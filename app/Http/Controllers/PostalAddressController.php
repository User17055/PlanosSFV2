<?php

namespace App\Http\Controllers;

use App\Services\PostalAddressLookup;

class PostalAddressController extends Controller
{
    public function __invoke(string $zip,PostalAddressLookup $lookup)
    {
        abort_unless(preg_match('/^\d{8}$/',$zip),422);
        $address=$lookup->lookup($zip);
        return $address?response()->json($address):response()->json(['message'=>'CEP não encontrado. Preencha bairro, cidade e UF manualmente.'],404);
    }
}
