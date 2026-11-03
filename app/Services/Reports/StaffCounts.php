<?php

namespace App\Services\Reports;

use App\Models\{Invoice,Lead,Proposal,User};
use App\Services\AccessService;
use Illuminate\Support\Facades\Cache;

/** Números dos atalhos do menu da equipe. Falta pagar e em atraso ficam guardados por 1 minuto para não pesar a cada tela; lead novo é contado na hora, senão o menu fica atrás da lista ao vivo. */
class StaffCounts
{
    public function for(User $user): array
    {
        $access = app(AccessService::class);
        $leads = in_array($user->role, ['central_admin', 'manager', 'sales', 'operator', 'attendant'], true)
            ? ['leads_new' => $access->scope(Lead::where('status', 'new'), $user)->count()] : [];
        return $leads + Cache::remember('staff-counts:'.$user->id, 60, function () use ($user, $access) {
            $out = [];
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
