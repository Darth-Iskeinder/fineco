echo "старт\n";
$tid = 1;
$B = \App\Services\MonthClosing\MonthClosingBoard::class;
$today  = \Carbon\CarbonImmutable::now()->startOfDay();
$months = $B::window($B::defaultFocus($today), $today);
\App\Support\TenantContext::for($tid, function () use ($tid, $B, $today, $months) {
    $s = microtime(true);
    $data = (new $B($today))->build($months);
    echo "фирма #{$tid}: строк " . count($data['rows']) . ", расчёт " . round(microtime(true) - $s, 2) . " с, автоаудит в условии: " . json_encode($data['autoAudit']) . ", налоги: " . json_encode($data['taxes']) . "\n";
    $bad = [];
    foreach ($months as $m) {
        $k = $m->format('Y-m');
        $n = $m->addMonth();
        // Независимый счёт прямо из журнала задач: записи следующего месяца, корневые позиции.
        $raw = \Illuminate\Support\Facades\DB::table('buh_task_logs as l')
            ->join('estimate_items as i', 'i.id', '=', 'l.estimate_item_id')
            ->where('l.tenant_id', $tid)->where('l.status', '<>', 'handed')->whereNull('i.parent_id')
            ->where('l.year', $n->year)->where('l.month', $n->month)
            ->get(['l.id', 'l.client_id', 'l.status']);
        $rawOpen = $raw->filter(fn ($l) => $l->status !== 'completed')->groupBy('client_id')->map->count();
        $c = ['closed' => 0, 'overdue' => 0, 'open' => 0, 'none' => 0];
        $tasks = 0; $done = 0; $audit = 0; $taxT = 0; $taxD = 0; $unknown = 0; $nochief = 0; $seen = [];
        foreach ($data['rows'] as $r) {
            $cell = $r['cells'][$k]; $id = $r['client']->id; $seen[$id] = true;
            $c[$cell['status']]++;
            $tasks += count($cell['tasks']); $done += count($cell['tasks']) - $cell['open'];
            $audit += $cell['auditBlocks'] ? 1 : 0;
            if ($cell['taxes']) { $taxT += $cell['taxes']['total']; $taxD += $cell['taxes']['done']; }
            if ($cell['status'] !== 'none') { $unknown += $cell['chiefUnknown'] ? 1 : 0; $nochief += (!$cell['chiefUnknown'] && !$cell['chief']) ? 1 : 0; }
            $st = $cell['status'];
            if ($st === 'closed' && ($cell['open'] > 0 || $cell['auditBlocks'])) $bad[] = "{$k} клиент {$id}: закрыт, но открыто {$cell['open']} или вопрос автоаудита";
            if ($st === 'closed' && ($rawOpen[$id] ?? 0) > 0) $bad[] = "{$k} клиент {$id}: закрыт, а в журнале незакрытых {$rawOpen[$id]}";
            if (in_array($st, ['open', 'overdue'], true) && $cell['open'] === 0 && !$cell['auditBlocks']) $bad[] = "{$k} клиент {$id}: не закрыт без причины";
            if ($st === 'open' && $cell['deadline'] && $today->gt($cell['deadline'])) $bad[] = "{$k} клиент {$id}: срок прошёл, а показан как впереди";
            if ($st === 'overdue' && $cell['deadline'] && !$today->gt($cell['deadline'])) $bad[] = "{$k} клиент {$id}: срок впереди, а показан как прошёл";
            if ($cell['taxes'] && $st === 'closed' && $cell['taxes']['done'] < $cell['taxes']['total']) $bad[] = "{$k} клиент {$id}: закрыт, а налоги сданы не все";
        }
        $outside = $raw->pluck('client_id')->unique()->reject(fn ($id) => isset($seen[$id]))->values()->all();
        echo "\n{$k} (задачи месяца {$n->format('Y-m')})\n";
        echo "  страница: закрыт {$c['closed']}, срок прошёл {$c['overdue']}, срок впереди {$c['open']}, нет задач {$c['none']}\n";
        echo "  страница: задач {$tasks}, сделано {$done} | журнал напрямую: записей {$raw->count()}, закрыто " . $raw->where('status', 'completed')->count() . ", клиентов с незакрытыми " . $rawOpen->count() . "\n";
        echo "  клеток с вопросом автоаудита {$audit}; налоги {$taxD} из {$taxT}; главбух не определён {$unknown}, без главбуха {$nochief}\n";
        if ($outside) echo "  в журнале есть, на странице нет, клиенты: " . implode(', ', $outside) . "\n";
    }
    echo "\nПротиворечий: " . count($bad) . "\n" . implode("\n", array_slice($bad, 0, 40)) . "\n";
});
