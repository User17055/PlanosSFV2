<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\AccessService;
use App\Services\Domain\FinancialService;
use Illuminate\Http\Request;

class FinanceController extends Controller
{
    public function index(Request $request,AccessService $access)
    {
        $query=$access->scope(Invoice::query(),$request->user());
        $stats=[
            'billed_cents'=>(clone $query)->sum('amount_cents'),
            'received_cents'=>(clone $query)->sum('paid_cents'),
            'overdue_cents'=>(clone $query)->whereNotIn('status',['cancelled','paid','refunded'])->where('due_at','<',now())->selectRaw('COALESCE(SUM(amount_cents - paid_cents),0) as total')->value('total'),
            'pending_cents'=>(clone $query)->where('status','pending')->selectRaw('COALESCE(SUM(amount_cents - paid_cents),0) as total')->value('total'),
        ];
        if ($request->input('status')==='overdue') $query->whereNotIn('status',['cancelled','paid','refunded'])->where('due_at','<',now())->whereColumn('paid_cents','<','amount_cents');
        elseif ($request->filled('status')) $query->where('status',$request->string('status')->toString());
        if ($request->filled('customer_id')) $query->where('customer_id',$request->integer('customer_id'));
        $invoices=$query->with(['customer','subscription.pet','subscription.productVersion','originatingFranchise'])->orderByDesc('due_at')->paginate(15)->withQueryString();
        return view('finance.index',compact('invoices','stats'));
    }

    public function confirm(Request $request,Invoice $invoice,AccessService $access,FinancialService $financial)
    {
        $access->record($request->user(),$invoice);
        $data=$request->validate(['reference'=>'required|string|min:5|max:120','amount_cents'=>'required|integer|min:1']);
        $financial->confirmPayment($invoice,$request->user(),$data['reference'],(int)$data['amount_cents']);
        return back()->with('success','Pagamento de demonstração conciliado. Elegibilidade recalculada.');
    }
}
