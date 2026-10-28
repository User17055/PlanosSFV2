<?php

namespace App\Notifications;

use App\Models\Proposal;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProposalLink extends Notification
{
    public function __construct(private Proposal $proposal, private string $url) {}

    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        $expires=($this->proposal->link_expires_at ?? $this->proposal->expires_at)->timezone('America/Sao_Paulo')->format('d/m/Y \à\s H:i');
        return (new MailMessage)->subject('Seu contrato está pronto para assinatura · São Francisco')->greeting('Olá, '.$this->proposal->customer->name.'!')
            ->line('Preparamos o contrato de '.$this->proposal->productVersion->name.' para '.$this->proposal->pet->name.'.')
            ->line('Leia o documento, confirme as condições e assine. Em seguida, você seguirá direto para o pagamento.')
            ->action('Ler e assinar o contrato',$this->url)
            ->line('Este link é pessoal e vale até '.$expires.'. Se você não reconhece esta proposta, ignore este e-mail.');
    }
}
