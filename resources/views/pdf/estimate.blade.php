<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: "DejaVu Sans", sans-serif; }
        body { font-size: 12px; color: #1e293b; padding: 40px; }
        .header { margin-bottom: 28px; }
        .header h1 { font-size: 18px; font-weight: bold; color: #1e293b; margin-bottom: 4px; }
        .header p { color: #64748b; font-size: 11px; }
        .meta { display: flex; gap: 28px; margin-bottom: 24px; padding: 14px 16px; background: #f8fafc; border-radius: 8px; }
        .meta-item label { font-size: 10px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 2px; }
        .meta-item span { font-size: 12px; font-weight: 600; color: #1e293b; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        thead th { background: #f1f5f9; padding: 9px 10px; text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; font-weight: 600; }
        thead th.right { text-align: right; }
        tbody td { padding: 9px 10px; border-bottom: 1px solid #f1f5f9; font-size: 12px; vertical-align: middle; }
        tbody td.right { text-align: right; font-weight: 600; }
        tbody td.center { text-align: center; }
        tbody tr.child-row td { background: #fafafa; color: #475569; font-size: 11px; }
        tbody tr.child-row td.child-name { padding-left: 28px; }
        tbody tr:last-child td { border-bottom: none; }
        tfoot td { padding: 11px 10px; font-weight: bold; font-size: 13px; border-top: 2px solid #e2e8f0; background: #f8fafc; }
        tfoot td.right { text-align: right; font-weight: bold; }
        .badge { display: inline-block; padding: 2px 7px; border-radius: 20px; font-size: 9px; font-weight: 600; }
        .badge-recurring { background: #dcfce7; color: #166534; }
        .badge-onetime { background: #fef9c3; color: #854d0e; }
        .notes { margin-top: 16px; padding: 14px; background: #f8fafc; border-radius: 8px; }
        .notes label { font-size: 10px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 6px; }
        .footer { margin-top: 36px; padding-top: 16px; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; font-size: 10px; color: #94a3b8; }
    </style>
</head>
<body>

<div class="header">
    <h1>Смета на бухгалтерские услуги</h1>
    <p>Сформировано: {{ now()->format('d.m.Y') }}</p>
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
        <label>Налогообложение</label>
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
            <th style="width: 32px;">#</th>
            <th>Наименование услуги</th>
            <th style="width: 80px;">Тип</th>
            <th style="width: 110px;">Периодичность</th>
            <th style="width: 85px;" class="right">Цена</th>
            <th style="width: 45px;" class="right">Кол.</th>
            <th style="width: 95px;" class="right">Сумма</th>
        </tr>
    </thead>
    <tbody>
        @php $rowNum = 0; @endphp
        @forelse($estimate->rootItems as $item)
            @php $rowNum++; @endphp
            <tr>
                <td style="color: #94a3b8;">{{ $rowNum }}</td>
                <td>{{ $item->name }}</td>
                <td>
                    @if($item->type === 'recurring')
                        <span class="badge badge-recurring">Постоянная</span>
                    @else
                        <span class="badge badge-onetime">Временная</span>
                    @endif
                </td>
                <td>{{ $item->periodicity ?? '—' }}</td>
                <td class="right">{{ number_format($item->cost, 0, ',', ' ') }}</td>
                <td class="right">{{ $item->quantity }}</td>
                <td class="right">{{ number_format($item->total, 0, ',', ' ') }} сом</td>
            </tr>
            @foreach($item->children as $child)
            <tr class="child-row">
                <td></td>
                <td class="child-name">↳ {{ $child->name }}</td>
                <td></td>
                <td>{{ $child->periodicity ?? '—' }}</td>
                <td class="right">{{ number_format($child->cost, 0, ',', ' ') }}</td>
                <td class="right">{{ $child->quantity }}</td>
                <td class="right">{{ number_format($child->total, 0, ',', ' ') }} сом</td>
            </tr>
            @endforeach
        @empty
            <tr>
                <td colspan="7" style="text-align: center; color: #94a3b8; padding: 24px;">Нет позиций в смете</td>
            </tr>
        @endforelse
    </tbody>
    <tfoot>
        <tr>
            <td colspan="6" class="right">Итого:</td>
            <td class="right">{{ number_format($estimate->total, 0, ',', ' ') }} сом</td>
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
    <span>{{ $periodLabel }}</span>
</div>

</body>
</html>
