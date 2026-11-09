<?php

namespace App\Services\Contracts;

use App\Services\Domain\Fingerprint;
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
            // Decisão de 28/10/2026: os planos não oferecem desconto em ração nem em produtos Franpet.
            if (preg_match('/desconto na compra de ração|desconto em produtos Franpet/iu',$text)) { $p->parentNode->removeChild($p); continue; }
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
                $fields=$pet?['Nome'=>'pet_nome','Raça'=>'pet_raca','Espécie'=>'pet_especie','Cor'=>'pet_cor','Sexo'=>'pet_sexo','Idade'=>'pet_idade','NASC.'=>'pet_nascimento']
                    :['Nome'=>'contratante_nome','Endereço'=>'contratante_endereco','Cidade'=>'contratante_cidade','CIDADE'=>'contratante_cidade','Estado'=>'contratante_uf','CEP'=>'contratante_cep','RG'=>'contratante_rg','CPF'=>'contratante_cpf','Telefone Celular'=>'contratante_telefone','E-mail'=>'contratante_email'];
                $labels='Nome|Endereço|Cidade|CIDADE|Estado|CEP|RG|CPF|Telefone Celular|E-mail|Raça|Espécie|Cor|Sexo|Idade|NASC\.';
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
        $this->removeMailtoFields($xp);
        $this->matchFieldSizes($xp);
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

    /**
     * Os Word de origem são contratos preenchidos: o dado do cliente antigo pode ter outro tamanho de letra
     * (o CPF do Felino estava em 10,5 e o rótulo em 12). O campo herda o tamanho do texto que vem antes dele.
     */
    public function matchFieldSizes(DOMXPath $xp): int
    {
        $count=0;
        foreach($xp->query('//w:body//w:p') as $paragraph) {
            $previous=null;
            foreach($xp->query('.//w:r[w:t]',$paragraph) as $run) {
                $text=''; foreach($xp->query('w:t',$run) as $t) $text.=$t->textContent;
                if (trim($text)==='') continue;
                if (str_contains($text,'{{') && $previous) {
                    foreach(['sz','szCs'] as $tag) {
                        $want=$xp->query('w:rPr/w:'.$tag,$previous)->item(0)?->getAttributeNS(self::W_NS,'val');
                        $have=$xp->query('w:rPr/w:'.$tag,$run)->item(0);
                        if ($want && $have && $have->getAttributeNS(self::W_NS,'val')!==$want) { $have->setAttributeNS(self::W_NS,'w:val',$want); $count++; }
                    }
                }
                $previous=$run;
            }
        }
        return $count;
    }

    /** O e-mail do cliente antigo também fica em campos HYPERLINK "mailto:..."; tira o campo e deixa o texto exibido. */
    public function removeMailtoFields(DOMXPath $xp): int
    {
        $count=0;
        foreach(iterator_to_array($xp->query('//w:r[w:instrText[contains(., "mailto:")]]')) as $run) {
            $begin=$run; while($begin && !$xp->query('w:fldChar[@w:fldCharType="begin"]',$begin)->length) $begin=$xp->query('preceding-sibling::w:r[1]',$begin)->item(0);
            $separate=$run; while($separate && !$xp->query('w:fldChar[@w:fldCharType="separate"]',$separate)->length) $separate=$xp->query('following-sibling::w:r[1]',$separate)->item(0);
            $end=$separate?$xp->query('following-sibling::w:r[w:fldChar[@w:fldCharType="end"]][1]',$separate)->item(0):null;
            if (!$begin || !$separate || !$end) throw new \RuntimeException('Campo de e-mail sem início, separador ou fim no Word.');
            for($node=$begin; $node!==$separate; $node=$next) { $next=$node->nextSibling; $node->parentNode->removeChild($node); }
            $separate->parentNode->removeChild($separate); $end->parentNode->removeChild($end); $count++;
        }
        return $count;
    }

    public function entry(array $snapshot): ?array
    {
        if (!data_get($snapshot,'version.contract_layout.source')) return null;
        $indexPath=resource_path('contracts/word/index.json');
        $index=is_file($indexPath)?json_decode(file_get_contents($indexPath),true):[];
        $key=Fingerprint::sha256(data_get($snapshot,'version.contract_layout'));
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
                    // No Windows o Word insere a imagem e a página de evidência; no Linux elas entram no DOCX antes do LibreOffice converter.
                    if (PHP_OS_FAMILY!=='Windows') $this->embedSigning($zip,$xml,$xp,$line,$context);
                }
                $zip->addFromString('word/document.xml',$xml->saveXML()); $zip->close();
            }
            if (PHP_OS_FAMILY!=='Windows') {
                $this->convertWithLibreOffice($input,$directory);
                $bytes=is_file($output)?file_get_contents($output):'';
                if (!str_starts_with($bytes,'%PDF-')) throw new \RuntimeException('O LibreOffice não produziu um PDF válido.');
                if (!$fill) {
                    if (!is_dir(dirname($cache))) mkdir(dirname($cache),0700,true);
                    file_put_contents($cache,$bytes,LOCK_EX);
                }
                return $bytes;
            }
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

    private const W_NS='http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /** Converte o DOCX já preenchido em PDF com o LibreOffice (um por vez, com perfil próprio e prazo de 90 s). */
    private function convertWithLibreOffice(string $input,string $directory): void
    {
        $home=storage_path('app/private/lo-home');
        if (!is_dir($home)) mkdir($home,0700,true);
        $lock=fopen(storage_path('app/private/word-conversion.lock'),'c');
        if (!flock($lock,LOCK_EX|LOCK_NB)) { fclose($lock); throw new \RuntimeException('Conversor do Word ocupado. Tente novamente em instantes.'); }
        try {
            if((int)ini_get('max_execution_time')>0 && (int)ini_get('max_execution_time')<120) set_time_limit(120);
            $process=new Process([config('plans.libreoffice_bin','soffice'),'--headless','--norestore','--nolockcheck','-env:UserInstallation=file://'.$home.'/profile',
                '--convert-to','pdf:writer_pdf_Export','--outdir',$directory,$input],null,['HOME'=>$home]);
            $process->setTimeout(90); $process->mustRun();
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }

    /** Assinatura desenhada no campo do contratante e, ao final, a página com o registro da assinatura eletrônica. */
    private function embedSigning(ZipArchive $zip,DOMDocument $xml,DOMXPath $xp,\DOMNode $line,array $context): void
    {
        $this->ensureContentTypes($zip);
        $rels=(string)$zip->getFromName('word/_rels/document.xml.rels'); $n=1;
        $addImage=function(string $bytes,string $ext) use($zip,&$rels,&$n): ?array {
            $size=@getimagesizefromstring($bytes); if (!$size) return null;
            $id='rIdSf'.$n; $name='sf'.($n++).'.'.$ext;
            $zip->addFromString('word/media/'.$name,$bytes);
            $rels=str_replace('</Relationships>','<Relationship Id="'.$id.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/'.$name.'"/></Relationships>',$rels);
            return ['id'=>$id,'w'=>$size[0],'h'=>$size[1]];
        };
        if (!empty($context['signature_image'])) {
            $png=base64_decode(substr($context['signature_image'],22),true);
            if ($png!==false && ($img=$addImage($png,'png'))) {
                $width=220; $height=(int)round($width*$img['h']/$img['w']); if ($height>70) { $height=70; $width=(int)round($height*$img['w']/$img['h']); }
                $first=$xp->query('w:r',$line)->item(0);
                $line->insertBefore($this->pictureRun($xml,$img['id'],$width,$height,901),$first);
                $line->insertBefore($this->fragment($xml,'<w:r><w:br/></w:r>'),$first);
            }
        }
        if (!empty($context['evidence_lines'])) {
            $body=$xp->query('//w:body')->item(0); $end=$xp->query('w:sectPr',$body)->item(0);
            $add=fn(string $text,int $size,bool $bold,int $after)=>$body->insertBefore($this->fragment($xml,'<w:p><w:pPr><w:spacing w:before="0" w:after="'.$after.'"/></w:pPr><w:r><w:rPr>'.($bold?'<w:b/>':'').'<w:sz w:val="'.$size.'"/></w:rPr><w:t xml:space="preserve">'.htmlspecialchars($text,ENT_XML1|ENT_QUOTES,'UTF-8').'</w:t></w:r></w:p>'),$end);
            $body->insertBefore($this->fragment($xml,'<w:p><w:r><w:br w:type="page"/></w:r></w:p>'),$end);
            $lines=array_values($context['evidence_lines']);
            for ($i=0;$i<3;$i++) $add('',18,false,0);
            foreach ($lines as $i=>$text) $i===0 ? $add($text,22,true,200) : $add($text,18,false,60);
            if (!empty($context['selfie_jpeg']) && ($img=$addImage($context['selfie_jpeg'],'jpg'))) {
                $paragraph=$body->insertBefore($this->fragment($xml,'<w:p/>'),$end);
                $paragraph->appendChild($this->pictureRun($xml,$img['id'],120,(int)round(120*$img['h']/$img['w']),902));
            }
        }
        $zip->addFromString('word/_rels/document.xml.rels',$rels);
    }

    private function fragment(DOMDocument $xml,string $inner): \DOMNode
    {
        $fragment=$xml->createDocumentFragment();
        if (!$fragment->appendXML(preg_replace('/^<(w:\w+)/','<$1 xmlns:w="'.self::W_NS.'"',$inner,1))) throw new \RuntimeException('Não foi possível montar a assinatura no Word.');
        return $fragment;
    }

    private function pictureRun(DOMDocument $xml,string $rid,int $widthPx,int $heightPx,int $id): \DOMNode
    {
        $cx=$widthPx*9525; $cy=$heightPx*9525;
        return $this->fragment($xml,'<w:r xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="'.$cx.'" cy="'.$cy.'"/><wp:docPr id="'.$id.'" name="Imagem '.$id.'"/><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic><pic:nvPicPr><pic:cNvPr id="'.$id.'" name="img'.$id.'"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="'.$rid.'"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$cx.'" cy="'.$cy.'"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>');
    }

    private function ensureContentTypes(ZipArchive $zip): void
    {
        $types=(string)$zip->getFromName('[Content_Types].xml'); $changed=false;
        foreach (['png'=>'image/png','jpg'=>'image/jpeg'] as $ext=>$mime) {
            if (stripos($types,'Extension="'.$ext.'"')===false) { $types=preg_replace('/(<Types[^>]*>)/','$1<Default Extension="'.$ext.'" ContentType="'.$mime.'"/>',$types,1); $changed=true; }
        }
        if ($changed) $zip->addFromString('[Content_Types].xml',$types);
    }
}
