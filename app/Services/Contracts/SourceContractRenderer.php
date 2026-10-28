<?php

namespace App\Services\Contracts;

class SourceContractRenderer
{
    public function html(array $snapshot, array $evidence = [], bool $draft = true, ?string $signature = null, bool $fill = true): ?string
    {
        $layout = data_get($snapshot,'version.contract_layout');
        $text = (string)data_get($snapshot,'version.contract_template');
        if (!is_array($layout) || !hash_equals($layout['template_sha256']??'',hash('sha256',$text))) return null;
        $escape = fn($value) => htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $template = app(ContractTemplate::class);
        $context=['accepted_at'=>$draft?null:($evidence['accepted_at']??null),'contract_number'=>$evidence['contract_number']??''];
        $left = (float)($layout['margin_left']??56.7); $right = (float)($layout['margin_right']??56.7);
        $html='<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><style>@page{margin:58pt '.$right.'pt 50pt '.$left.'pt}body{font-family:Contract Source,DejaVu Sans,sans-serif;font-size:12pt;color:#000;line-height:1.1}p{margin:0;white-space:normal;word-wrap:break-word}.source-header{position:fixed;top:-48pt;left:0}.source-footer{position:fixed;bottom:-35pt;left:0;width:100%;text-align:center}.evidence{page-break-before:always;font-size:10pt}.evidence p{margin:12pt 0}</style></head><body>';
        foreach(['header','footer'] as $kind) {
            $html.='<div class="source-'.$kind.'">';
            foreach($layout[$kind]??[] as $image) {
                if (!preg_match('~^data:image/(jpeg|png);base64,[A-Za-z0-9+/=]+$~D',$image['data']??'')) continue;
                $html.='<img src="'.$image['data'].'" style="'.($kind==='header'?'width:84pt;max-height:46pt':'max-width:100%;max-height:30pt').'">';
            }
            $html.='</div>';
        }
        $signatureBlock=false;
        foreach($layout['paragraphs']??[] as $paragraph) {
            if (!$signatureBlock && str_contains($paragraph['text']??'','{{cidade_assinatura}}')) {
                $html.='<div style="page-break-inside:avoid">';
                $signatureBlock=true;
            }
            $align=in_array($paragraph['align']??'',['left','right','center','justify'])?$paragraph['align']:'left';
            $style='text-align:'.$align.';margin-top:'.max(0,(float)($paragraph['before']??0)).'pt;margin-bottom:'.max(0,(float)($paragraph['after']??0)).'pt;';
            if (($paragraph['keep']??false) || preg_match('/^(CLÁUSULA |DO |DA |DOS |DAS )/u',trim($paragraph['text']??''))) $style.='page-break-after:avoid;';
            if ($paragraph['page_break']??false) $style.='page-break-before:always;';
            $html.='<p style="'.$style.'">';
            foreach($paragraph['runs']??[] as $run) {
                $value=$fill?$template->render((string)($run['text']??''),$snapshot,$context):(string)($run['text']??'');
                $html.='<span style="font-size:'.min(36,max(6,(float)($run['size']??12))).'pt;'.(($run['bold']??false)?'font-weight:bold;':'').(($run['italic']??false)?'font-style:italic;':'').'">'.nl2br($escape(str_replace("\t",'    ',$value)),false).'</span>';
            }
            if (trim($paragraph['text']??'')==='') $html.='&#160;';
            $html.='</p>';
        }
        if ($signatureBlock) $html.='</div>';
        if (!$draft) {
            $html.='<section class="evidence"><h2>Registro de assinatura</h2><p>'.$escape(data_get($snapshot,'customer.name')).'<br>CPF: '.$escape(data_get($snapshot,'customer.document')).'</p><p>Data do aceite: '.$escape($template->values($snapshot,$context)['data_assinatura_extenso']).'</p>';
            if ($signature) $html.='<img style="max-width:240pt;max-height:90pt" src="'.$escape($signature).'">';
            $html.='<p>Método: '.$escape($evidence['signature_method']??'digital').'</p><p>Integridade das condições: '.$escape($evidence['snapshot_sha256']??'').'</p></section>';
        }
        return $html.'</body></html>';
    }
}
