<?php

namespace App\Services\Domain;

use App\Models\{AuditLog, Proposal};
use App\Notifications\PaymentReminder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{Log, Notification};
use Illuminate\Support\Str;

/** Lembrete por e-mail para quem assinou e não pagou a entrada: até 3, com 2 dias entre eles, a partir de 24 h depois da assinatura. */
class PaymentReminders
{
    public const MAX = 3;

    /** @return Collection<int, Proposal> */
    public function due(): Collection
    {
        return Proposal::whereNotNull('accepted_at')->where('accepted_at', '<=', now()->subDay())->where('signature_method', 'digital')->where('status', '!=', 'cancelled')
            ->whereHas('subscription', fn ($s) => $s->whereNotIn('status', \App\Models\Subscription::TERMINAL)
                ->whereHas('invoices', fn ($i) => $i->where('is_initial', true)->whereNotIn('status', ['paid', 'cancelled', 'refunded'])))
            ->with(['customer', 'pet', 'productVersion', 'campaign'])->get()
            ->filter(function (Proposal $p) {
                if (! filter_var($p->customer->email, FILTER_VALIDATE_EMAIL)) return false;
                $sent = AuditLog::where('action', 'proposal.payment_reminder')->where('entity_id', $p->id)->where('entity_type', $p->getMorphClass());
                return (clone $sent)->count() < self::MAX && ! (clone $sent)->where('created_at', '>', now()->subDays(2)->addHour())->exists();
            })->values();
    }

    public function send(Proposal $proposal): bool
    {
        // Link novo de pagamento: abre pelo CPF, sem senha, enquanto a entrada estiver em aberto (o anterior deixa de valer).
        $token = Str::random(64);
        $proposal->update(['link_token_hash' => hash('sha256', $token), 'link_revoked_at' => null]);
        try {
            Notification::route('mail', $proposal->customer->email)->notify(new PaymentReminder($proposal, route('sales.proposal', ['token' => $token])));
        } catch (\Throwable $e) {
            Log::warning('payment_reminder_failed', ['proposal_id' => $proposal->id, 'error_type' => $e::class]);
            return false;
        }
        Audit::record('proposal.payment_reminder', $proposal, null, ['number' => AuditLog::where('action', 'proposal.payment_reminder')->where('entity_id', $proposal->id)->count() + 1]);
        return true;
    }
}
