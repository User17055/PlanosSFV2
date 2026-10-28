<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;

class PortalVerifyEmail extends Notification
{
    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        $path=URL::temporarySignedRoute('site-api.verify-email',now()->addHour(),['user'=>$notifiable->id,'hash'=>sha1($notifiable->email)],false);
        $url=config('site_portal.public_url').'/area-do-cliente/verificar-email/'.$notifiable->id.'/'.sha1($notifiable->email).'?'.parse_url($path,PHP_URL_QUERY);
        return (new MailMessage)->subject('Confirme seu e-mail · São Francisco')->greeting('Olá, '.$notifiable->name.'!')
            ->line('Confirme seu e-mail para assinar o contrato e acompanhar os cuidados do seu pet.')
            ->action('Confirmar meu e-mail',$url)->line('Este link é válido por uma hora. Entre na sua área do cliente para concluir.');
    }
}
