<?php

namespace Tests\Feature;

use App\Services\Contracts\{OriginalWordContract, ContractTemplate};
use Tests\TestCase;
use ZipArchive;

class OriginalWordContractTest extends TestCase
{
    public function test_preparation_changes_only_customer_text_and_its_email_link_preserving_styles_and_assets(): void
    {
        $source=tempnam(sys_get_temp_dir(),'word-source-');
        $target=tempnam(sys_get_temp_dir(),'word-target-');
        $xml='<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:body><w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:rPr><w:b/></w:rPr><w:t>Nome: Pessoa </w:t></w:r><w:r><w:t>Anterior</w:t></w:r></w:p><w:p><w:hyperlink r:id="rIdPersonal"><w:r><w:t>E-mail: anterior@example.test</w:t></w:r></w:hyperlink></w:p><w:p><w:r><w:t>CARACTERÍSTICA DO PET</w:t></w:r></w:p><w:p><w:r><w:t>Idade: 2020-01-02</w:t></w:r></w:p><w:p><w:r><w:t>__________</w:t></w:r></w:p><w:p><w:r><w:t>CONTRATANTE</w:t></w:r></w:p><w:sectPr><w:pgSz w:w="12240" w:h="15840"/></w:sectPr></w:body></w:document>';
        try {
            $zip=new ZipArchive; $zip->open($source,ZipArchive::OVERWRITE);
            $zip->addFromString('word/document.xml',$xml);
            $zip->addFromString('word/styles.xml','original styles');
            $zip->addFromString('word/media/image1.png','original logo');
            $zip->addFromString('word/_rels/document.xml.rels','<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdPersonal" Target="mailto:anterior@example.test"/><Relationship Id="rIdLogo" Target="media/image1.png"/></Relationships>');
            $zip->close();
            app(OriginalWordContract::class)->prepare($source,$target);
            $zip->open($target);
            $result=$zip->getFromName('word/document.xml');
            $this->assertStringContainsString('{{contratante_nome}}',$result);
            $this->assertStringContainsString('{{pet_idade}}',$result);
            $this->assertStringContainsString('assinatura_contratante',$result);
            $this->assertStringContainsString('<w:b/>',$result);
            $this->assertStringContainsString('w:w="12240"',$result);
            $this->assertStringNotContainsString('Pessoa',$result);
            $this->assertStringNotContainsString('Anterior',$result);
            $this->assertStringNotContainsString('anterior@example.test',$result);
            $this->assertStringNotContainsString('mailto:',$zip->getFromName('word/_rels/document.xml.rels'));
            $this->assertStringContainsString('rIdLogo',$zip->getFromName('word/_rels/document.xml.rels'));
            $this->assertSame('original styles',$zip->getFromName('word/styles.xml'));
            $this->assertSame('original logo',$zip->getFromName('word/media/image1.png'));
            $zip->close();
        } finally { unlink($source); unlink($target); }
    }

    public function test_all_prepared_packages_have_fields_instead_of_customer_values(): void
    {
        $index=json_decode(file_get_contents(resource_path('contracts/word/index.json')),true);
        $this->assertCount(5,$index);
        foreach($index as $entry) {
            $path=resource_path('contracts/word/'.$entry['docx_sha256'].'.docx');
            $this->assertSame($entry['docx_sha256'],hash_file('sha256',$path));
            $zip=new ZipArchive; $zip->open($path);
            $xml=$zip->getFromName('word/document.xml');
            foreach(['contratante_nome','contratante_cpf','contratante_rg','contratante_endereco','contratante_cidade','contratante_uf','contratante_cep','contratante_telefone','contratante_email','pet_nome','data_assinatura_extenso'] as $field) $this->assertStringContainsString('{{'.$field.'}}',$xml);
            // O Felino traz "NASC." (data de nascimento); os outros, "Idade".
            $this->assertMatchesRegularExpression('/\{\{pet_(idade|nascimento)\}\}/',$xml);
            $this->assertStringContainsString('assinatura_contratante',$xml);
            $this->assertStringNotContainsString('mailto:',$xml);
            $this->assertStringNotContainsString('mailto:', $zip->getFromName('word/_rels/document.xml.rels'));
            $zip->close();
        }
    }

    public function test_age_uses_the_signing_date_and_supports_estimated_age(): void
    {
        $template=app(ContractTemplate::class);
        $this->assertSame('6 anos e 9 meses',$template->render('{{pet_idade}}',['pet'=>['birth_date'=>'2020-01-02']],['accepted_at'=>'2026-10-22T15:00:00-03:00']));
        $this->assertSame('1 mês',$template->render('{{pet_idade}}',['pet'=>['estimated_age_months'=>1]]));
        $this->assertSame('Não informado',$template->render('{{pet_idade}}',[]));
    }
}
