<?php
declare(strict_types=1);
namespace LuzPropria\LuzPanel\Portal;

class PortalApi
{
    private array $settings;

    public function __construct()
    {
        $private=dirname(__DIR__,3).'/configuracao_privada/portal.php';
        $config=is_file($private)?require $private:[];
        $this->settings=['url'=>rtrim((string)(getenv('PORTAL_API_URL') ?: ($config['url']??'')),'/'),
            'key'=>(string)(getenv('PORTAL_API_KEY') ?: ($config['key']??'')),
            'payment_hosts'=>array_filter(array_map('trim',explode(',',(string)(getenv('PORTAL_PAYMENT_HOSTS') ?: ($config['payment_hosts']??'app.vindi.com.br,sandbox-app.vindi.com.br')))))];
    }

    public function managementUrl(): string { return preg_replace('#/api/site/v1$#','',$this->settings['url']); }

    public function paymentAllowed(string $url): bool
    {
        $parts=parse_url($url);
        return filter_var($url,FILTER_VALIDATE_URL)!==false && ($parts['scheme']??'')==='https' && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port'])
            && in_array(strtolower($parts['host']??''),$this->settings['payment_hosts'],true);
    }

    public function request(string $method,string $path,array $data=[],?string $token=null,array $context=[],bool $pdf=false): PortalResult
    {
        $base=$this->settings['url']; $parts=parse_url($base);
        $local=in_array(getenv('APP_ENV'),['dev','test','local'],true) && in_array($parts['host']??'',['localhost','127.0.0.1'],true);
        if (strlen($this->settings['key'])<32 || !filter_var($base,FILTER_VALIDATE_URL) || isset($parts['user']) || isset($parts['pass'])
            || (($parts['scheme']??'')!=='https' && !$local) || !preg_match('~^[A-Za-z0-9_/-]+$~D',$path) || !in_array($method,['GET','POST'],true)) {
            return new PortalResult(503,['message'=>'A área do cliente está temporariamente indisponível. Tente novamente em instantes.']);
        }
        $url=$base.'/'.ltrim($path,'/');
        if ($method==='GET' && $data) $url.='?'.http_build_query($data,'','&',PHP_QUERY_RFC3986);
        $headers=['Accept: '.($pdf?'application/pdf, image/jpeg':'application/json'),'X-Site-Key: '.$this->settings['key']];
        if ($token && preg_match('/^[a-f0-9]{64}$/D',$token)) $headers[]='Authorization: Bearer '.$token;
        if (filter_var($context['ip']??'',FILTER_VALIDATE_IP)) $headers[]='X-Portal-IP: '.$context['ip'];
        $agent=preg_replace('/[\r\n\x00-\x1f]/',' ',(string)($context['agent']??''));
        $headers[]='X-Portal-Agent: '.mb_substr($agent,0,500);
        if (preg_match('/^\d{1,5}$/D',(string)($context['port']??''))) $headers[]='X-Portal-Port: '.$context['port'];
        $language=preg_replace('/[^A-Za-z0-9,;=.\- ]/','',(string)($context['language']??''));
        if ($language!=='') $headers[]='X-Portal-Language: '.mb_substr($language,0,120);
        $handle=curl_init($url); $body=''; $limit=$pdf?16*1024*1024:2*1024*1024;
        curl_setopt_array($handle,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>5,
            CURLOPT_TIMEOUT=>$pdf?125:($path==='catalog'?6:25),CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk) use(&$body,$limit): int {
                if (strlen($body)+strlen($chunk)>$limit) return 0;
                $body.=$chunk; return strlen($chunk);
            }]);
        // shortcut: libcurl < 7.85 (hospedagem atual) não tem CURLOPT_PROTOCOLS_STR; remover o ramo antigo quando todos os ambientes tiverem libcurl novo.
        if (defined('CURLOPT_PROTOCOLS_STR')) curl_setopt($handle,CURLOPT_PROTOCOLS_STR,$local?'https,http':'https');
        else curl_setopt($handle,CURLOPT_PROTOCOLS,$local?CURLPROTO_HTTPS|CURLPROTO_HTTP:CURLPROTO_HTTPS);
        if ($method==='POST') {
            $headers[]='Content-Type: application/json';
            curl_setopt($handle,CURLOPT_HTTPHEADER,$headers);
            curl_setopt($handle,CURLOPT_POSTFIELDS,json_encode($data,JSON_THROW_ON_ERROR));
        }
        $success=curl_exec($handle); $status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);
        unset($handle);
        if ($success===false || $status<200 || $status>=500 || ($status>=300 && $status<400)) return new PortalResult(503,['message'=>'Não foi possível concluir a conexão. Seus dados já salvos permanecem preservados. Tente novamente em instantes.']);
        if ($pdf && $status===200 && (str_starts_with($body,'%PDF-') || str_starts_with($body,"\xFF\xD8\xFF"))) return new PortalResult(200,[],$body);
        $json=json_decode($body,true);
        if (!is_array($json)) return new PortalResult(503,['message'=>'Não foi possível concluir a conexão. Tente novamente em instantes.']);
        return new PortalResult($status,$json);
    }
}
