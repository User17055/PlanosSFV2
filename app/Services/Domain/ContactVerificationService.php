<?php

namespace App\Services\Domain;

use App\Models\Subscription;
use App\Models\User;
use App\Notifications\PortalVerifyEmail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/** A confirmação do e-mail é o último passo: vem depois da assinatura e do pagamento e libera a ativação. */
class ContactVerificationService
{
    public function requestAfterPayment(int $customerId): void
    {
        $user = User::where('customer_id', $customerId)->where('role', 'customer')->where('active', true)->whereNull('email_verified_at')->first();
        if (! $user || ! Cache::add('contact-verify-mail:'.$user->id, 1, now()->addHour())) {
            return;
        }
        try {
            $user->notify(new PortalVerifyEmail);
        } catch (\Throwable $e) {
            Cache::forget('contact-verify-mail:'.$user->id);
            Log::warning('portal.verification_pending', ['user_id' => $user->id, 'error_type' => $e::class]);
        }
    }

    public function activatePending(int $customerId): void
    {
        foreach (Subscription::where('customer_id', $customerId)->whereNotIn('status', Subscription::TERMINAL)->get() as $subscription) {
            app(ActivationService::class)->activate($subscription);
        }
    }
}
