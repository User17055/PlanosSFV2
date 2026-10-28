<?php

namespace App\Services\Attribution;

use App\Models\{Customer,User};
use Illuminate\Http\Request;

class CustomerAcquisition
{
    public static function rules(): array
    {
        return ['acquisition_source'=>'nullable|in:direct,referral,shared_link,instagram,facebook,google,other',
            'referrer_name'=>'nullable|string|max:180','campaign_name'=>'nullable|string|max:120'];
    }

    public function record(Customer $customer,User $actor,Request $request,array $data): AttributionData
    {
        $attribution=app(AttributionService::class)->proposalData($request,array_intersect_key($data,self::rules()),$actor);
        $customer->update(['acquisition_profile'=>$attribution->proposalAttributes()]);
        if($actor->role==='customer' && (int)$actor->customer_id===(int)$customer->id)$actor->setRelation('customer',$customer);
        return $attribution;
    }
}
