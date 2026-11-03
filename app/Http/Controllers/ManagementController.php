<?php

namespace App\Http\Controllers;

use App\Models\{AuditLog, Franchise, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ManagementController extends Controller
{
    public function index()
    {
        return view('management.index',['franchises'=>Franchise::orderBy('name')->get(),'users'=>User::with('franchises')->where('role','!=','customer')->orderBy('name')->get()]);
    }

    public function franchise(Request $request)
    {
        $data=$request->validate(['name'=>'required|string|min:3|max:150','code'=>'required|string|max:30|alpha_dash|unique:franchises,code','city'=>'required|string|max:100','state'=>'required|string|size:2','address'=>'required|string|max:255']);
        $franchise=Franchise::create($data+['active'=>true]);
        AuditLog::create(['actor_id'=>$request->user()->id,'action'=>'franchise.created','entity_type'=>'franchise','entity_id'=>$franchise->id]);
        return back()->with('success','Franquia cadastrada.');
    }

    public function user(Request $request)
    {
        $data=$request->validate(['name'=>'required|string|min:3|max:180','email'=>'required|email|max:180|unique:users,email','password'=>'required|string|min:12','role'=>'required|in:central_admin,manager,sales,operator,finance,attendant','franchise_ids'=>'required|array|min:1','franchise_ids.*'=>'integer|exists:franchises,id']);
        DB::transaction(function() use($data,$request) {
            $user=User::create(collect($data)->only(['name','email','password','role'])->all()+['franchise_id'=>$data['franchise_ids'][0],'active'=>true]);
            $user->franchises()->sync(collect($data['franchise_ids'])->mapWithKeys(fn($id)=>[$id=>['active'=>true]])->all());
            AuditLog::create(['actor_id'=>$request->user()->id,'action'=>'user.created','entity_type'=>'user','entity_id'=>$user->id,'data'=>['role'=>$user->role,'franchise_ids'=>$data['franchise_ids']]]);
        });
        return back()->with('success','Acesso individual criado. Compartilhe a senha inicial por um canal seguro.');
    }

    public function updateUser(Request $request,User $user)
    {
        abort_if($user->id===$request->user()->id,422,'Peça a outro administrador para alterar seu próprio perfil.');
        abort_if($user->role==='customer',422,'Esta área gerencia os acessos da equipe.');
        $data=$request->validate(['active'=>'required|boolean','role'=>'required|in:central_admin,manager,sales,operator,finance,attendant','franchise_ids'=>'required|array|min:1','franchise_ids.*'=>'integer|exists:franchises,id']);
        DB::transaction(function() use($data,$user,$request) {
            // Trocar o perfil por aqui tira o cargo: o nível dele deixaria de valer.
            $user->update(['role'=>$data['role'],'job_role_id'=>$data['role']===$user->role?$user->job_role_id:null,'active'=>(bool)$data['active'],'franchise_id'=>$data['franchise_ids'][0]]);
            $user->franchises()->sync(collect($data['franchise_ids'])->mapWithKeys(fn($id)=>[$id=>['active'=>(bool)$data['active']]])->all());
            AuditLog::create(['actor_id'=>$request->user()->id,'action'=>'user.access_updated','entity_type'=>'user','entity_id'=>$user->id,'data'=>$data]);
        });
        return back()->with('success','Permissões atualizadas. A desativação será aplicada também às sessões em andamento.');
    }
}
