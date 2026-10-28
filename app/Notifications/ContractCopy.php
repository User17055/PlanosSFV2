<?php

namespace App\Notifications;

use App\Models\ContractDocument;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

class ContractCopy extends Notification
{
    public function __construct(private ContractDocument $document) {}

    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        $subscription=$this->document->subscription;
        $snapshot=$subscription->contract_snapshot;
        $bytes=Storage::disk(config('integrations.contracts.disk','local'))->get($this->document->storage_path);
        return (new MailMessage)->subject('Seu contrato assinado · São Francisco')->greeting('Olá, '.data_get($snapshot,'customer.name',$subscription->customer->name).'!')
            ->line('Sua assinatura foi registrada. Enviamos em anexo a cópia do contrato de '.data_get($snapshot,'product.name').' para '.data_get($snapshot,'pet.name').'.')
            ->line('O contrato também fica disponível na sua área do cliente.')
            ->action('Acessar a área do cliente',config('site_portal.public_url').'/area-do-cliente')
            ->line('A ativação depende da confirmação do primeiro pagamento e das demais condições do contrato.')
            ->attachData($bytes,'contrato-'.$subscription->number.'.pdf',['mime'=>'application/pdf']);
    }
}
