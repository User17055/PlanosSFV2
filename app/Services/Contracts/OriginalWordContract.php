<?php

namespace App\Services\Contracts;

use DOMDocument;
use DOMXPath;
use ZipArchive;
use Symfony\Component\Process\Process;
use Illuminate\Support\Str;

/** Edits text nodes inside the original OOXML package; never rebuilds its layout. */
class OriginalWordContract
{
    public function replaceRange(DOMXPath $xpath, \DOMNode $paragraph, int $start, int $length, string $value): void
    {
        $offset=0; $inserted=false;
        foreach($xpath->query('.//w:t',$paragraph) as $node) {
            $text=$node->textContent; $size=mb_strlen($text); $end=$offset+$size;
            if ($end>$start && $offset<$start+$length || ($length===0 && !$inserted && $end>=$start)) {
                $from=max(0,$start-$offset); $to=min($size,$start+$length-$offset);
                $node->textContent=mb_substr($text,0,$from).(!$inserted?$value:'').mb_substr($text,max($from,$to));
                $node->setAttributeNS('http://www.w3.org/XML/1998/namespace','xml:space','preserve');
                $inserted=true;
            }
            $offset=$end;
        }
    }

    public function prepare(string $original, string $destination): array
    {
        if (!copy($original,$destination)) throw new \RuntimeException('Não foi possível copiar o Word original.');
        $zip=new ZipArchive; $zip->open($destination);
        $xml=new DOMDocument; $xml->preserveWhiteSpace=true; $xml->loadXML($zip->getFromName('word/document.xml'),LIBXML_NONET);
        $xp=new DOMXPath($xml); $xp->registerNamespace('w','http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $pet=false; $replaced=[];
        foreach($xp->query('//w:body//w:p') as $p) {
            $text=''; foreach($xp->query('.//w:t',$p) as $n) $text.=$n->textContent;
            if (preg_match('/CAR.*DO PET/iu',$text)) $pet=true;
            if (preg_match('/^\s*CONTRATADO:/u',$text)) $pet=false;
            $changes=[];
            if (trim($text)==='CONTRATANTE') {
                $changes[]=[0,mb_strlen($text),'CONTRATANTE: {{contratante_nome}} – CPF: {{contratante_cpf}}'];
                $previous=$p->previousSibling;
                while ($previous && $previous->nodeName!=='w:p') $previous=$previous->previousSibling;
                if ($previous && str_contains($previous->textContent,'_____')) {
                    $start=$xml->createElementNS($p->lookupNamespaceURI('w'),'w:bookmarkStart');
                    $start->setAttributeNS($p->lookupNamespaceURI('w'),'w:id','900001');
                    $start->setAttributeNS($p->lookupNamespaceURI('w'),'w:name','assinatura_contratante');
                    $end=$xml->createElementNS($p->lookupNamespaceURI('w'),'w:bookmarkEnd');
                    $end->setAttributeNS($p->lookupNamespaceURI('w'),'w:id','900001');
                    $previous->appendChild($start); $previous->appendChild($end);
                }
            }
            elseif (preg_match('/^\s*Presidente Prudente[^,]*,(.*)$/u',$text,$m,PREG_OFFSET_CAPTURE)) {
                $changes[]=[mb_strlen(substr($text,0,$m[1][1])),mb_strlen($m[1][0]),' {{data_assinatura_extenso}}'];
            } else {
                $fields=$pet?['Nome'=>'pet_nome','Raça'=>'pet_raca','Espécie'=>'pet_especie','Cor'=>'pet_cor','Sexo'=>'pet_sexo','Idade'=>'pet_idade']
                    :['Nome'=>'contratante_nome','Endereço'=>'contratante_endereco','Cidade'=>'contratante_cidade','Estado'=>'contratante_uf','CEP'=>'contratante_cep','RG'=>'contratante_rg','CPF'=>'contratante_cpf','Telefone Celular'=>'contratante_telefone','E-mail'=>'contratante_email'];
                $labels='Nome|Endereço|Cidade|Estado|CEP|RG|CPF|Telefone Celular|E-mail|Raça|Espécie|Cor|Sexo|Idade';
                if (!str_contains($text,'CONTRATADO:')) foreach($fields as $label=>$key) {
                    if (preg_match('/(?:^|(?<=\s)|(?<=[\d\pL\-\.]))'.preg_quote($label,'/').'\s*:\s*(.*?)(?=(?:'.$labels.')\s*:|$)/u',$text,$m,PREG_OFFSET_CAPTURE)) {
                        $value=rtrim($m[1][0]);
                        $separator=($m[1][0]===$value && $m[1][1]+strlen($m[1][0])<strlen($text))?'  ':'';
                        $changes[]=[mb_strlen(substr($text,0,$m[1][1])),mb_strlen($value),'{{'.$key.'}}'.$separator];
                    }
                }
            }
            usort($changes,fn($a,$b)=>$b[0]<=>$a[0]);
            foreach($changes as [$start,$length,$value]) { $this->replaceRange($xp,$p,$start,$length,$value); $replaced[]=$value; }
        }
        // Personal mailto relationships also belong to the replaced customer data.
        $removed=[];
        foreach(iterator_to_array($xp->query('//w:hyperlink[contains(., "{{contratante_email}}") ]')) as $link) {
            $removed[]=$link->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships','id');
            while($link->firstChild) $link->parentNode->insertBefore($link->firstChild,$link);
            $link->parentNode->removeChild($link);
        }
        if ($removed && ($relationships=$zip->getFromName('word/_rels/document.xml.rels'))!==false) {
            $rels=new DOMDocument; $rels->loadXML($relationships,LIBXML_NONET);
            foreach(iterator_to_array($rels->documentElement->childNodes) as $rel) if ($rel instanceof \DOMElement && in_array($rel->getAttribute('Id'),$removed,true)) $rel->parentNode->removeChild($rel);
            $zip->addFromString('word/_rels/document.xml.rels',$rels->saveXML());
        }
        $zip->addFromString('word/document.xml',$xml->saveXML()); $zip->close();
        return $replaced;
    }

    public function entry(array $snapshot): ?array
    {
        if (!data_get($snapshot,'version.contract_layout.source')) return null;
        $indexPath=resource_path('contracts/word/index.json');
        $index=is_file($indexPath)?json_decode(file_get_contents($indexPath),true):[];
        $key=hash('sha256',json_encode(data_get($snapshot,'version.contract_layout'),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $entry=$index[$key]??null;
        if (!$entry || !hash_equals($entry['text_sha256'],hash('sha256',(string)data_get($snapshot,'version.contract_template')))) throw new \RuntimeException('O Word original deste contrato ainda não está preparado.');
        return $entry;
    }

    public function pdf(array $snapshot, array $context=[], bool $fill=true): ?string
    {
        $entry=$this->entry($snapshot); if (!$entry) return null;
        $source=resource_path('contracts/word/'.$entry['docx_sha256'].'.docx');
        if (!is_file($source) || !hash_equals($entry['docx_sha256'],hash_file('sha256',$source))) throw new \RuntimeException('Integridade do Word original inválida.');
        $cache=storage_path('app/private/word-models/'.$entry['docx_sha256'].'.pdf');
        if (!$fill && is_file($cache) && str_starts_with(file_get_contents($cache), '%PDF-')) return file_get_contents($cache);
        $directory=storage_path('app/private/word-conversion/'.Str::uuid());
        if (!is_dir($directory)) mkdir($directory,0700,true);
        $input=$directory.'/contrato.docx'; $output=$directory.'/contrato.pdf';
        copy($source,$input);
        try {
            if ($fill) {
                $zip=new ZipArchive; $zip->open($input);
                $xml=new DOMDocument; $xml->loadXML($zip->getFromName('word/document.xml'),LIBXML_NONET);
                $xp=new DOMXPath($xml); $xp->registerNamespace('w','http://schemas.openxmlformats.org/wordprocessingml/2006/main');
                foreach($xp->query('//w:t') as $n) $n->textContent=app(ContractTemplate::class)->render($n->textContent,$snapshot,$context);
                if (!empty($context['accepted_at'])) {
                    $line=$xp->query('//w:bookmarkStart[@w:name="assinatura_contratante"]/parent::w:p')->item(0);
                    if (!$line) throw new \RuntimeException('Campo de assinatura ausente no Word preparado.');
                    foreach($xp->query('.//w:t',$line) as $n) if (preg_match('/^_+$/',trim($n->textContent))) {
                        $n->textContent='Assinado digitalmente em '.\Carbon\CarbonImmutable::parse($context['accepted_at'])->timezone('America/Sao_Paulo')->format('d/m/Y H:i:s').' (Brasília)';
                        $run=$n->parentNode;
                        $properties=$xp->query('w:rPr',$run)->item(0);
                        if (!$properties) { $properties=$xml->createElementNS($run->namespaceURI,'w:rPr'); $run->insertBefore($properties,$run->firstChild); }
                        $size=$xp->query('w:sz',$properties)->item(0);
                        if (!$size) { $size=$xml->createElementNS($run->namespaceURI,'w:sz'); $properties->appendChild($size); }
                        $size->setAttributeNS($run->namespaceURI,'w:val','16');
                    }
                }
                $zip->addFromString('word/document.xml',$xml->saveXML()); $zip->close();
            }
            if (PHP_OS_FAMILY!=='Windows') throw new \RuntimeException('Configure a conversão nativa de Word para PDF neste servidor. O contrato não será reconstruído em HTML.');
            $lock=fopen(storage_path('app/private/word-conversion.lock'),'c');
            if (!flock($lock,LOCK_EX|LOCK_NB)) { fclose($lock); throw new \RuntimeException('Conversor do Word ocupado. Tente novamente em instantes.'); }
            try {
                if((int)ini_get('max_execution_time')>0 && (int)ini_get('max_execution_time')<120) set_time_limit(120);
                $arguments=['powershell.exe','-NoProfile','-NonInteractive','-File',base_path('scripts/convert-word-pdf.ps1'),'-InputPath',$input,'-OutputPath',$output];
                if ($fill && !empty($context['accepted_at'])) {
                    $arguments[]='-SigningText';
                    $arguments[]='Assinado digitalmente em '.\Carbon\CarbonImmutable::parse($context['accepted_at'])->timezone('America/Sao_Paulo')->format('d/m/Y H:i:s').' (Brasília)';
                    if (!empty($context['signature_image'])) {
                        $signature=$directory.'/assinatura.png';
                        file_put_contents($signature,base64_decode(substr($context['signature_image'],22),true));
                        $arguments[]='-SignaturePath'; $arguments[]=$signature;
                    }
                    if (!empty($context['evidence_lines'])) {
                        $evidence=$directory.'/registro.txt';
                        file_put_contents($evidence,implode("\n",$context['evidence_lines']));
                        $arguments[]='-EvidencePath'; $arguments[]=$evidence;
                        if (!empty($context['selfie_jpeg'])) {
                            file_put_contents($selfie=$directory.'/foto.jpg',$context['selfie_jpeg']);
                            $arguments[]='-SelfiePath'; $arguments[]=$selfie;
                        }
                    }
                }
                // Under the built-in web server Symfony only forwards variables also present in $_SERVER, dropping
                // SystemRoot, TEMP and USERPROFILE; PowerShell and Word cannot start without them.
                $process=new Process($arguments,null,getenv());
                $process->setTimeout(90); $process->mustRun();
            } finally { flock($lock,LOCK_UN); fclose($lock); }
            $bytes=is_file($output)?file_get_contents($output):'';
            if (!str_starts_with($bytes,'%PDF-')) throw new \RuntimeException('O Word não produziu um PDF válido.');
            if (!$fill) {
                if (!is_dir(dirname($cache))) mkdir(dirname($cache),0700,true);
                file_put_contents($cache,$bytes,LOCK_EX);
            }
            return $bytes;
        } finally {
            foreach([$input,$output,$directory.'/assinatura.png',$directory.'/registro.txt',$directory.'/foto.jpg'] as $file) if(is_file($file)) unlink($file);
            if(is_dir($directory)) rmdir($directory);
        }
    }
}

