<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Cargos de gestão (como o ADMINISTRADOR) já nascem podendo trocar quem fez a venda; dá para tirar no cargo. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('job_roles')->where('base_role', 'manager')->get() as $role) {
            $permissions = json_decode($role->permissions, true) ?: [];
            if (! in_array('proposals.seller', $permissions, true)) {
                DB::table('job_roles')->where('id', $role->id)->update(['permissions' => json_encode([...$permissions, 'proposals.seller'])]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('job_roles')->get() as $role) {
            $permissions = array_values(array_diff(json_decode($role->permissions, true) ?: [], ['proposals.seller']));
            DB::table('job_roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
        }
    }
};
