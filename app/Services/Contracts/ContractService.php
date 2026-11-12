<?php

namespace App\Services\Contracts;

use App\Models\ContractDocument;
use App\Models\Subscription;
use App\Notifications\ContractCopy;
use App\Services\Domain\ActivationService;
use App\Services\Domain\Fingerprint;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ContractService
{
    public function reviewPdf(array $snapshot): string
    {
        $directory=storage_path('app/private/contract-reviews');
        if(!is_dir($directory)) mkdir($directory,0700,true);
        $key=hash('sha256','review-word-v1|'.Fingerprint::json($snapshot));
        $path=$directory.'/'.$key.'.pdf';
        if(is_file($path) && filemtime($path)>time()-7200 && str_starts_with($bytes=file_get_contents($path),'%PDF-')) return $bytes;
        $bytes=app(OriginalWordContract::class)->pdf($snapshot);
        if($bytes===null) {
            $text=app(ContractTemplate::class)->render((string)data_get($snapshot,'version.contract_template'),$snapshot);
            $bytes=$this->renderPdf('<html><meta charset="utf-8"><body style="font-family:DejaVu Sans;white-space:pre-wrap">'.htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</body></html>');
        }
        if(!str_starts_with($bytes,'%PDF-')) throw new \RuntimeException('Não foi possível gerar a conferência do contrato.');
        $temporary=$path.'.'.Str::uuid().'.tmp';
        try {
            if(file_put_contents($temporary,$bytes,LOCK_EX)===false || !rename($temporary,$path)) throw new \RuntimeException('Não foi possível guardar o PDF para conferência.');
        } finally { if(is_file($temporary))unlink($temporary); }
        return $bytes;
    }

    public function templatePdf(\App\Models\ProductVersion $version): string
    {
        $snapshot=['version'=>$version->toArray(),'product'=>$version->product->toArray()];
        $original = app(OriginalWordContract::class)->pdf($snapshot, [], false);
        if ($original !== null) return $original;
        $html=app(SourceContractRenderer::class)->html($snapshot,[],true,null,false);
        if ($html===null) $html='<html><meta charset="utf-8"><body style="font-family:DejaVu Sans;white-space:pre-wrap">'.htmlspecialchars($version->contract_template,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</body></html>';
        return $this->renderPdf($html,[0,0,(float)data_get($snapshot,'version.contract_layout.width',612),(float)data_get($snapshot,'version.contract_layout.height',792)]);
    }

    public function generateDigital(Subscription $subscription, array $evidence = []): ContractDocument
    {
        if (($evidence['affirmative'] ?? false) !== true) {
            throw ValidationException::withMessages(['acceptance' => 'O aceite explícito do titular é obrigatório.']);
        }
        $method = $evidence['signature_method'] ?? 'digital';
        if (! in_array($method, ['digital', 'tablet'], true)) {
            throw ValidationException::withMessages(['signature_method' => 'Método de assinatura inválido.']);
        }
        $existing = ContractDocument::where('subscription_id', $subscription->id)
            ->where('kind', 'final_contract')->where('status', 'valid')->first();
        if ($existing) {
            return $existing;
        }
        $snapshot = $this->snapshot($subscription);
        $proposal=$subscription->proposal;
        if($proposal?->accepted_at) {
            $evidence=array_merge($evidence,(array)$proposal->acceptance_evidence);
            $evidence['accepted_at']=$proposal->accepted_at->toIso8601String();
            $evidence['signature_method']=$proposal->signature_method;
            $method=$proposal->signature_method;
        }
        $evidence['accepted_at'] ??= now()->toIso8601String();
        $evidence['snapshot_sha256'] = Fingerprint::sha256($snapshot);
        $signatureImage = $this->signatureImage($evidence['signature_data'] ?? $evidence['signature_image'] ?? null);
        unset($evidence['signature_image']);
        if ($signatureImage) {
            $evidence['signature_image_sha256'] = hash('sha256', $signatureImage);
            $evidence['signature_data'] = $signatureImage;
        }
        $bytes = $this->pdf($subscription, $snapshot, $evidence, $signatureImage, false);
        $document = $this->persist($subscription, $bytes, 'final_contract', $method, 'valid', $evidence);
        // Activation is centralized and idempotent. PDF storage alone never confirms payment.
        app(ActivationService::class)->activate($subscription->fresh());
        $this->sendCustomerCopy($document);
        return $document;
    }

    public function generatePaperDraft(Subscription $subscription, int $actorId): ContractDocument
    {
        $existing = ContractDocument::where('subscription_id', $subscription->id)->where('kind', 'paper_draft')->first();
        if ($existing) {
            return $existing;
        }
        $evidence = ['issued_by' => $actorId, 'issued_at' => now()->toIso8601String(), 'unsigned' => true];
        $bytes = $this->pdf($subscription, $this->snapshot($subscription), $evidence, null, true);
        return $this->persist($subscription, $bytes, 'paper_draft', 'paper', 'pending', $evidence, $actorId);
    }

    public function uploadPaper(Subscription $subscription, UploadedFile $file, int $actorId, array $evidence = []): ContractDocument
    {
        $this->snapshot($subscription);
        $max = (int) config('integrations.contracts.maximum_upload_kb', 10240) * 1024;
        if (! $file->isValid() || $file->getSize() <= 0 || $file->getSize() > $max || $file->getMimeType() !== 'application/pdf') {
            throw ValidationException::withMessages(['file' => 'Envie o contrato completo em PDF, com no máximo '.($max / 1024).' KB.']);
        }
        $bytes = file_get_contents($file->getRealPath());
        if (! str_starts_with($bytes, '%PDF-') || ! str_contains(substr($bytes, -2048), '%%EOF')) {
            throw ValidationException::withMessages(['file' => 'O arquivo não é um PDF completo reconhecido.']);
        }
        // Attachments stay private and cannot activate until a separate review.
        $signedAt = $evidence['signed_at'] ?? $evidence['accepted_at'] ?? null;
        try {
            $signedAt = $signedAt ? \Carbon\CarbonImmutable::parse($signedAt, 'America/Sao_Paulo') : null;
        } catch (\Throwable) {
            $signedAt = null;
        }
        if (! $signedAt || $signedAt->isFuture()) {
            throw ValidationException::withMessages(['signed_at' => 'Informe a data real da assinatura em papel, sem data futura.']);
        }
        $scan = app(DocumentVirusScanner::class)->scan($bytes);
        $evidence = array_merge($evidence, ['accepted_at' => $signedAt->toIso8601String(), 'signing_date_source' => 'operator_reported_pending_review', 'uploaded_at' => now()->toIso8601String(), 'malware_scan' => $scan]);
        return $this->persist($subscription, $bytes, 'final_contract', 'paper', 'pending', $evidence, $actorId, $file->getClientOriginalName());
    }

    public function validatePaper(ContractDocument $document, bool $approved, int $actorId, ?string $reason = null): ContractDocument
    {
        if ($document->kind !== 'final_contract' || $document->signature_method !== 'paper') {
            throw ValidationException::withMessages(['document' => 'A conferência exige uma digitalização do contrato assinado.']);
        }
        if (! $approved && blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'Informe o motivo da rejeição.']);
        }
        if ($approved && ! $this->verifyIntegrity($document)) {
            throw ValidationException::withMessages(['document' => 'Falha na integridade do documento. Envie uma nova versão para conferência.']);
        }
        if ($approved && config('integrations.contracts.require_antivirus') && data_get($document->evidence, 'malware_scan.status') !== 'clean') {
            throw ValidationException::withMessages(['document' => 'O contrato aguarda verificação antimalware.']);
        }
        DB::transaction(function () use ($document, $approved, $actorId, $reason) {
            $subscription = Subscription::whereKey($document->subscription_id)->lockForUpdate()->firstOrFail();
            $locked = ContractDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $latest = ContractDocument::where('subscription_id', $subscription->id)->where('kind', 'final_contract')->where('signature_method', 'paper')->max('version');
            if ((int) $locked->version !== (int) $latest) {
                throw ValidationException::withMessages(['document' => 'Existe uma digitalização mais recente para conferir.']);
            }
            $locked->update(['status' => $approved ? 'valid' : 'rejected', 'validated_at' => now(),
                'validated_by' => $actorId, 'rejection_reason' => $approved ? null : $reason]);
            $subscription->update(['document_status' => $approved ? 'valid' : 'rejected']);
        }, 3);
        if ($approved) {
            app(ActivationService::class)->activate(Subscription::findOrFail($document->subscription_id));
            $this->sendCustomerCopy($document->fresh());
        }
        return $document->fresh();
    }

    /** Emails the signed copy once per document, only after the first payment. A mail failure never undoes the signature. */
    public function sendCustomerCopy(ContractDocument $document): bool
    {
        $subscription = $document->subscription;
        $email = $subscription?->customer?->email;
        // A base antiga não recebe e-mail automático, nem quando o contrato escaneado é validado.
        if ($document->kind !== 'final_contract' || $document->status !== 'valid' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || $subscription->imported_at) {
            return false;
        }
        if (! $subscription->activated_at && ! $subscription->invoices()->where('is_initial', true)->where('status', 'paid')->exists()) {
            return false;
        }
        if (! ContractDocument::whereKey($document->id)->whereNull('customer_notified_at')->update(['customer_notified_at' => now()])) {
            return false;
        }
        try {
            Notification::route('mail', $email)->notify(new ContractCopy($document));
            return true;
        } catch (\Throwable $exception) {
            ContractDocument::whereKey($document->id)->update(['customer_notified_at' => null]);
            Log::warning('contract_copy_email_failed', ['document_id' => $document->id, 'error_type' => $exception::class, 'reason' => mb_substr($exception->getMessage(), 0, 300)]);
            return false;
        }
    }

    public function verifyIntegrity(ContractDocument $document): bool
    {
        $disk = Storage::disk(config('integrations.contracts.disk', 'local'));
        return $disk->exists($document->storage_path)
            && hash_equals((string) $document->sha256, hash('sha256', $disk->get($document->storage_path)));
    }

    private function snapshot(Subscription $subscription): array
    {
        if (! $subscription->locked_at || empty($subscription->contract_snapshot)) {
            throw ValidationException::withMessages(['subscription' => 'A proposta deve estar congelada e com vaga assegurada antes de emitir o contrato.']);
        }
        $snapshot = $subscription->contract_snapshot;
        foreach (['customer.name', 'customer.document', 'pet.name', 'product.name', 'version.contract_template'] as $field) {
            if (blank(data_get($snapshot, $field))) {
                throw ValidationException::withMessages(['contract' => 'Cadastro ou texto contratual incompleto: '.$field]);
            }
        }
        return $snapshot;
    }

    private function persist(Subscription $subscription, string $bytes, string $kind, string $method, string $status, array $evidence, ?int $actorId = null, ?string $originalName = null): ContractDocument
    {
        $path = 'contracts/'.$subscription->id.'/'.Str::uuid().'.pdf';
        $disk = Storage::disk(config('integrations.contracts.disk', 'local'));
        if (! $disk->put($path, $bytes, ['visibility' => 'private'])) {
            throw new \RuntimeException('Não foi possível armazenar o contrato. A contratação permanece pendente.');
        }
        try {
            $document = DB::transaction(function () use ($subscription, $bytes, $kind, $method, $status, $evidence, $actorId, $originalName, $path) {
                $locked = Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();
                if ($kind === 'final_contract') {
                    $existing = ContractDocument::where('subscription_id', $locked->id)->where('kind', $kind)->where('status', 'valid')->first();
                    if ($existing) {
                        return $existing;
                    }
                }
                $version = 1 + (int) ContractDocument::where('subscription_id', $locked->id)->max('version');
                $document = ContractDocument::create([
                    'subscription_id' => $locked->id, 'proposal_id' => $locked->proposal_id,
                    'kind' => $kind, 'signature_method' => $method, 'status' => $status,
                    'storage_path' => $path, 'original_name' => $originalName ? basename($originalName) : 'contrato-'.$locked->id.'.pdf',
                    'mime_type' => 'application/pdf', 'sha256' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes),
                    'version' => $version, 'accepted_at' => $evidence['accepted_at'] ?? null,
                    'validated_at' => $status === 'valid' ? now() : null, 'uploaded_by' => $actorId, 'evidence' => $evidence,
                ]);
                if ($kind !== 'paper_draft') {
                    $locked->update(['document_status' => $status]);
                }
                return $document;
            }, 3);
            if ($document->storage_path !== $path) {
                $disk->delete($path);
            }
            return $document;
        } catch (\Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        }
    }

    private function pdf(Subscription $subscription, array $snapshot, array $evidence, ?string $signatureImage, bool $draft): string
    {
        $original = app(OriginalWordContract::class)->pdf($snapshot, [
            'accepted_at' => $draft ? null : ($evidence['accepted_at'] ?? null),
            'contract_number' => $subscription->number,
            'signature_image' => $signatureImage,
            'evidence_lines' => $draft ? [] : $this->evidenceLines($subscription, $snapshot, $evidence),
            'selfie_jpeg' => $draft ? null : $this->selfie($evidence),
        ]);
        if ($original !== null) return $original;
        $escape = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $price = number_format(((int) data_get($snapshot, 'version.price_cents', 0)) / 100, 2, ',', '.');
        $category = ['health_plan' => 'Plano de Saúde', 'package' => 'Pacote'][data_get($snapshot, 'category', data_get($snapshot, 'product.category'))] ?? data_get($snapshot, 'product.category');
        $billing = ['monthly' => 'mensal', 'annual' => 'anual', 'one_time' => 'cobrança única'][data_get($snapshot, 'version.billing_cycle')] ?? data_get($snapshot, 'version.billing_cycle');
        $species = ['dog' => 'Cão', 'cat' => 'Gato'][data_get($snapshot, 'pet.species')] ?? data_get($snapshot, 'pet.species');
        $birth = data_get($snapshot, 'pet.birth_date');
        $birthLabel = $birth ? \Carbon\CarbonImmutable::parse(substr($birth, 0, 10))->format('d/m/Y') : 'idade estimada de '.data_get($snapshot, 'pet.estimated_age_months').' meses';
        $html = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;color:#173d36;font-size:10pt;line-height:1.45}h1{font-size:20pt}h2{font-size:12pt;margin-top:20pt;page-break-after:avoid}.meta{background:#edf5f0;padding:12pt}.terms{white-space:pre-wrap}.benefit{padding:8pt 5pt;border-bottom:1px solid #ddd;page-break-inside:avoid;word-wrap:break-word}.small{font-size:8pt;color:#555}footer{position:fixed;bottom:-15pt;font-size:8pt;color:#777}img{max-width:240pt;max-height:90pt}</style></head><body>';
        $html .= '<footer>São Francisco • Proposta '.$escape($subscription->proposal_id).' • Assinatura '.$escape($subscription->id).'</footer>';
        $html .= '<h1>São Francisco</h1><h2>'.($draft ? 'Documento para assinatura em papel — não assinado' : 'Contrato final e registro de aceite').'</h2>';
        if (data_get($snapshot, 'version.is_demo')) {
            $html .= '<p><strong>MODELO DEMONSTRATIVO — condições comerciais e texto aguardam aprovação para operação.</strong></p>';
        }
        $html .= '<div class="meta">Proposta: '.$escape($subscription->proposal_id).' | Assinatura: '.$escape($subscription->id).'<br>Produto: '.$escape(data_get($snapshot, 'product.name')).' • versão '.$escape(data_get($snapshot, 'version.version')).'<br>Categoria: '.$escape($category).'<br>Valor contratado: R$ '.$price.' • '.$escape($billing).'</div>';
        $html .= '<h2>Titular contratante</h2>'.$escape(data_get($snapshot, 'customer.name')).'<br>Documento: '.$escape(data_get($snapshot, 'customer.document')).'<br>'.$escape(data_get($snapshot, 'customer.email')).' • '.$escape(data_get($snapshot, 'customer.phone'));
        $html .= '<h2>Pet coberto</h2>'.$escape(data_get($snapshot, 'pet.name')).' • '.$escape($species).'<br>Nascimento: '.$escape($birthLabel);
        $terms = app(ContractTemplate::class)->render((string) data_get($snapshot, 'version.contract_template'), $snapshot, [
            'accepted_at' => $draft ? null : ($evidence['accepted_at'] ?? null), 'contract_number' => $subscription->number,
        ]);
        $html .= '<h2>Texto integral contratado</h2><div class="terms">'.$escape($terms).'</div>';
        $html .= '<h2>Condições congeladas e benefícios</h2>';
        foreach ($snapshot['benefits'] ?? [] as $benefit) {
            $period = ['calendar_month' => 'mês-calendário', 'monthly_cycle' => 'ciclo mensal da vigência', 'contract_year' => 'ano contratual', 'lifetime' => 'toda a vigência', 'rolling_window' => 'janela móvel'][($benefit['period_type'] ?? '')] ?? ($benefit['period_type'] ?? 'não definido');
            $limit = isset($benefit['quantity_limit']) ? $benefit['quantity_limit'].' '.($benefit['unit'] ?? 'procedimento(s)') : 'sem limite numérico';
            $details = ['Limite: '.$limit.' por '.$period.'.', 'Carência: '.($benefit['waiting_days'] ?? 0).' dia(s).'];
            if (!empty($benefit['description'])) $details[] = $benefit['description'];
            if (($benefit['coverage_type'] ?? 'included') === 'discount') $details[] = 'Desconto: '.number_format(((int)($benefit['discount_basis_points'] ?? 0))/100, 2, ',', '.').'%.';
            else $details[] = 'Cobertura incluída no produto.';
            if (!empty($benefit['copay_cents'])) $details[] = 'Coparticipação: R$ '.number_format($benefit['copay_cents']/100, 2, ',', '.').'.';
            if (!empty($benefit['requires_authorization'])) $details[] = 'Exige autorização adicional.';
            if (!empty($benefit['renewal_window_days'])) $details[] = 'Janela de apuração: '.$benefit['renewal_window_days'].' dias.';
            if (!empty($benefit['allowed_franchise_ids'])) $details[] = 'Unidades de atendimento (identificadores): '.implode(', ', $benefit['allowed_franchise_ids']).'.';
            if (!empty($benefit['exclusions'])) $details[] = 'Exclusões: '.$benefit['exclusions'];
            $html .= '<div class="benefit"><strong>'.$escape($benefit['name'] ?? $benefit['description'] ?? $benefit['service_code'] ?? 'Benefício').'</strong><br>'.$escape(implode(' ', $details)).'</div>';
        }
        $html .= '<h2>'.($draft ? 'Assinatura do titular' : 'Evidências do aceite').'</h2>';
        if ($draft) {
            $html .= '<p>Assinatura: ______________________________________</p><p>'.$escape(data_get($snapshot, 'customer.name')).'<br>CPF: '.$escape(data_get($snapshot, 'customer.document')).'<br>CONTRATANTE</p><p>Data: ____/____/________</p><p class="small">Após assinatura, digitalizar todas as páginas e submeter à conferência no sistema. Este arquivo sem assinatura não permite ativação.</p>';
        } else {
            $acceptedAt = !empty($evidence['accepted_at']) ? \Carbon\CarbonImmutable::parse($evidence['accepted_at'])->setTimezone('America/Sao_Paulo')->format('d/m/Y H:i:s').' (Brasília)' : 'data não informada';
            $methodLabel = ($evidence['signature_method'] ?? 'digital') === 'tablet' ? 'assinatura presencial no tablet' : 'aceite digital com assinatura desenhada';
            $html .= '<p>Ação afirmativa registrada em '.$escape($acceptedAt).'. Método: '.$escape($methodLabel).'.</p>';
            if ($signatureImage) {
                $html .= '<img src="'.$signatureImage.'" alt="Traço de assinatura do titular">';
            }
            $html .= '<p class="small">'.implode('<br>', array_map($escape, array_slice($this->evidenceLines($subscription, $snapshot, $evidence), 1))).'</p>';
            if ($selfie = $this->selfie($evidence)) {
                $html .= '<img src="data:image/jpeg;base64,'.base64_encode($selfie).'" alt="Foto do titular">';
            }
            $html .= '<p>'.$escape(data_get($snapshot, 'customer.name')).'<br>CPF: '.$escape(data_get($snapshot, 'customer.document')).'<br>CONTRATANTE</p>';
            $html .= '<p class="small">Registro de integridade das condições: '.$escape($evidence['snapshot_sha256'] ?? '').'.</p>';
        }
        $html .= '</body></html>';
        return $this->renderPdf($html);
    }

    /** Electronic signature record printed on the last page of the contract. The first line is the title. */
    private function evidenceLines(Subscription $subscription, array $snapshot, array $evidence): array
    {
        $at = ! empty($evidence['accepted_at']) ? \Carbon\CarbonImmutable::parse($evidence['accepted_at'])->setTimezone('America/Sao_Paulo')->format('d/m/Y \à\s H:i:s').' (Brasília)' : 'não informada';
        $device = (array) ($evidence['device'] ?? []);
        $deviceText = collect([
            $device['platform'] ?? null,
            isset($device['mobile']) ? ($device['mobile'] ? 'celular ou tablet' : 'computador') : null,
            ! empty($device['screen']) ? 'tela '.$device['screen'] : null,
            ! empty($device['touch_points']) ? 'tela sensível ao toque' : null,
            ! empty($device['cores']) ? $device['cores'].' núcleos' : null,
            ! empty($device['memory_gb']) ? $device['memory_gb'].' GB de memória' : null,
        ])->filter()->implode(' · ');
        $lines = [
            'REGISTRO DA ASSINATURA ELETRÔNICA',
            'Contrato '.$subscription->number.' · '.(data_get($snapshot, 'version.name') ?: data_get($snapshot, 'product.name')).' para '.data_get($snapshot, 'pet.name'),
            'Signatário: '.($evidence['signer_name'] ?? data_get($snapshot, 'customer.name')).' · CPF '.data_get($snapshot, 'customer.document'),
            'Data e hora da assinatura: '.$at,
            'Forma: '.(($evidence['signature_method'] ?? 'digital') === 'tablet' ? 'presencial, no tablet da unidade, com foto do titular' : 'eletrônica, pelo próprio aparelho do titular').', com confirmação dos termos e assinatura desenhada',
            ($evidence['source'] ?? null) === 'signature_link' ? 'Identificação: link pessoal enviado ao titular, com confirmação do CPF' : null,
            'Endereço IP: '.($evidence['ip'] ?? 'não informado').(isset($evidence['ip_port']) ? ' · porta '.$evidence['ip_port'] : ''),
            'Navegador: '.($evidence['user_agent'] ?? 'não informado'),
            'Aparelho: '.($deviceText ?: 'não informado'),
            'Idioma e fuso horário: '.collect([$device['language'] ?? ($evidence['accept_language'] ?? null), $device['timezone'] ?? null])->filter()->implode(' · '),
            ! empty($device['connection']) ? 'Conexão: '.$device['connection'] : null,
            'Código de integridade das condições aceitas (SHA-256): '.($evidence['snapshot_sha256'] ?? ''),
            ! empty($evidence['signature_hash']) ? 'Código de integridade da assinatura desenhada (SHA-256): '.$evidence['signature_hash'] : null,
            ! empty($evidence['selfie_sha256']) ? 'Foto do titular registrada no momento da assinatura (SHA-256 '.$evidence['selfie_sha256'].'):' : null,
        ];
        return array_values(array_filter($lines, fn ($line) => $line !== null && trim($line) !== ''));
    }

    private function selfie(array $evidence): ?string
    {
        $selfie = ! empty($evidence['selfie_path']) ? Storage::disk(config('integrations.contracts.disk', 'local'))->get($evidence['selfie_path']) : null;
        return $selfie && hash_equals((string) ($evidence['selfie_sha256'] ?? ''), hash('sha256', $selfie)) ? $selfie : null;
    }

    private function renderPdf(string $html, array|string $paper = 'A4'): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $fontDirectory=storage_path('app/private/pdf-fonts');
        if (!is_dir($fontDirectory)) mkdir($fontDirectory,0700,true);
        $options->set('fontDir',$fontDirectory);
        $options->set('fontCache',$fontDirectory);
        $options->set('chroot',array_values(array_filter([base_path(),is_dir('C:/Windows/Fonts')?'C:/Windows/Fonts':null])));
        $pdf = new Dompdf($options);
        foreach(['normal'=>['normal','GOTHIC.TTF'],'bold'=>['normal','GOTHICB.TTF'],'italic'=>['italic','GOTHICI.TTF'],'bolditalic'=>['italic','GOTHICBI.TTF']] as $variant=>[$style,$file]) {
            if (is_file('C:/Windows/Fonts/'.$file)) $pdf->getFontMetrics()->registerFont(['family'=>'Contract Source','style'=>$style,'weight'=>str_starts_with($variant,'bold')?'bold':'normal'],'file://C:/Windows/Fonts/'.$file);
        }
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper($paper);
        $pdf->render();
        $pdf->getCanvas()->page_text($pdf->getCanvas()->get_width()-140, $pdf->getCanvas()->get_height()-28, 'Página {PAGE_NUM}/{PAGE_COUNT}', $pdf->getFontMetrics()->getFont('DejaVu Sans'), 8, [0.4, 0.4, 0.4]);
        return $pdf->output();
    }

    public function signatureImage(?string $image): ?string
    {
        if (! $image) {
            return null;
        }
        if (strlen($image) > 350000 || ! str_starts_with($image, 'data:image/png;base64,')) {
            throw ValidationException::withMessages(['signature' => 'Imagem de assinatura inválida.']);
        }
        $raw = base64_decode(substr($image, 22), true);
        $info = $raw ? @getimagesizefromstring($raw) : false;
        if (! $info || ($info['mime'] ?? '') !== 'image/png' || $info[0] > 2400 || $info[1] > 1200 || $info[0]<8 || $info[1]<8) {
            throw ValidationException::withMessages(['signature' => 'Imagem de assinatura inválida.']);
        }
        if(!function_exists('imagecreatefromstring')) throw ValidationException::withMessages(['signature'=>'O servidor não está preparado para validar o desenho. Solicite apoio à unidade.']);
        $picture=@imagecreatefromstring($raw);
        if(!$picture) throw ValidationException::withMessages(['signature'=>'Não foi possível ler a assinatura. Desenhe novamente.']);
        try {
            $ink=0; $minX=$info[0]; $minY=$info[1]; $maxX=0; $maxY=0;
            for($y=0;$y<$info[1];$y++)for($x=0;$x<$info[0];$x++) {
                $pixel=imagecolorsforindex($picture,imagecolorat($picture,$x,$y));
                if($pixel['alpha']<110 && min($pixel['red'],$pixel['green'],$pixel['blue'])<210) {
                    $ink++;$minX=min($minX,$x);$minY=min($minY,$y);$maxX=max($maxX,$x);$maxY=max($maxY,$y);
                    if($ink>=25 && max($maxX-$minX,$maxY-$minY)>=8)break 2;
                }
            }
            if($ink<25 || max($maxX-$minX,$maxY-$minY)<8) throw ValidationException::withMessages(['signature'=>'A assinatura está vazia ou contém apenas um ponto. Desenhe seu traço antes de confirmar.']);
        } finally { imagedestroy($picture); }
        return 'data:image/png;base64,'.base64_encode($raw);
    }
}
