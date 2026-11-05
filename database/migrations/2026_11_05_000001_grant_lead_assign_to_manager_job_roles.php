<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Quem é da gestão da unidade e já mexe nos leads passa a poder escolher o responsável, sem precisar marcar a opção em cada cargo. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('job_roles')->where('base_role', 'manager')->get() as $role) {
            $permissions = json_decode($role->permissions, true) ?: [];
            if (in_array('leads.update', $permissions, true) && ! in_array('leads.assign', $permissions, true)) {
                DB::table('job_roles')->where('id', $role->id)->update(['permissions' => json_encode([...$permissions, 'leads.assign'])]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('job_roles')->where('base_role', 'manager')->get() as $role) {
            $permissions = array_values(array_diff(json_decode($role->permissions, true) ?: [], ['leads.assign']));
            DB::table('job_roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
        }
    }
};
