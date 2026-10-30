<?php

namespace App\Services\Reports;

use App\Models\{Invoice,Lead,Proposal,User};
use App\Services\AccessService;
use Illuminate\Support\Facades\Cache;

/** Números dos atalhos do menu da equipe (leads novos, falta pagar, em atraso). Guardados por 1 minuto para não pesar a cada tela. */
class StaffCounts
{
    public function for(User $user): array
    {
        return Cache::remember('staff-counts:'.$user->id, 60, function () use ($user) {
            $access = app(AccessService::class);
            $out = [];
            if (in_array($user->role, ['central_admin', 'manager', 'sales', 'operator', 'attendant'], true)) {
                $out['leads_new'] = $access->scope(Lead::where('status', 'new'), $user)->count();
            }
            if (in_array($user->role, ['central_admin', 'manager', 'sales'], true)) {
                $out['to_pay'] = $access->scope(Proposal::query(), $user)->inStage('pagar')->count();
            }
            if (in_array($user->role, ['central_admin', 'manager', 'finance'], true)) {
                $out['overdue'] = $access->scope(Invoice::whereColumn('paid_cents', '<', 'amount_cents')->where('due_at', '<', now())->whereNotIn('status', ['cancelled', 'refunded', 'paid']), $user)->count();
            }
            return $out;
        });
    }
}
