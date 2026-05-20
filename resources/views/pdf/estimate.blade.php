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
        thead th:last-child { text-align: right; }
        tbody td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; font-size: 12px; }
        tbody td:last-child { text-align: right; font-weight: 600; }
        tbody tr:last-child td { border-bottom: none; }
        .total-row { background: #f8fafc; }
        .total-row td { padding: 12px; font-weight: bold; font-size: 13px; border-top: 2px solid #e2e8f0; }
        .notes { margin-top: 20px; padding: 14px; background: #f8fafc; border-radius: 8px; }
        .notes label { font-size: 10px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 6px; }
        .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; font-size: 10px; color: #94a3b8; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 10px; background: #ede9fe; color: #7c3aed; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Смета на бухгалтерские услуги</h1>
        <p>Дата: {{ now()->format('d.m.Y') }}</p>
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
        @if($client->taxSystem)
        <div class="meta-item">
            <label>Система налогообложения</label>
            <span>{{ $client->taxSystem->name }}</span>
        </div>
        @endif
        @if($client->tariff)
        <div class="meta-item">
            <label>Тариф</label>
            <span>{{ $client->tariff->name }}</span>
        </div>
        @endif
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 40px;">#</th>
                <th>Наименование услуги</th>
                <th style="width: 120px;">Периодичность</th>
                <th style="width: 90px; text-align: right;">Цена</th>
                <th style="width: 60px; text-align: center;">Кол.</th>
                <th style="width: 100px;">Сумма</th>
            </tr>
        </thead>
        <tbody>
            @foreach($estimate->items as $i => $item)
            <tr>
                <td style="color: #94a3b8;">{{ $i + 1 }}</td>
                <td>{{ $item->name }}</td>
                <td>{{ $item->periodicity ?? '—' }}</td>
                <td style="text-align: right;">{{ number_format($item->cost, 0, ',', ' ') }}</td>
                <td style="text-align: center;">{{ $item->quantity }}</td>
                <td>{{ number_format($item->total, 0, ',', ' ') }} сом</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="5" style="text-align: right;">Итого:</td>
                <td>{{ number_format($estimate->total, 0, ',', ' ') }} сом</td>
            </tr>
        </tfoot>
    </table>

    @if($estimate->notes)
    <div class="notes">
        <label>Примечания</label>
        <p>{{ $estimate->notes }}</p>
    </div>
    @endif

    <div class="footer">
        <span>ERP Fineco</span>
        <span>Сформировано: {{ now()->format('d.m.Y H:i') }}</span>
    </div>
</body>
</html>
