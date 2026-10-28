<?php

namespace App\Http\Controllers;

use App\Services\Reports\DashboardService;
use Illuminate\Support\Facades\{Cache,Http};

class DashboardMapController extends Controller
{
    public function neighborhoods(string $city)
    {
        abort_unless(preg_match('/^\d{7}$/',$city) && array_key_exists($city,DashboardService::cityCatalog()),404);
        foreach([$city.'.json',$city.'-points.json'] as $filename) {
            $path=public_path('assets/maps/neighborhoods/'.$filename);
            if(is_file($path)) return response()->file($path,['Content-Type'=>'application/geo+json','Cache-Control'=>'public, max-age=86400']);
        }
        return response()->json(['type'=>'FeatureCollection','features'=>[],'source'=>null,'message'=>'A base cartográfica disponível ainda não cobre os bairros desta cidade. O ranking por bairro continua disponível abaixo.']);
    }

    public function __invoke(string $state)
    {
        abort_unless(in_array($state,DashboardService::STATES,true),404);
        $local=public_path('assets/maps/'.$state.'.json');
        if(is_file($local)) return response()->file($local,['Content-Type'=>'application/geo+json','Cache-Control'=>'public, max-age=86400']);
        try {
            $data=Cache::remember('dashboard-map-'.$state,now()->addDays(30),function()use($state){
                $data=Http::timeout(15)->get('https://servicodados.ibge.gov.br/api/v3/malhas/estados/'.$state,['intrarregiao'=>'municipio','qualidade'=>'minima','formato'=>'application/vnd.geo+json'])->throw()->json();
                if(($data['type']??'')!=='FeatureCollection')throw new \RuntimeException('Malha indisponível');
                return $data;
            });
            return response()->json($data)->header('Cache-Control','public, max-age=86400');
        } catch(\Throwable) { return response()->json(['message'=>'O mapa está temporariamente indisponível. Consulte os números nas tabelas.'],503); }
    }
}
