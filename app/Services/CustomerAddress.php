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

    public static function display(mixed $address): string
    {
        if(!is_array($address))return (string)$address;
        return implode(', ',array_filter([$address['formatted']??null,$address['neighborhood']??null,$address['city']??null,$address['state']??null,$address['zip']??null]));
    }
}
