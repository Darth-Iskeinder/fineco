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
        $employee = auth('employee')->user();

        // Только компании, у которых уже создана смета.
        // Видимость: админ и руководитель — все; остальные (главбух, бухгалтер) — только те
        // компании, где они ответственные. Правило то же, что в задачнике: своё — это своё.
        // Следствие: компания без ответственного не попадает никому, кроме админа
        // и руководителя, — так и договорились.
        // Кто видит несколько ответственных сразу — только им нужен фильтр по ответственному.
        // У главбуха/бухгалтера в списке и так только свои компании: селект с одним человеком — шум.
        $seesEveryone = $employee->isAdmin() || $employee->isManager();

        $clients = Client::active()
            ->has('estimates')
            ->when(!$seesEveryone, fn ($q) => $q->where('responsible_employee_id', $employee->id))
            ->with(['taxSystem', 'tariff', 'responsibleEmployee', 'estimate'])
            ->orderBy('name')
            ->get();

        // Варианты фильтров собираем из того, что реально есть в списке: пустые пункты
        // в селекте только мешают.
        $responsibleOptions = $clients
            ->map(fn ($c) => $c->responsibleEmployee)
            ->filter()->unique('id')->sortBy('full_name')
            ->map(fn ($e) => ['id' => $e->id, 'name' => $e->full_name])
            ->values();

        $taxSystemOptions = $clients
            ->map(fn ($c) => $c->taxSystem)
            ->filter()->unique('id')->sortBy('name')
            ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])
            ->values();

        return view('buhsmeta.index', [
            'clients'            => $clients,
            'canFilterByPerson'  => $seesEveryone,
            'responsibleOptions' => $responsibleOptions,
            'taxSystemOptions'   => $taxSystemOptions,
        ]);
    }

    public function avr(Request $request, Client $client)
    {
        $year  = (int) $request->get('year',  now()->year);
        $month = (int) $request->get('month', now()->month);
        $year  = max(2020, min(2030, $year));
        $month = max(1, min(12, $month));

        $client->load('taxSystem');

        // Выполненные плановые задачи. Только корневые: подпункт — не отдельная работа,
        // а галочка внутри своей задачи, и в смете его цена уже сидит в строке основного БП
        // (родительский total = сумма подпунктов). Отдельной строкой он удваивал бы сумму акта.
        $plannedLogs = BuhTaskLog::where('client_id', $client->id)
            ->where('year', $year)
            ->where('month', $month)
            ->where('status', 'completed')
            ->whereHas('estimateItem', fn ($q) => $q->whereNull('parent_id'))
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
