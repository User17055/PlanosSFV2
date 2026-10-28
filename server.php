<?php

// Router exclusivo para desenvolvimento. Na VPS, Nginx deve apontar para public/.
$publicDirectory=__DIR__.'/public';
$requestPath=rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)??'/');
$asset=realpath($publicDirectory.$requestPath);
$publicPrefix=realpath($publicDirectory).DIRECTORY_SEPARATOR;
if($requestPath!=='/' && $asset && is_file($asset) && str_starts_with($asset,$publicPrefix)) {
    if(!str_starts_with($requestPath,'/assets/')) return false;
    // O servidor embutido do PHP não envia cabeçalhos de cache. Sem eles, cada página baixava as fontes
    // de novo e o texto "pulava" ao trocar a fonte provisória pela Poppins. Arquivos com ?v= mudam de URL
    // a cada alteração, então podem ficar em cache por um ano.
    $types=['css'=>'text/css; charset=UTF-8','js'=>'text/javascript; charset=UTF-8','ttf'=>'font/ttf','woff2'=>'font/woff2','png'=>'image/png','svg'=>'image/svg+xml','txt'=>'text/plain; charset=UTF-8'];
    $etag='"'.dechex(filemtime($asset)).'-'.dechex(filesize($asset)).'"';
    header('Cache-Control: public, max-age='.(isset($_GET['v'])?31536000:604800));
    header('ETag: '.$etag);
    header('Last-Modified: '.gmdate('D, d M Y H:i:s',filemtime($asset)).' GMT');
    header('X-Content-Type-Options: nosniff');
    if(trim($_SERVER['HTTP_IF_NONE_MATCH']??'')===$etag){http_response_code(304);return true;}
    header('Content-Type: '.($types[strtolower(pathinfo($asset,PATHINFO_EXTENSION))]??'application/octet-stream'));
    header('Content-Length: '.filesize($asset));
    readfile($asset);
    return true;
}
require $publicDirectory.'/index.php';
