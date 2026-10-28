<?php

namespace App\Http\Controllers;

use App\Models\{AuditLog, ContractDocument, Proposal};
use App\Services\AccessService;
use App\Services\Contracts\ContractService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ContractController extends Controller
{
    public function download(Request $request,ContractDocument $document,AccessService $access,ContractService $contracts)
    {
        $access->record($request->user(),$document->subscription);
        abort_unless($contracts->verifyIntegrity($document),409,'Falha na verificação de integridade do arquivo.');
        AuditLog::create(['actor_id'=>$request->user()->id,'action'=>'contract.download','entity_type'=>'contract_document','entity_id'=>$document->id]);
        return Storage::disk(config('integrations.contracts.disk','local'))->download($document->storage_path,'contrato-'.$document->subscription->number.'-v'.$document->version.'.pdf',['Content-Type'=>'application/pdf','Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }

    public function proposal(Request $request,Proposal $proposal,AccessService $access)
    {
        $access->record($request->user(),$proposal);
        $query=$proposal->documents();
        if($request->routeIs('proposals.paper-download')) $query->where('kind','paper_draft');
        else $query->orderByRaw("CASE WHEN kind='final_contract' AND status='valid' THEN 0 ELSE 1 END");
        $document=$query->latest('version')->firstOrFail();
        return redirect()->route('contracts.download',$document);
    }

    public function upload(Request $request,Proposal $proposal,AccessService $access,ContractService $contracts)
    {
        $access->record($request->user(),$proposal);
        $request->validate(['document'=>'required|file|mimetypes:application/pdf|max:10240','signed_at'=>'required|date|before_or_equal:now']);
        abort_unless($proposal->signature_method==='paper' && $proposal->subscription,422,'Emita a contratação em papel antes de anexar o documento.');
        $contracts->uploadPaper($proposal->subscription,$request->file('document'),$request->user()->id,['signed_at'=>\Carbon\CarbonImmutable::parse($request->input('signed_at'),'America/Sao_Paulo')->utc()->toIso8601String()]);
        return back()->with('success','Documento recebido e aguardando conferência.');
    }

    public function validateDocument(Request $request,ContractDocument $document,AccessService $access,ContractService $contracts)
    {
        $access->record($request->user(),$document->subscription);
        $data=$request->validate(['approved'=>'required|boolean','reason'=>'required_if:approved,0|nullable|string|min:5|max:1000']);
        $contracts->validatePaper($document,(bool)$data['approved'],$request->user()->id,$data['reason']??null);
        return back()->with('success',$data['approved']?'Documento validado.':'Documento rejeitado com motivo registrado.');
    }
}
