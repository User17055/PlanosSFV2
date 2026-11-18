<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Cargos que já viam a visão geral continuam vendo os planos gerais; só um cargo novo pode ficar com os planos novos apenas (ex.: Marketing). */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('job_roles')->get() as $role) {
            $permissions = json_decode($role->permissions, true) ?: [];
            if (in_array('dashboard.view', $permissions, true) && ! in_array('dashboard.general', $permissions, true)) {
                DB::table('job_roles')->where('id', $role->id)->update(['permissions' => json_encode([...$permissions, 'dashboard.general'])]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('job_roles')->get() as $role) {
            $permissions = array_values(array_diff(json_decode($role->permissions, true) ?: [], ['dashboard.general']));
            DB::table('job_roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
        }
    }
};
