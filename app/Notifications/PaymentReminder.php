<?php

namespace App\Notifications;

use App\Models\Proposal;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Contrato assinado com a entrada em aberto: lembra o cliente de pagar para o plano começar a valer. */
class PaymentReminder extends Notification
{
    public function __construct(private Proposal $proposal, private string $url) {}

    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        $p = $this->proposal;
        $entry = $p->subscription?->invoices()->where('is_initial', true)->first();
        return (new MailMessage)->subject('Falta só o pagamento para ativar o plano de '.$p->pet->name.' · São Francisco')
            ->view(['emails.payment-reminder', 'emails.payment-reminder-text'], [
                'name' => explode(' ', trim($p->customer->name))[0], 'plan' => $p->productVersion->name, 'pet' => $p->pet->name,
                'amount' => $entry ? 'R$ '.number_format($entry->amount_cents / 100, 2, ',', '.') : null,
                'campaign' => $p->campaign?->name ?? 'Campanha São Francisco', 'freeMonths' => (int) $p->free_months,
                'url' => $this->url, 'site' => config('site_portal.public_url'),
            ]);
    }
}
