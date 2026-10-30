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
        $site=config('site_portal.public_url');
        return (new MailMessage)->subject('Seu contrato assinado · São Francisco')
            ->view(['emails.contract-copy','emails.contract-copy-text'],[
                'name'=>explode(' ',trim(data_get($snapshot,'customer.name',$subscription->customer->name)))[0],
                'plan'=>data_get($snapshot,'product.name'),'pet'=>data_get($snapshot,'pet.name'),'site'=>$site,'url'=>$site.'/area-do-cliente'])
            ->attachData($bytes,'contrato-'.$subscription->number.'.pdf',['mime'=>'application/pdf']);
    }
}
