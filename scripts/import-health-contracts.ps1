param([string]$SourceDirectory='C:\Users\André Junior\OneDrive\SERVIDOR\PLANOS DE SAUDE (NAO USAR)\CONTRATOS 2026')
$ErrorActionPreference='Stop'
Add-Type -AssemblyName System.IO.Compression.FileSystem
$root=Split-Path -Parent $PSScriptRoot
$enc=[System.Text.UTF8Encoding]::new($false)
function XmlEntry($zip,$name){$entry=$zip.GetEntry($name);if(!$entry){return $null};$reader=[System.IO.StreamReader]::new($entry.Open());try{return [xml]$reader.ReadToEnd()}finally{$reader.Dispose()}}
function Attr($node,$name,$fallback=''){if(!$node){return $fallback};$value=$node.GetAttribute($name,'http://schemas.openxmlformats.org/wordprocessingml/2006/main');if($value -eq ''){return $fallback};return $value}
foreach($item in @(@('PLANO ECONOMICO.docx','plano-economico'),@('PLANO ESSENCIAL.docx','plano-essencial'),@('PLANO PRIME.docx','plano-prime'),@('PLANO SUPER ECONOMICO.docx','plano-super-economico'))){
$zip=[System.IO.Compression.ZipFile]::OpenRead((Join-Path $SourceDirectory $item[0]))
try{
$xml=XmlEntry $zip 'word/document.xml'
$ns=[System.Xml.XmlNamespaceManager]::new($xml.NameTable)
$ns.AddNamespace('w','http://schemas.openxmlformats.org/wordprocessingml/2006/main')
$ns.AddNamespace('a','http://schemas.openxmlformats.org/drawingml/2006/main')
$ns.AddNamespace('r','http://schemas.openxmlformats.org/officeDocument/2006/relationships')
$ns.AddNamespace('wp','http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing')
$paragraphs=[System.Collections.Generic.List[object]]::new();$texts=[System.Collections.Generic.List[string]]::new();$pet=$false
foreach($p in $xml.SelectNodes('//w:body/w:p',$ns)){
$text=($p.SelectNodes('.//w:t',$ns)|ForEach-Object{$_.'#text'})-join ''
$replacement=$null
if($text -match 'CAR.*DO PET'){ $pet=$true }
if($text -match '^CONTRATADO:'){ $pet=$false }
if($text -match '^Nome\s*:'){if($pet){$replacement='Nome: {{pet_nome}}'}else{$replacement='Nome: {{contratante_nome}}'}}
elseif($text -match '^Endereço\s*:'){$replacement='Endereço: {{contratante_endereco}}'}
elseif($text -match '^Cidade\s*:'){$replacement='Cidade: {{contratante_cidade}}    Estado: {{contratante_uf}}    CEP: {{contratante_cep}}'}
elseif($text -match '^(RG|CPF)\s*:'){$replacement='RG: {{contratante_rg}}    CPF: {{contratante_cpf}}';if($text -match 'Telefone'){$replacement+="`nTelefone Celular: {{contratante_telefone}}"};if($text -match 'E-mail'){$replacement+="`nE-mail: {{contratante_email}}"}}
elseif($text -match '^Telefone Celular\s*:'){$replacement='Telefone Celular: {{contratante_telefone}}'}
elseif($text -match '^E-mail\s*:'){$replacement='E-mail: {{contratante_email}}'}
elseif($pet -and $text -match '^Raça\s*:'){$replacement='Raça: {{pet_raca}}'}
elseif($pet -and $text -match '^Espécie\s*:'){$replacement='Espécie: {{pet_especie}}'}
elseif($pet -and $text -match '^Cor\s*:'){$replacement='Cor: {{pet_cor}}'}
elseif($pet -and $text -match '^Sexo\s*:'){$replacement='Sexo: {{pet_sexo}}'}
elseif($pet -and $text -match '^Idade\s*:'){$replacement='Data de nascimento: {{pet_nascimento}}'}
elseif($text.Trim() -match '^Presidente Prudente\s*[–,\-]'){$replacement='{{cidade_assinatura}}, {{data_assinatura_extenso}}'}
elseif($text.Trim() -eq 'CONTRATANTE'){$replacement="{{contratante_nome}}`nCPF: {{contratante_cpf}}`nCONTRATANTE"}
$first=$p.SelectSingleNode('./w:r/w:rPr',$ns)
$runs=@($p.SelectNodes('./w:r',$ns)|ForEach-Object{
$rp=$_.SelectSingleNode('./w:rPr',$ns);if(!$rp){$rp=$xml.CreateElement('w','rPr','http://schemas.openxmlformats.org/wordprocessingml/2006/main')};$rt=($_.SelectNodes('./w:t|./w:br|./w:tab',$ns)|ForEach-Object{if($_.LocalName -eq 'br'){"`n"}elseif($_.LocalName -eq 'tab'){"`t"}else{$_.'#text'}})-join ''
@{text=$rt;bold=($null -ne $rp.SelectSingleNode('./w:b',$ns) -and (Attr ($rp.SelectSingleNode('./w:b',$ns)) 'val' '1') -ne '0');italic=($null -ne $rp.SelectSingleNode('./w:i',$ns));size=([double](Attr ($rp.SelectSingleNode('./w:sz',$ns)) 'val' '24'))/2}
})
if($null -ne $replacement){$text=$replacement;$runs=@(@{text=$text;bold=$false;italic=$false;size=12})}
$align=Attr ($p.SelectSingleNode('./w:pPr/w:jc',$ns)) 'val' 'left';if($align -eq 'both'){$align='justify'}
$paragraphs.Add(@{text=$text;runs=$runs;align=$align;before=([double](Attr ($p.SelectSingleNode('./w:pPr/w:spacing',$ns)) 'before' '0'))/20;after=([double](Attr ($p.SelectSingleNode('./w:pPr/w:spacing',$ns)) 'after' '0'))/20;keep=($null -ne $p.SelectSingleNode('./w:pPr/w:keepNext',$ns));page_break=($null -ne $p.SelectSingleNode('./w:pPr/w:pageBreakBefore',$ns))})
$texts.Add($text)
}
$section=$xml.SelectSingleNode('//w:sectPr',$ns)
$size=$section.SelectSingleNode('./w:pgSz',$ns);$margin=$section.SelectSingleNode('./w:pgMar',$ns)
$decor=@{}
foreach($kind in @('header','footer')){
$ref=$section.SelectSingleNode("./w:${kind}Reference[@w:type='default']",$ns)
$rels=XmlEntry $zip 'word/_rels/document.xml.rels';$rid=if($ref){$ref.GetAttribute('id','http://schemas.openxmlformats.org/officeDocument/2006/relationships')}else{''}
$target=($rels.Relationships.Relationship|Where-Object{$_.Id -eq $rid}).Target
if(!$target){continue};$part=XmlEntry $zip ('word/'+$target);$partRels=XmlEntry $zip ('word/_rels/'+$target+'.rels')
$images=@();foreach($image in $part.SelectNodes('//a:blip',$ns)){
$embed=$image.GetAttribute('embed','http://schemas.openxmlformats.org/officeDocument/2006/relationships');$imageTarget=($partRels.Relationships.Relationship|Where-Object{$_.Id -eq $embed}).Target
$entry=$zip.GetEntry('word/'+$imageTarget);if(!$entry){continue};$memory=[System.IO.MemoryStream]::new();$stream=$entry.Open();$stream.CopyTo($memory);$stream.Dispose()
$images+=@{data='data:image/jpeg;base64,'+[Convert]::ToBase64String($memory.ToArray());width=84};$memory.Dispose()
};$decor[$kind]=$images
}
$plain=($texts -join "`n").Trim()
$layout=@{source=$item[0];paragraphs=@($paragraphs.ToArray());width=([double](Attr $size 'w' '12240'))/20;height=([double](Attr $size 'h' '15840'))/20;margin_left=([double](Attr $margin 'left' '1134'))/20;margin_right=([double](Attr $margin 'right' '1134'))/20;header=$decor.header;footer=$decor.footer;template_sha256=([BitConverter]::ToString([System.Security.Cryptography.SHA256]::Create().ComputeHash($enc.GetBytes($plain)))).Replace('-','').ToLowerInvariant()}
[System.IO.File]::WriteAllText((Join-Path $root ('resources/contracts/'+$item[1]+'.txt')),$plain,$enc)
[System.IO.File]::WriteAllText((Join-Path $root ('resources/contracts/'+$item[1]+'.json')),($layout|ConvertTo-Json -Depth 12),$enc)
Write-Output ($item[1]+' importado')
}finally{$zip.Dispose()}
}
