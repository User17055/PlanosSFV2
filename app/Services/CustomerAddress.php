<?php

namespace App\Services;

class CustomerAddress
{
    public static function rules(): array
    {
        return ['_structured_address'=>'nullable|in:1','street'=>'nullable|required_if:_structured_address,1|string|max:200',
            'number'=>'nullable|required_with:street|string|max:30','complement'=>'nullable|string|max:120',
            'zip'=>'nullable|required_if:_structured_address,1|regex:/^\d{5}-?\d{3}$/'];
    }

    public static function from(array $data, array $previous=[]): array
    {
        $address=array_merge($previous,array_intersect_key($data,array_flip(['street','number','complement','city','state','zip','neighborhood'])));
        if(!empty($data['street'])) {
            $address['formatted']=implode(', ',array_filter([trim($data['street']),trim($data['number']??''),trim($data['complement']??'')],fn($value)=>$value!==''));
        } elseif(array_key_exists('address',$data)) {
            $address['formatted']=$data['address']??'';
            unset($address['street'],$address['number'],$address['complement']);
        }
        if(isset($address['state']))$address['state']=strtoupper($address['state']);
        return $address;
    }

    /** Endereço no formato do cadastro de cliente da Vindi; só vai completo (CEP, rua, número, cidade e UF). */
    public static function vindi(mixed $address): ?array
    {
        if(!is_array($address))return null;
        $zip=preg_replace('/\D/','',(string)($address['zip']??''));
        $state=strtoupper(trim((string)($address['state']??'')));
        if(strlen($zip)!==8 || strlen($state)!==2 || blank($address['street']??null) || blank($address['number']??null) || blank($address['city']??null))return null;
        return ['street'=>trim($address['street']),'number'=>trim($address['number']),'additional_details'=>trim((string)($address['complement']??'')),
            'zipcode'=>$zip,'neighborhood'=>trim((string)($address['neighborhood']??'')),'city'=>trim($address['city']),'state'=>$state,'country'=>'BR'];
    }

    public static function display(mixed $address): string
    {
        if(!is_array($address))return (string)$address;
        return implode(', ',array_filter([$address['formatted']??null,$address['neighborhood']??null,$address['city']??null,$address['state']??null,$address['zip']??null]));
    }
}
