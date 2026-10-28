<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class PortalPasswordReset extends Notification
{
    public function __construct(private string $url) {}
    public function via(object $notifiable): array { return ['mail']; }
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Redefinir sua senha · São Francisco')->greeting('Olá!')
            ->line('Recebemos um pedido para redefinir sua senha da área do cliente.')
            ->action('Criar nova senha',$this->url)->line('Se você não solicitou, pode ignorar esta mensagem.');
    }
}
