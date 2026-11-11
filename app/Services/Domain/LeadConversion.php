<?php

namespace App\Services\Domain;

use App\Models\{Lead, Subscription};

/** Plano ativado: o lead com o mesmo telefone ou e-mail do cliente vira "Finalizado" e passa a ter o nome do cliente. */
class LeadConversion
{
    /** Telefone comparável: sem o 55 e sem o 9 do celular. "5518996123456" e "1896123456" viram "1896123456". */
    public static function phoneKey(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if (strlen($digits) >= 12 && str_starts_with($digits, '55')) $digits = substr($digits, 2);
        if (strlen($digits) === 11 && $digits[2] === '9') $digits = substr($digits, 0, 2).substr($digits, 3);
        return strlen($digits) === 10 ? $digits : null;
    }

    public function fromSubscription(Subscription $subscription): int
    {
        $customer = $subscription->customer()->first();
        $phone = self::phoneKey($customer?->phone);
        $email = $customer?->email ? mb_strtolower(trim($customer->email)) : null;
        if (! $phone && ! $email) return 0;
        $leads = Lead::where('status', '!=', 'converted')
            ->where(fn ($q) => $q->when($email, fn ($w) => $w->orWhereRaw('lower(email) = ?', [$email]))->when($phone, fn ($w) => $w->orWhere('phone', 'like', '%'.substr($phone, -8))))
            ->get()->filter(fn (Lead $lead) => ($phone && self::phoneKey($lead->phone) === $phone) || ($email && mb_strtolower(trim((string) $lead->email)) === $email));
        foreach ($leads as $lead) {
            // O nome que veio do anúncio fica guardado nos dados do lead.
            $extra = $lead->extra ?? [];
            if ($lead->name !== $customer->name && ! isset($extra['nome_no_anuncio'])) $extra['nome_no_anuncio'] = $lead->name;
            $lead->update(['status' => 'converted', 'name' => mb_substr($customer->name, 0, 150), 'extra' => $extra]);
            Audit::record('lead.converted_by_plan', $lead, null, ['subscription_id' => $subscription->id, 'customer_id' => $customer->id]);
        }
        return $leads->count();
    }
}
