@extends('layouts.app')
@section('title', 'Аудит')
@section('page-title', 'Аудит')

@section('content')
@push('styles')
<style>[x-cloak] { display: none !important; }</style>
@endpush

<div x-data="auditList()" class="space-y-4">

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <div>
                <h2 class="text-lg font-semibold text-slate-800">Аудиты</h2>
                <p class="text-sm text-slate-500 mt-0.5">Проверки качества по клиентам: одна строка — один клиент за один период</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('audit.findings') }}"
                   class="inline-flex items-center px-4 py-2 border border-slate-200 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-100 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 9v2m0 4h.01M5.07 19h13.86a2 2 0 001.74-3L13.74 4a2 2 0 00-3.48 0l-6.93 12a2 2 0 001.74 3z"/></svg>
                    Замечания
                    @if($openFindings > 0)
                        <span class="ml-2 px-2 py-0.5 rounded-full text-xs font-semibold {{ $overdueFindings > 0 ? 'bg-red-50 text-red-600' : 'bg-amber-50 text-amber-700' }}">{{ $openFindings }}</span>
                    @endif
                </a>
                <button @click="showNew = !showNew"
                        class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Новый аудит
                </button>
            </div>
        </div>

        {{-- Форма создания --}}
        <div x-show="showNew" x-transition x-cloak class="px-6 py-4 bg-slate-50 border-b border-slate-100">
            <form method="POST" action="{{ route('audit.store') }}">
                @csrf
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-slate-700 mb-1">Клиент <span class="text-red-500">*</span></label>
                        <select name="client_id" required
                                class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                            <option value="">— выберите клиента —</option>
                            @foreach($clients as $client)
                                <option value="{{ $client->id }}" @selected(old('client_id') == $client->id)>{{ $client->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Период</label>
                        <select @change="applyPreset($event.target.value)"
                                class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                            <template x-for="p in presets" :key="p.label">
                                <option :value="p.label" x-text="p.label"></option>
                            </template>
                            <option value="custom">Произвольный</option>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">С</label>
                            <input type="date" name="period_start" x-model="from" required
                                   class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">По</label>
                            <input type="date" name="period_end" x-model="to" required
                                   class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                        </div>
                    </div>

                </div>

                <div class="flex items-center gap-2 mt-4 flex-wrap">
                    <button type="submit" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors">
                        Создать и начать
                    </button>
                    <button type="button" @click="showNew = false"
                            class="px-4 py-2 border border-slate-200 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-100 transition-colors">Отмена</button>
                    <p class="text-xs text-slate-400 ml-2">
                        В аудит попадут все закрытые задачи клиента, чей месяц входит в период.
                        @if($standard)
                            Чек-лист подставится из стандарта — контрольных точек: {{ $standard->items()->count() }}.
                        @else
                            Стандарт чек-листа не заведён — чек-лист придётся заполнить вручную.
                        @endif
                    </p>
                </div>

                {{-- Ошибки валидации выводит общий блок в макете --}}
            </form>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Клиент</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Период</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Аудитор</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Статус</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Замечания</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Чек-лист</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Обновлён</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-slate-200">
                    @forelse($audits as $audit)
                        <tr onclick="window.location='{{ $audit['url'] }}'" class="hover:bg-slate-50 cursor-pointer">
                            <td class="px-6 py-4 text-sm font-medium text-slate-900">{{ $audit['client'] }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-600">{{ $audit['period'] }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-600">{{ $audit['auditor'] }}</td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span @class([
                                    'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium',
                                    'bg-emerald-50 text-emerald-700' => $audit['status'] === 'completed',
                                    'bg-indigo-50 text-indigo-700'   => $audit['status'] === 'in_progress',
                                    'bg-slate-100 text-slate-600'    => $audit['status'] === 'draft',
                                ])>{{ $audit['status_label'] }}</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                @if($audit['critical'] + $audit['major'] + $audit['minor'] === 0)
                                    <span class="text-sm text-slate-400">нет</span>
                                @else
                                    <div class="flex items-center gap-1.5">
                                        @if($audit['critical'])
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-50 text-red-700">{{ $audit['critical'] }} критич.</span>
                                        @endif
                                        @if($audit['major'])
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-700">{{ $audit['major'] }} сущ.</span>
                                        @endif
                                        @if($audit['minor'])
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-600">{{ $audit['minor'] }} незнач.</span>
                                        @endif
                                    </div>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-500 tabular-nums">{{ $audit['checklist'] }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-400">{{ $audit['updated'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-10 text-center text-slate-400">
                                Аудитов пока нет. Нажмите «Новый аудит», выберите клиента и период.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function auditList() {
    const pad = n => String(n).padStart(2, '0');
    const iso = (y, m, d) => `${y}-${pad(m)}-${pad(d)}`;
    const now = new Date();
    const year = now.getFullYear();
    const q = Math.floor(now.getMonth() / 3) + 1;

    const quarter = (y, n) => ({
        label: `${['I', 'II', 'III', 'IV'][n - 1]} квартал ${y}`,
        from: iso(y, n * 3 - 2, 1),
        to: iso(y, n * 3, new Date(y, n * 3, 0).getDate()),
    });

    const presets = [];
    for (let i = 0; i < 4; i++) {
        const n = q - i;
        presets.push(n > 0 ? quarter(year, n) : quarter(year - 1, n + 4));
    }
    presets.push({ label: `Первое полугодие ${year}`, from: iso(year, 1, 1), to: iso(year, 6, 30) });
    presets.push({ label: `${year} год`, from: iso(year, 1, 1), to: iso(year, 12, 31) });

    return {
        showNew: {{ $errors->any() ? 'true' : 'false' }},
        presets,
        from: presets[0].from,
        to: presets[0].to,
        applyPreset(label) {
            const p = this.presets.find(x => x.label === label);
            if (p) { this.from = p.from; this.to = p.to; }
        },
    };
}
</script>
@endsection
