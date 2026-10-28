<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\Reports\ReportService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function index(Request $request, ReportService $reports)
    {
        $filters = $reports->filters($request->user(), $request->query());
        return response()->view('reports.index', $reports->report($request->user(), $filters))
            ->header('Cache-Control', 'private, no-store');
    }

    public function export(Request $request, ReportService $reports)
    {
        $filters = $reports->filters($request->user(), $request->query());
        $count = $reports->exportCount($request->user(), $filters);
        AuditLog::create(['actor_id' => $request->user()->id, 'action' => 'report.exported', 'entity_type' => 'commercial_report',
            'entity_id' => $request->user()->id, 'data' => ['filters' => $filters, 'proposal_count' => $count]]);
        return response()->streamDownload(function () use ($request, $reports, $filters) {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['Proposta', 'Proposta criada em (Brasília)', 'Tutor contratante', 'Pet', 'Produto', 'Categoria',
                'Responsável pela proposta', 'Franquia de origem', 'Canal', 'Origem de aquisição', 'Indicado por', 'Campanha',
                'Link de aquisição', 'Responsável pelo link', 'Situação da proposta', 'Situação operacional', 'Documento',
                'Primeiro pagamento', 'Aceite em (Brasília)', 'Primeira ativação em (Brasília)',
                'Recebido conciliado no período (R$)', 'Recebimento demonstrativo no período (R$)'], ';', '"', '');
            foreach ($reports->exportRows($request->user(), $filters) as $row) fputcsv($stream, $reports->csvValues($row), ';', '"', '');
            fclose($stream);
        }, 'contratacoes-'.$filters['date_from'].'-a-'.$filters['date_to'].'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
