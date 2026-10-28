<?php

namespace App\Http\Controllers;

use App\Models\{AuditLog, Customer, Franchise, Pet};
use App\Services\AccessService;
use App\Services\Domain\{EligibilityService, FinancialService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    public function __construct(private AccessService $access) {}

    public function index(Request $request, FinancialService $financial)
    {
        $query = $this->access->customerQuery($request->user())->with('pets')->withCount(['pets','subscriptions']);
        if ($search = $request->string('q')->toString()) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('document', 'like', '%'.$search.'%')->orWhere('email','like','%'.$search.'%'));
        }
        $customers = $query->orderBy('name')->paginate(15)->withQueryString();
        $financialStates = $customers->getCollection()->mapWithKeys(fn ($c) => [$c->id => $financial->customerStatus($c)])->all();
        return view('customers.index', compact('customers','financialStates'));
    }

    public function create(Request $request)
    {
        $franchises = Franchise::where('active', true)->when($request->user()->role !== 'central_admin', fn ($q) => $q->whereIn('id', $this->access->franchiseIds($request->user())))->get();
        return view('customers.create', compact('franchises'));
    }

    public function store(Request $request)
    {
        $request->merge(['document' => preg_replace('/\D/', '', $request->input('document', ''))]);
        $data = $this->validateWorkspace($request,[
            'name' => 'required|string|min:3|max:180', 'document' => 'required|digits:11|unique:customers,document',
            'email' => 'required|email|max:180','phone' => 'required|string|max:30','address' => 'nullable|string|max:500',
            'originating_franchise_id' => 'nullable|integer|exists:franchises,id',
            'pet_name' => 'required|string|max:100','pet_species' => 'required|in:dog,cat',
            'pet_birth_date' => 'required|date|before_or_equal:today',
            'pet_color' => 'nullable|string|max:100','rg'=>'nullable|string|max:40','city'=>'nullable|string|max:150','neighborhood'=>'nullable|string|max:150','state'=>'nullable|string|size:2','pet_breed' => 'nullable|string|max:100','pet_sex' => 'nullable|in:male,female,unknown',
            'pet_microchip'=>'nullable|string|max:100|unique:pets,microchip',
        ] + \App\Services\CustomerAddress::rules() + \App\Services\Attribution\CustomerAcquisition::rules(),'customerCreate',route('customers.create'),[
            'document.unique'=>'Já existe um tutor com este CPF. Abra a ficha existente para adicionar ou atualizar os pets.',
            'pet_name.required'=>'Informe o nome do primeiro pet.',
            'pet_birth_date.required'=>'Informe a data de nascimento do primeiro pet.',
            'pet_microchip.unique'=>'Este microchip já está vinculado a um pet cadastrado.',
        ]);
        try { $this->validateCpf($data['document']); }
        catch (ValidationException $e) { throw $e->errorBag('customerCreate')->redirectTo(route('customers.create')); }
        $franchiseId = $data['originating_franchise_id'] ?? $request->user()->franchise_id;
        if (! $franchiseId && $request->user()->role === 'central_admin') $franchiseId = Franchise::where('code','CENTRAL')->value('id');
        abort_unless($franchiseId, 422, 'Selecione a unidade de origem.');
        $this->access->franchise($request->user(), (int) $franchiseId);
        $customer = DB::transaction(function () use ($data, $request, $franchiseId) {
            $customer = Customer::create([
                'name'=>$data['name'],'rg'=>$data['rg']??null,'document'=>$data['document'],'email'=>$data['email'],'phone'=>$data['phone'],
                'address'=>\App\Services\CustomerAddress::from($data),'originating_franchise_id'=>$franchiseId,
            ]);
            app(\App\Services\Attribution\CustomerAcquisition::class)->record($customer,$request->user(),$request,$data);
            $pet=Pet::create(['customer_id'=>$customer->id,'name'=>$data['pet_name'],'species'=>$data['pet_species'],'birth_date'=>$data['pet_birth_date'],'color'=>$data['pet_color']??null,'breed'=>$data['pet_breed'] ?? null,'sex'=>$data['pet_sex'] ?? 'unknown','microchip'=>$data['pet_microchip']??null]);
            AuditLog::create(['actor_id'=>$request->user()->id,'franchise_id'=>$franchiseId,'action'=>'customer.created','entity_type'=>'customer','entity_id'=>$customer->id]);
            AuditLog::create(['actor_id'=>$request->user()->id,'franchise_id'=>$franchiseId,'action'=>'pet.created','entity_type'=>'pet','entity_id'=>$pet->id,'data'=>['customer_id'=>$customer->id]]);
            return $customer;
        });
        return redirect()->to(route('customers.show',$customer).'#pets')->with('success','Tutor e primeiro pet cadastrados. Continue nesta ficha para atualizar os dados, adicionar pets ou contratar um plano.');
    }

    public function show(Request $request, Customer $customer, FinancialService $financial, EligibilityService $eligibility)
    {
        $this->access->customer($request->user(),$customer);
        $ids = $this->access->franchiseIds($request->user());
        $restrict = fn ($q) => $request->user()->role === 'central_admin' ? $q : $q->whereIn('originating_franchise_id',$ids);
        $customer->load(['pets'=>fn($q)=>$q->orderBy('name'),'subscriptions'=>fn ($q)=>$restrict($q)->with(['productVersion.product','productVersion.benefitRules','contractDocuments','documents','pet','invoices','cancellationRequest','originatingFranchise']), 'proposals'=>fn ($q)=>$restrict($q)->with(['productVersion.product','pet'])->latest(), 'invoices'=>$restrict]);
        $financialState = $financial->customerStatus($customer);
        if($request->user()->role!=='central_admin') $financialState['overdue_cents']=null;
        if(!in_array($request->user()->role,['central_admin','manager','finance'],true)) $customer->setRelation('invoices',collect());
        $eligibilities=[];
        foreach($customer->subscriptions as $subscription) {
            foreach($subscription->productVersion->benefitRules as $rule) $eligibilities[$subscription->id][$rule->id]=$eligibility->benefit($subscription,$rule,$request->user()->franchise_id);
        }
        return view('customers.show',compact('customer','financialState','eligibilities'));
    }

    public function pet(Request $request, Customer $customer)
    {
        $this->access->customer($request->user(), $customer);
        $data = $this->validateWorkspace($request,['name'=>'required|string|max:100','species'=>'required|in:dog,cat','birth_date'=>'required|date|before_or_equal:today','color'=>'nullable|string|max:100','breed'=>'nullable|string|max:100','sex'=>'nullable|in:male,female,unknown','microchip'=>'nullable|string|max:100|unique:pets,microchip'],
            $request->user()->role==='customer'?'default':'petCreate',$this->workspaceUrl($request,$customer,'novo-pet'),['microchip.unique'=>'Este microchip já está vinculado a um pet cadastrado.']);
        $pet=DB::transaction(function()use($request,$customer,$data) {
            $locked=Customer::lockForUpdate()->findOrFail($customer->id);
            $pet=$locked->pets()->create($data);
            AuditLog::create(['actor_id'=>$request->user()->id,'franchise_id'=>$locked->originating_franchise_id,'action'=>'pet.created','entity_type'=>'pet','entity_id'=>$pet->id,'data'=>['customer_id'=>$locked->id]]);
            return $pet;
        });
        return redirect()->to($this->workspaceUrl($request,$customer,'pet-'.$pet->id))->with('success','Pet adicionado à ficha deste tutor.');
    }

    public function edit(Request $request,Customer $customer)
    {
        $this->access->customer($request->user(),$customer);
        if ($request->user()->role==='customer') return view('customers.edit',compact('customer'));
        return redirect()->to(route('customers.show',['customer'=>$customer,'edit'=>'tutor']).'#tutor');
    }

    public function update(Request $request,Customer $customer)
    {
        $this->access->customer($request->user(),$customer);
        $data=$this->validateWorkspace($request,['name'=>'required|string|min:3|max:180','phone'=>'required|string|max:30','address'=>'nullable|required_without:street|string|max:500','rg'=>'nullable|string|max:40','city'=>'nullable|string|max:150','neighborhood'=>'nullable|string|max:150','state'=>'nullable|string|size:2','reason'=>'required|string|min:5|max:1000'] + \App\Services\CustomerAddress::rules() + \App\Services\Attribution\CustomerAcquisition::rules(),
            $request->user()->role==='customer'?'default':'customerEdit',$request->user()->role==='customer'?route('customers.edit',$customer):$this->workspaceUrl($request,$customer,'tutor'));
        DB::transaction(function()use($request,$customer,$data) {
            $locked=Customer::lockForUpdate()->findOrFail($customer->id);
            $before=$locked->only(['name','phone','address','rg','acquisition_profile']);
            $locked->update(['name'=>$data['name'],'phone'=>$data['phone'],'rg'=>$data['rg']??$locked->rg,'address'=>\App\Services\CustomerAddress::from($data,is_array($locked->address)?$locked->address:[])]);
            if(array_intersect_key($data,\App\Services\Attribution\CustomerAcquisition::rules()))app(\App\Services\Attribution\CustomerAcquisition::class)->record($locked,$request->user(),$request,$data);
            AuditLog::create(['actor_id'=>$request->user()->id,'franchise_id'=>$locked->originating_franchise_id,'action'=>'customer.updated','entity_type'=>'customer','entity_id'=>$customer->id,'reason'=>$data['reason'],'data'=>['before'=>$before,'after'=>$locked->only(['name','phone','address','rg','acquisition_profile'])]]);
        });
        return redirect()->to($this->workspaceUrl($request,$customer,'tutor'))->with('success','Dados do tutor atualizados nesta ficha. Os contratos já aceitos preservam os dados originais.');
    }

    public function editPet(Request $request,Pet $pet)
    {
        $this->access->customer($request->user(),$pet->customer);
        if ($request->user()->role==='customer') return view('pets.edit',compact('pet'));
        return redirect()->to(route('customers.show',['customer'=>$pet->customer_id,'edit'=>'pet-'.$pet->id]).'#pet-'.$pet->id);
    }

    public function updatePet(Request $request,Pet $pet)
    {
        $this->access->customer($request->user(),$pet->customer);
        $data=$this->validateWorkspace($request,['name'=>'required|string|max:100','color'=>'nullable|string|max:100','breed'=>'nullable|string|max:100','sex'=>'required|in:male,female,unknown','birth_date'=>'required|date|before_or_equal:today','reason'=>'required|string|min:5|max:1000'],
            $request->user()->role==='customer'?'default':'petEdit'.$pet->id,$request->user()->role==='customer'?route('pets.edit',$pet):$this->workspaceUrl($request,$pet->customer,'pet-'.$pet->id));
        DB::transaction(function()use($request,$pet,$data) {
            Customer::lockForUpdate()->findOrFail($pet->customer_id);
            $locked=Pet::lockForUpdate()->findOrFail($pet->id);
            $before=$locked->only(['name','breed','sex','birth_date','color']);
            $locked->update(collect($data)->except('reason')->all());
            AuditLog::create(['actor_id'=>$request->user()->id,'franchise_id'=>$pet->customer->originating_franchise_id,'action'=>'pet.updated','entity_type'=>'pet','entity_id'=>$pet->id,'reason'=>$data['reason'],'data'=>['before'=>$before,'after'=>$locked->only(['name','breed','sex','birth_date','color'])]]);
        });
        return redirect()->to($this->workspaceUrl($request,$pet->customer,'pet-'.$pet->id))->with('success','Dados do pet atualizados na ficha. Os contratos existentes preservam os dados do aceite.');
    }

    private function workspaceUrl(Request $request,Customer $customer,string $anchor): string
    {
        return $request->user()->role === 'customer'
            ? route('portal')
            : route('customers.show',$customer).'#'.$anchor;
    }

    private function validateWorkspace(Request $request,array $rules,string $bag,string $redirect,array $messages=[]): array
    {
        $validator=Validator::make($request->all(),$rules,$messages);
        if ($validator->fails()) throw (new ValidationException($validator))->errorBag($bag)->redirectTo($redirect);
        return $validator->validated();
    }

    public function validateCpf(string $cpf): void
    {
        $valid = strlen($cpf) === 11 && ! preg_match('/^(\d)\1{10}$/', $cpf);
        for ($t = 9; $valid && $t < 11; $t++) {
            $sum = 0; for ($c = 0; $c < $t; $c++) $sum += ((int)$cpf[$c]) * (($t + 1) - $c);
            $valid = ((10 * $sum) % 11) % 10 === (int)$cpf[$t];
        }
        if (! $valid) throw \Illuminate\Validation\ValidationException::withMessages(['document'=>'Informe um CPF válido.']);
    }
}
