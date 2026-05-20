<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: "DejaVu Sans", sans-serif; }
        body { font-size: 12px; color: #1e293b; padding: 40px; }
        .header { margin-bottom: 32px; }
        .header h1 { font-size: 20px; font-weight: bold; color: #1e293b; margin-bottom: 4px; }
        .header p { color: #64748b; font-size: 11px; }
        .meta { display: flex; gap: 32px; margin-bottom: 28px; padding: 16px; background: #f8fafc; border-radius: 8px; }
        .meta-item label { font-size: 10px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 2px; }
        .meta-item span { font-size: 12px; font-weight: 600; color: #1e293b; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        thead th { background: #f1f5f9; padding: 10px 12px; text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; font-weight: 600; }
        thead th.right { text-align: right; }
        tbody td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; font-size: 12px; vertical-align: top; }
        tbody tr:last-child td { border-bottom: none; }
        .total-row td { padding: 12px; font-weight: bold; font-size: 13px; border-top: 2px solid #e2e8f0; background: #f8fafc; }
        .badge-adhoc { display: inline-block; padding: 2px 6px; border-radius: 10px; font-size: 9px; background: #fef3c7; color: #92400e; margin-left: 4px; }
        .amount { text-align: right; font-weight: 600; white-space: nowrap; }
        .employee { font-size: 11px; color: #64748b; margin-top: 2px; }
        .date { font-size: 11px; color: #94a3b8; }
        .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; font-size: 10px; color: #94a3b8; }
        .empty { text-align: center; padding: 32px; color: #94a3b8; font-size: 13px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Акт выполненных работ</h1>
        <p>Сформировано: {{ now()->format('d.m.Y H:i') }}</p>
    </div>

    <div class="meta">
        <div class="meta-item">
            <label>Компания</label>
            <span>{{ $client->name }}</span>
        </div>
        <div class="meta-item">
            <label>ИНН</label>
            <span>{{ $client->inn }}</span>
        </div>
        <div class="meta-item">
            <label>Период</label>
            <span>
                @php
                    $months = ['','Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
                @endphp
                {{ $months[$month] }} {{ $year }}
            </span>
        </div>
        @if($client->taxSystem)
        <div class="meta-item">
            <label>Система налогообложения</label>
            <span>{{ $client->taxSystem->name }}</span>
        </div>
        @endif
    </div>

    @if($tasks->isEmpty())
        <div class="empty">За указанный период выполненных задач не найдено.</div>
    @else
        <table>
            <thead>
                <tr>
                    <th style="width: 36px;">#</th>
                    <th>Наименование работы</th>
                    <th style="width: 130px;">Исполнитель</th>
                    <th style="width: 90px;">Дата</th>
                    <th style="width: 110px;" class="right">Сумма</th>
                </tr>
            </thead>
            <tbody>
                @foreach($tasks as $i => $task)
                <tr>
                    <td style="color: #94a3b8;">{{ $i + 1 }}</td>
                    <td>
                        {{ $task['name'] }}
                        @if($task['type'] === 'adhoc')
                            <span class="badge-adhoc">доп.</span>
                        @endif
                    </td>
                    <td class="employee">{{ $task['employee_name'] }}</td>
                    <td class="date">
                        {{ $task['completed_at'] ? $task['completed_at']->format('d.m.Y') : '—' }}
                    </td>
                    <td class="amount">{{ number_format($task['cost'], 0, ',', ' ') }} сом</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="total-row">
                    <td colspan="4" style="text-align: right;">Итого выполнено:</td>
                    <td class="amount">{{ number_format($total, 0, ',', ' ') }} сом</td>
                </tr>
            </tfoot>
        </table>
    @endif

    <div class="footer">
        <span>ERP Fineco</span>
        <span>{{ $months[$month] }} {{ $year }}</span>
    </div>
</body>
</html>
