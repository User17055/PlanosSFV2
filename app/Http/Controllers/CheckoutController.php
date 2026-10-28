<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Services\AccessService;
use App\Services\Integrations\{VindiCheckoutService,IntegrationPendingException};
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function __invoke(Request $request, Subscription $subscription, AccessService $access, VindiCheckoutService $checkout)
    {
        $access->customer($request->user(),$subscription->customer);
        $request->validate(['payment_method'=>'nullable|string|max:60']);
        try {
            $url=$checkout->start($subscription,$request->input('payment_method'));
        } catch (IntegrationPendingException $exception) {
            return back()->with('error',$exception->getMessage());
        }
        if ($subscription->invoices()->where('is_initial',true)->where('status','paid')->exists()) {
            return redirect()->route('portal')->with('success','Pagamento confirmado. Acompanhe a ativação do plano no portal.');
        }
        return redirect()->away($url)->header('Cache-Control','private, no-store')->header('Referrer-Policy','no-referrer');
    }
}
