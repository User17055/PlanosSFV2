<?php

namespace App\Http\Controllers;

use App\Models\{LegacyPendingItem, Pet};
use App\Services\Domain\{DomainException, LegacyImport};
use Illuminate\Http\Request;

/** "Pets para escolher": itens da base antiga na Vindi que ainda não têm pet. A equipe escolhe o pet e o contrato nasce ligado à Vindi. */
class LegacyPendingController extends Controller
{
    public function index()
    {
        $items = LegacyPendingItem::whereNull('resolved_at')->with(['customer:id,name,document', 'customer.pets' => fn ($q) => $q->where('active', true)->orderBy('name'),
            'productVersion:id,name,product_id', 'productVersion.product:id,category'])->orderBy('customer_id')->orderBy('id')->get();
        return view('legacy.pending', ['groups' => $items->groupBy('customer_id'), 'total' => $items->count(),
            'done' => LegacyPendingItem::whereNotNull('resolved_at')->count()]);
    }

    public function link(Request $request, LegacyPendingItem $item, LegacyImport $import)
    {
        $data = $request->validate(['pet_id' => 'required|integer|exists:pets,id']);
        try {
            $import->link($item, Pet::findOrFail($data['pet_id']), $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
        return back()->with('success', 'Plano ligado ao pet. O contrato já aparece na ficha do cliente.');
    }

    public function dismiss(Request $request, LegacyPendingItem $item, LegacyImport $import)
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:500']);
        try {
            $import->dismiss($item, $request->user(), $data['reason']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
        return back()->with('success', 'Item retirado da lista, com o motivo registrado.');
    }
}
