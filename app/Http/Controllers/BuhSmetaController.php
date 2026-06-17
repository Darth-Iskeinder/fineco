<?php

namespace App\Http\Controllers;

use App\Models\BuhAdhocTask;
use App\Models\BuhTaskLog;
use App\Models\Client;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class BuhSmetaController extends Controller
{
    public function index(Request $request)
    {
        // Только компании, у которых уже создана смета
        $clients = Client::active()
            ->has('estimates')
            ->with(['taxSystem', 'tariff', 'responsibleEmployee', 'estimate'])
            ->orderBy('name')
            ->get();

        return view('buhsmeta.index', [
            'clients'        => $clients,
            'ownershipForms' => Client::$ownershipForms,
        ]);
    }

    public function avr(Request $request, Client $client)
    {
        $year  = (int) $request->get('year',  now()->year);
        $month = (int) $request->get('month', now()->month);
        $year  = max(2020, min(2030, $year));
        $month = max(1, min(12, $month));

        $client->load('taxSystem');

        // Выполненные плановые задачи
        $plannedLogs = BuhTaskLog::where('client_id', $client->id)
            ->where('year', $year)
            ->where('month', $month)
            ->where('status', 'completed')
            ->with(['employee', 'estimateItem'])
            ->get();

        // Выполненные внеплановые задачи
        $adhocTasks = BuhAdhocTask::where('client_id', $client->id)
            ->where('year', $year)
            ->where('month', $month)
            ->where('status', 'completed')
            ->with('employee')
            ->get();

        $tasks = collect();

        foreach ($plannedLogs as $log) {
            if (!$log->estimateItem) continue;
            $tasks->push([
                'name'          => $log->estimateItem->name,
                'employee_name' => $log->employee->full_name,
                'cost'          => (float) $log->estimateItem->total,
                'completed_at'  => $log->completed_at,
                'type'          => 'planned',
            ]);
        }

        foreach ($adhocTasks as $adhoc) {
            $tasks->push([
                'name'          => $adhoc->name,
                'employee_name' => $adhoc->employee->full_name,
                'cost'          => (float) $adhoc->cost,
                'completed_at'  => $adhoc->completed_at,
                'type'          => 'adhoc',
            ]);
        }

        $total = $tasks->sum('cost');

        $pdf = Pdf::loadView('pdf.avr', compact('client', 'tasks', 'total', 'year', 'month'))
            ->setPaper('a4', 'portrait');

        $filename = 'avr_' . preg_replace('/[^a-zA-Z0-9]/', '_', $client->name) . '_' . $year . '_' . $month . '.pdf';

        return $pdf->download($filename);
    }
}
