<?php

namespace App\Http\Controllers;

use App\Models\{AuditLog, JobRole};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class JobRoleController extends Controller
{
    public function index()
    {
        return view('job-roles.index', ['jobRoles' => JobRole::withCount('users')->orderBy('name')->get(), 'levels' => JobRole::levels()]);
    }

    public function create()
    {
        return view('job-roles.form', ['jobRole' => new JobRole(['base_role' => 'sales', 'permissions' => []]), 'levels' => JobRole::levels()]);
    }

    public function edit(JobRole $jobRole)
    {
        return view('job-roles.form', ['jobRole' => $jobRole, 'levels' => JobRole::levels()]);
    }

    public function store(Request $request)
    {
        return $this->save($request, new JobRole);
    }

    public function update(Request $request, JobRole $jobRole)
    {
        return $this->save($request, $jobRole);
    }

    public function destroy(Request $request, JobRole $jobRole)
    {
        abort_if($jobRole->users()->exists(), 422, 'Troque o cargo dos funcionários antes de excluir.');
        DB::transaction(function () use ($request, $jobRole) {
            AuditLog::create(['actor_id' => $request->user()->id, 'action' => 'job_role.deleted', 'entity_type' => 'job_role', 'entity_id' => $jobRole->id, 'data' => $jobRole->only(['name', 'base_role', 'permissions'])]);
            $jobRole->delete();
        });
        return redirect()->route('employees.roles.index')->with('success', 'Cargo excluído.');
    }

    private function save(Request $request, JobRole $jobRole)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80', Rule::unique('job_roles')->ignore($jobRole->id)],
            'base_role' => ['required', Rule::in(array_keys(JobRole::levels()))],
            'permissions' => 'nullable|array', 'permissions.*' => ['string', Rule::in(array_keys(JobRole::OPTIONS))],
        ]);
        $data['permissions'] = JobRole::clean($data['permissions'] ?? [], $data['base_role']);
        if ($data['permissions'] === []) throw ValidationException::withMessages(['permissions' => 'Marque pelo menos uma opção.']);
        $created = ! $jobRole->exists;
        DB::transaction(function () use ($request, $jobRole, $data, $created) {
            $jobRole->fill($data)->save();
            // O perfil de quem já tem o cargo acompanha o nível, para as demais regras do sistema continuarem certas.
            $jobRole->users()->update(['role' => $data['base_role']]);
            AuditLog::create(['actor_id' => $request->user()->id, 'action' => $created ? 'job_role.created' : 'job_role.updated', 'entity_type' => 'job_role', 'entity_id' => $jobRole->id, 'data' => $data]);
        });
        return redirect()->route('employees.roles.index')->with('success', $created ? 'Cargo criado.' : 'Cargo atualizado. A mudança vale na próxima tela que a equipe abrir.');
    }
}
