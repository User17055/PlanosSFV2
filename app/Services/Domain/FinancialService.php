<?php

namespace App\Services\Domain;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Pet;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FinancialService
{
    public function customerStatus(Customer $customer): array
    {
        $staleBefore = now()->subMinutes((int) config('plans.financial_max_age_minutes', 60));
        $overdue = Invoice::query()->where('customer_id', $customer->id)
            ->whereHas('subscription', fn ($q) => $q->where('category', 'health_plan'))
            ->whereNotIn('status', ['cancelled', 'refunded'])->where('due_at', '<', now())
            ->whereColumn('paid_cents', '<', 'amount_cents')->get()->sum(fn ($i) => $i->outstanding_cents);
        $pending = $customer->financial_verification_status !== 'verified' || ! $customer->financial_checked_at
            || $customer->financial_checked_at->lt($staleBefore)
            || $customer->invoices()->where(fn ($q) => $q->where('reconciliation_status', 'uncertain')->orWhereIn('status', ['refunded', 'disputed']))->exists()
            || $customer->subscriptions()->whereNotNull('activated_at')->whereNotIn('status', Subscription::TERMINAL)
                ->where(fn ($q) => $q->where('financial_verification_status', '<>', 'verified')->orWhereNull('financial_checked_at')->orWhere('financial_checked_at', '<', $staleBefore))->exists();

        return ['status' => $overdue > 0 ? 'blocked' : ($pending ? 'pending' : 'regular'), 'blocked' => $overdue > 0,
            'overdue_cents' => $overdue, 'last_checked_at' => $customer->financial_checked_at?->toIso8601String()];
    }

    /** Local demonstration only. Production confirmations must use a verified provider adapter. */
    public function confirmPayment(Invoice $invoice, User $actor, string $reference, int $amountCents, ?string $paidAt = null): Invoice
    {
        if (! config('plans.demo', false) || app()->environment('production')) {
            throw new DomainException('MANUAL_PAYMENT_DISABLED', 'Confirmação demonstrativa indisponível neste ambiente.', 403);
        }
        if (! $actor->active || ! in_array($actor->role, ['central_admin', 'finance'], true) || ! $actor->hasFranchise($invoice->originating_franchise_id)) {
            throw new DomainException('FORBIDDEN', 'Seu perfil não pode confirmar este pagamento.', 403);
        }
        if (trim($reference) === '' || $amountCents < 1) {
            throw new DomainException('PAYMENT_INVALID', 'Informe referência e valor válido.', 422);
        }
        $invoice = $this->applyVerifiedInvoice($invoice, ['status' => $amountCents >= $invoice->amount_cents ? 'paid' : 'partial',
            'paid_cents' => $amountCents, 'paid_at' => $paidAt ?? now()->toIso8601String(), 'reference' => 'demo:'.$reference,
            'source' => 'demo_finance', 'confirmed_by' => $actor->id, 'provider_status' => 'demo_confirmed', 'provider_updated_at' => now()->toIso8601String()]);
        Audit::record('demo_payment_confirmed', $invoice, $actor, ['amount_cents' => $amountCents], $reference);

        return $invoice;
    }

    /** Input is authoritative, already fetched and authenticated by a provider adapter. Never expose this method to raw browser payloads. */
    public function applyVerifiedInvoice(Invoice $invoice, array $data): Invoice
    {
        $result = DB::transaction(function () use ($invoice, $data) {
            $customer = Customer::lockForUpdate()->findOrFail($invoice->customer_id);
            $subscription = Subscription::findOrFail($invoice->subscription_id);
            Pet::lockForUpdate()->findOrFail($subscription->pet_id);
            $subscription = Subscription::lockForUpdate()->findOrFail($subscription->id);
            $current = Invoice::lockForUpdate()->findOrFail($invoice->id);
            $incoming = isset($data['provider_updated_at']) ? Carbon::parse($data['provider_updated_at'])->utc() : now();
            if ($current->provider_updated_at && $incoming->lt($current->provider_updated_at)) {
                return $current;
            }
            $paid = (int) ($data['paid_cents'] ?? 0);
            $status = $data['status'] ?? 'pending';
            if (! in_array($status, ['pending', 'partial', 'paid', 'cancelled', 'refunded', 'disputed'], true) || $paid < 0 || $paid > $current->amount_cents) {
                throw new DomainException('FINANCIAL_DATA_INVALID', 'Dados financeiros exigem conciliação.', 422);
            }
            if ($status === 'paid' && $paid < $current->amount_cents) {
                throw new DomainException('PAYMENT_NOT_FULL', 'O pagamento informado não quita a fatura.', 422);
            }
            if ($current->status === 'paid' && in_array($status, ['pending', 'partial'], true)) {
                return $current;
            }
            $reference = $data['reference'] ?? null;
            if ($paid > 0 && ! $reference) {
                throw new DomainException('PAYMENT_REFERENCE_REQUIRED', 'A confirmação exige referência financeira verificável.', 422);
            }
            if ($paid > 0) {
                $existing = Payment::where('provider_account', $current->provider_account)->where('external_transaction_id', $reference)->first();
                if ($existing && $existing->invoice_id !== $current->id) {
                    throw new DomainException('PAYMENT_CONFLICT', 'Esta transação já está vinculada a outra cobrança.', 409);
                }
                if ($existing && $paid !== $current->paid_cents) {
                    throw new DomainException('PAYMENT_CONFLICT', 'O valor desta confirmação já registrada diverge. Concilie a transação.', 409);
                }
                if (! $existing && $paid > $current->paid_cents) {
                    Payment::create(['invoice_id' => $current->id, 'provider_account' => $current->provider_account, 'external_transaction_id' => $reference, 'amount_cents' => $paid - $current->paid_cents, 'paid_at' => $data['paid_at'] ?? now(), 'source' => $data['source'] ?? 'provider_verified', 'confirmed_by' => $data['confirmed_by'] ?? null]);
                }
            }
            $current->update(['status' => $status, 'paid_cents' => $paid, 'paid_at' => $data['paid_at'] ?? null,
                'confirmed_at' => now(), 'provider_status' => $data['provider_status'] ?? $status, 'provider_updated_at' => $incoming,
                'reconciliation_status' => 'verified', 'payment_reference' => $reference]);
            $customer->update(['financial_verification_status' => 'verified', 'financial_checked_at' => now()]);
            $subscription->update(['financial_verification_status' => 'verified', 'financial_checked_at' => now()]);
            Audit::record('invoice_reconciled', $current, null, ['status' => $status, 'paid_cents' => $paid, 'provider_status' => $data['provider_status'] ?? $status]);
            $current = $current->fresh();
            app(CommissionService::class)->syncForInvoice($current);

            return $current;
        }, 3);
        foreach (Subscription::where('customer_id', $invoice->customer_id)->whereNotIn('status', Subscription::TERMINAL)->get() as $subscription) {
            app(ActivationService::class)->activate($subscription);
        }

        return $result;
    }
}
