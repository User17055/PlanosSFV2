<?php

namespace App\Http\Controllers;

use App\Models\{AuditLog, DigisacConnection, Franchise, JobRole, User};
use App\Services\Domain\DomainException;
use App\Services\Integrations\DigisacClient;
use Illuminate\Support\Facades\Cache;
use App\Services\Reports\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeController extends Controller
{
    public const ROLES=['sales'=>'Vendas','manager'=>'Gestão da unidade','operator'=>'Recepção','finance'=>'Financeiro','attendant'=>'Atendimento (só leads)','central_admin'=>'Administração central'];

    public function index(Request $request,DashboardService $dashboard)
    {
        $request->validate(['q'=>'nullable|string|max:150','active'=>'nullable|in:0,1']);
        $query=$dashboard->employees($request->user())->with(['franchises','jobRole']);
        if($request->filled('q')) $query->where(fn($q)=>$q->where('name','like','%'.$request->input('q').'%')->orWhere('email','like','%'.$request->input('q').'%')->orWhere('employee_code','like','%'.$request->input('q').'%'));
        if($request->filled('active')) $query->where('active',$request->boolean('active'));
        $employees=$query->orderBy('name')->paginate(12)->withQueryString();
        $filters=$dashboard->filters($request->user(),[]);
        $from=\Carbon\CarbonImmutable::now('America/Sao_Paulo')->startOfMonth()->utc();
        $until=\Carbon\CarbonImmutable::now('America/Sao_Paulo')->addDay()->startOfDay()->utc();
        $results=[];
        foreach($employees as $employee) {
            $personal=array_merge($filters,['employee_id'=>$employee->id]);
            $sold=$dashboard->proposals($request->user(),$personal)->where('accepted_at','>=',$from)->where('accepted_at','<',$until);
            $commissions=app(\App\Services\Domain\CommissionService::class)->query($request->user())->where('beneficiary_user_id',$employee->id)->where('is_demo',false)->whereHas('productVersion',fn($q)=>$q->where('is_demo',false)->where('status','!=','local_preview'));
            $results[$employee->id]=['sales'=>(clone $sold)->count(),'sold_cents'=>(int)(clone $sold)->sum('price_cents'),
                'payable_cents'=>(int)(clone $commissions)->where('status','payable')->sum('amount_cents'),
                'paid_cents'=>(int)(clone $commissions)->whereNotNull('paid_at')->where('paid_at','>=',$from)->where('paid_at','<',$until)->sum('amount_cents')];
        }
        return response()->view('employees.index',compact('employees','results')+['roles'=>self::ROLES,'canManage'=>$request->user()->role==='central_admin'])->header('Cache-Control','private, no-store');
    }

    public function create()
    {
        return view('employees.form',['employee'=>new User(['role'=>'sales','active'=>true]),'roles'=>self::ROLES,'jobRoles'=>JobRole::orderBy('name')->get(),'franchises'=>Franchise::where('active',true)->orderBy('name')->get(),'digisacUsers'=>$this->digisacUsers()]);
    }

    public function edit(User $employee)
    {
        abort_if($employee->role==='customer',404);
        return view('employees.form',['employee'=>$employee->load('franchises'),'roles'=>self::ROLES,'jobRoles'=>JobRole::orderBy('name')->get(),'franchises'=>Franchise::where('active',true)->orderBy('name')->get(),'digisacUsers'=>$this->digisacUsers()]);
    }

    /** Usuários do Digisac, lidos com a conexão do administrador; sem ela ou com o Digisac fora do ar, a lista fica vazia. */
    private function digisacUsers(): array
    {
        $admin=DigisacConnection::admin();
        if(!$admin) return [];
        try { return Cache::remember('digisac-users-'.$admin->id,600,fn()=>app(DigisacClient::class)->users($admin)); }
        catch(DomainException) { return []; }
    }

    public function store(Request $request) { return $this->save($request,new User); }
    public function update(Request $request,User $employee)
    {
        abort_if($employee->role==='customer',404);
        return $this->save($request,$employee);
    }

    private function save(Request $request,User $employee)
    {
        $data=$request->validate([
            'name'=>'required|string|min:3|max:180','email'=>['required','email','max:180',Rule::unique('users')->ignore($employee->id)],
            'password'=>($employee->exists?'nullable':'required').'|string|min:12|confirmed',
            'employee_code'=>['nullable','string','max:40',Rule::unique('users')->ignore($employee->id)],
            'phone'=>'nullable|string|max:30','job_title'=>'nullable|string|max:100','hired_at'=>'nullable|date|before_or_equal:today',
            'monthly_sales_target'=>'nullable|decimal:0,2|min:0|max:10000000',
            'active'=>'required|boolean','role'=>['required',Rule::in(array_keys(self::ROLES))],
            'franchise_ids'=>'required|array|min:1','franchise_ids.*'=>['required','integer','distinct',Rule::exists('franchises','id')->where('active',true)],
            'areas'=>'nullable|array','areas.*'=>['string',Rule::in(array_keys(User::AREAS))],
            'job_role_id'=>['nullable','integer',Rule::exists('job_roles','id')],
            'digisac_user_id'=>['nullable','string','max:100',Rule::unique('users')->ignore($employee->id)],
        ]);
        // Com cargo, o perfil é o nível do cargo e as telas são as dele.
        $jobRole=empty($data['job_role_id'])?null:JobRole::find($data['job_role_id']);
        $data['job_role_id']=$jobRole?->id;
        if($jobRole) $data['role']=$jobRole->base_role;
        // Telas liberadas: só as que o perfil permite. Tudo marcado vira null (o perfil inteiro, inclusive telas que vierem depois).
        // Quem edita a si mesmo não vê a lista (o campo areas_shown não vem) e fica como está.
        $self=$employee->id===$request->user()->id;
        $roleAreas=User::areasFor($data['role']);
        $picked=$request->has('areas_shown') && !$self && !$jobRole;
        $areas=$jobRole ? null : ($picked ? array_values(array_intersect($roleAreas,$data['areas']??[])) : $employee->areas);
        if($picked && $areas===[]) throw ValidationException::withMessages(['areas'=>'Marque pelo menos uma tela.']);
        if($areas===$roleAreas) $areas=null;
        if($self) abort_unless($data['role']===$employee->role && $data['job_role_id']===$employee->job_role_id && (bool)$data['active']===$employee->active && collect($data['franchise_ids'])->map(fn($id)=>(int)$id)->sort()->values()->all()===$employee->franchises()->wherePivot('active',true)->pluck('franchises.id')->sort()->values()->all(),422,'Outro administrador deve alterar seu perfil, situação ou unidades.');
        $created=!$employee->exists;
        DB::transaction(function()use($request,$employee,$data,$created,$areas){
            $attributes=collect($data)->except(['franchise_ids','monthly_sales_target','password','password_confirmation','areas'])->all()+['areas'=>$areas];
            if(!empty($data['password'])) $attributes['password']=$data['password'];
            $attributes['monthly_sales_target_cents']=(int)round(((float)($data['monthly_sales_target']??0))*100);
            $attributes['franchise_id']=$data['franchise_ids'][0];
            $employee->fill($attributes)->save();
            $employee->franchises()->sync(collect($data['franchise_ids'])->mapWithKeys(fn($id)=>[$id=>['active'=>(bool)$data['active']]])->all());
            AuditLog::create(['actor_id'=>$request->user()->id,'action'=>$created?'employee.created':'employee.updated','entity_type'=>'user','entity_id'=>$employee->id,'data'=>collect($attributes)->except('password')->all()]);
        });
        return redirect()->route('employees.index')->with('success',$created?'Funcionário cadastrado com acesso individual.':'Cadastro do funcionário atualizado.');
    }
}
