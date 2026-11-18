<?php

namespace App\Services\Reports;

use App\Models\{Lead,User};
use App\Services\AccessService;

/** Notificações em vermelho da equipe: leads disponíveis (número no menu Leads) e mensagem de cliente não lida (bolinha do botão do chat). */
class StaffCounts
{
    public function for(User $user): array
    {
        if (! in_array($user->role, ['central_admin', 'manager', 'sales', 'operator', 'attendant'], true)) return [];
        return [
            // Os mesmos da aba Disponíveis do chat: sem ninguém atendendo, nas unidades da pessoa.
            'leads_available' => app(AccessService::class)->scope(Lead::visibleTo($user)->whereNull('handled_by'), $user)->count(),
            // Cliente de um lead da pessoa mandou mensagem que ninguém leu.
            'chat_unread' => Lead::where('handled_by', $user->id)->chatUnread()->count(),
        ];
    }
}
