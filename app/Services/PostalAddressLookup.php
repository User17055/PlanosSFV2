<?php

namespace App\Services;

use Illuminate\Support\Facades\{Cache,Http};

class PostalAddressLookup
{
    public function lookup(string $zip): ?array
    {
        $zip=preg_replace('/\D/','',$zip);
        if(strlen($zip)!==8)return null;
        try {
            return Cache::remember('postal-address-'.$zip,now()->addDays(30),function()use($zip){
                $request=Http::timeout(6);
                $bundle=config('services.postal.ca_bundle');
                if(!$bundle && PHP_OS_FAMILY==='Windows' && is_file(storage_path('app/private/windows-trusted-ca.pem'))) $bundle=storage_path('app/private/windows-trusted-ca.pem');
                if($bundle) $request=$request->withOptions(['verify'=>$bundle]);
                $data=$request->get('https://viacep.com.br/ws/'.$zip.'/json/')->throw()->json();
                if(!is_array($data)||!empty($data['erro'])||empty($data['localidade'])||empty($data['uf'])) return null;
                return ['zip'=>$data['cep']??$zip,'street'=>$data['logradouro']??'','neighborhood'=>$data['bairro']??'','city'=>$data['localidade'],'state'=>$data['uf']];
            });
        } catch(\Throwable) { return null; }
    }
}
