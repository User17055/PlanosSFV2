<?php

namespace App\Services\Domain;

use App\Models\ProductVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CatalogService
{
    public function publish(ProductVersion $version, User $actor): ProductVersion
    {
        if (! $actor->active || ! in_array($actor->role, ['central_admin', 'manager'], true)) {
            throw new DomainException('FORBIDDEN', 'Seu perfil não pode publicar produtos.', 403);
        }

        return DB::transaction(function () use ($version, $actor) {
            $v = ProductVersion::lockForUpdate()->findOrFail($version->id);
            if ($v->published_at) {
                throw new DomainException('PUBLISHED_VERSION_IMMUTABLE', 'Esta versão já foi publicada. Crie outra versão para alterar as condições.');
            }
            if ($v->price_cents < 1 || ! $v->contract_template || ! in_array($v->product->category, ['health_plan', 'package'], true) || ! count($v->species ?? []) || ! in_array($v->billing_cycle, ['monthly', 'annual', 'one_time'], true)) {
                throw new DomainException('CATALOG_INCOMPLETE', 'Preço, categoria, espécies, cobrança e contrato são obrigatórios.', 422);
            }
            if ($v->product->category === 'package' && (! $v->duration_months || $v->billing_cycle !== 'one_time' || ! $v->renewal_policy)) {
                throw new DomainException('PACKAGE_POLICY_PENDING', 'Defina validade, cobrança única e renovação do Pacote.', 422);
            }
            if ($v->benefitRules()->count() === 0) {
                throw new DomainException('BENEFITS_REQUIRED', 'Cadastre os benefícios antes de publicar.', 422);
            }
            app(\App\Services\Contracts\ContractTemplate::class)->validate($v->contract_template);
            foreach ($v->benefitRules as $rule) {
                if (! in_array($rule->period_type, ['calendar_month', 'monthly_cycle', 'contract_year', 'lifetime'], true) || ($rule->quantity_limit !== null && $rule->quantity_limit < 1)) {
                    throw new DomainException('BENEFIT_RULE_INVALID', 'Regra de limite ou renovação não implementada.', 422);
                }
                if ($rule->coverage_type !== 'included' || $rule->copay_cents > 0 || $rule->requires_authorization) {
                    throw new DomainException('BENEFIT_FLOW_PENDING', 'Desconto, coparticipação e autorização adicional exigem fluxo aprovado antes de publicar.', 422);
                }
            }
            $v->update(['published_at' => now(), 'published_by' => $actor->id, 'status' => 'published']);
            Audit::record('catalog_published', $v, $actor, ['version' => $v->version]);

            return $v;
        }, 3);
    }
}
