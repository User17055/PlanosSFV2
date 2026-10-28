<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$source=$argv[1]??throw new RuntimeException('Informe a pasta dos Word originais.');
$directory=resource_path('contracts/word');
if(!is_dir($directory)) mkdir($directory,0700,true);
$indexPath=$directory.'/index.json';
$index=is_file($indexPath)?json_decode(file_get_contents($indexPath),true):[];
foreach(App\Services\Contracts\HealthPlanInstaller::PLANS as $slug=>$_) {
    $layout=json_decode(file_get_contents(resource_path('contracts/plano-'.$slug.'.json')),true,512,JSON_THROW_ON_ERROR);
    $input=$source.DIRECTORY_SEPARATOR.$layout['source'];
    $temporary=$directory.'/preparing-'.$slug.'.docx';
    $fields=app(App\Services\Contracts\OriginalWordContract::class)->prepare($input,$temporary);
    $hash=hash_file('sha256',$temporary);
    if (is_file($directory.'/'.$hash.'.docx')) unlink($temporary);
    else rename($temporary,$directory.'/'.$hash.'.docx');
    $key=hash('sha256',json_encode($layout,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    $index[$key]=['source'=>$layout['source'],'source_sha256'=>hash_file('sha256',$input),'docx_sha256'=>$hash,'text_sha256'=>$layout['template_sha256']];
    echo $slug.': '.count($fields).' campos preparados'.PHP_EOL;
}
file_put_contents($indexPath,json_encode($index,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
