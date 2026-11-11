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
        $mail = (new MailMessage)->subject('Falta só o pagamento para ativar o seu plano · São Francisco')->greeting('Olá, '.$p->customer->name.'!')
            ->line('Você assinou o '.$p->productVersion->name.' para '.$p->pet->name.', mas o pagamento da entrada ainda está em aberto. O plano só começa a valer depois dele.');
        if ($p->free_months) {
            $mail->line('Sua contratação está na campanha '.($p->campaign?->name ?? 'da São Francisco').': '.$p->free_months.' '.($p->free_months === 1 ? 'mensalidade grátis' : 'mensalidades grátis').' depois da entrada. Pague para garantir a promoção.');
        }
        return $mail->action('Pagar a entrada', $this->url)
            ->line('Para sua segurança, vamos pedir o CPF do titular. Se você já pagou, desconsidere este e-mail.');
    }
}
