<?php

namespace App\Services\Reports;

use App\Models\{Lead,User};

/** Números do menu da equipe: só a bolinha do botão de chat (os atalhos do pé do menu saíram em 18/11). */
class StaffCounts
{
    public function for(User $user): array
    {
        return in_array($user->role, ['central_admin', 'manager', 'sales', 'operator', 'attendant'], true)
            // Bolinha do botão de chat: cliente de um lead da pessoa mandou mensagem que ninguém leu.
            ? ['chat_unread' => Lead::where('handled_by', $user->id)->chatUnread()->count()] : [];
    }
}
