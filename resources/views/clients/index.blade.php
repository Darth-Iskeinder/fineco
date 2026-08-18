@extends('layouts.app')

@section('title', 'Клиенты - Kubik')
@section('page-title', 'Клиенты')

@section('content')
<div x-data="{
    showCreateModal: {{ $errors->createClient->isNotEmpty() ? 'true' : 'false' }},
    // Файл не прошёл проверку — открываем окно импорта заново, а не прячем ошибку.
    showImportModal: {{ $errors->has('file') ? 'true' : 'false' }},
    showEditModal: false,
    showDeleteModal: false,
    deleteClient: null,
    // Не null: модалка редактирования всегда в DOM (x-show), а x-model на editClient.* не умеет
    // optional chaining — при null Alpine падал бы с TypeError ещё до открытия. Держим объект
    // с полями под x-model; openEditModal() полностью заменяет его данными клиента.
    editClient: { tax_system_id: '', tariff_id: '', responsible_employee_id: '' },

    init() {
        @if($errors->updateClient->isNotEmpty())
            // Валидация при редактировании не прошла — переоткрываем именно модалку
            // редактирования с введёнными значениями, чтобы случайно не создать дубль.
            const base = this.clients.find(c => c.id === {{ (int) old('client_id') }}) || { id: {{ (int) old('client_id') }} };
            this.editClient = {
                ...base,
                name: @json(old('name')),
                inn: @json(old('inn')),
                tax_system_id: @json(old('tax_system_id')),
                tariff_id: @json(old('tariff_id')),
                responsible_employee_id: @json(old('responsible_employee_id')) ?? '',
            };
            this.showEditModal = true;
        @endif
    },
    allEmployees: @js($employees->map(fn($e) => ['id' => $e->id, 'name' => $e->full_name])),
    taxSystems: @js($taxSystems->map(fn($t) => ['id' => $t->id, 'name' => $t->name])),
    tariffs: @js($tariffs->map(fn($t) => ['id' => $t->id, 'name' => $t->name])),

    // Live search
    searchQuery: '',
    clients: @js($clients->map(fn($c) => [
        'id' => $c->id,
        'name' => $c->name,
        'inn' => $c->inn,
        'tax_system_id' => $c->tax_system_id,
        'tax_system_name' => $c->taxSystem?->name ?? '—',
        'tariff_id' => $c->tariff_id,
        'tariff_name' => $c->tariff?->name ?? '—',
        'is_active' => $c->is_active,
        'responsible_employee_id' => $c->responsible_employee_id,
        'responsible_name' => $c->responsibleEmployee?->full_name ?? '—',
        'estimate_items_count' => $c->estimate_root_items_count,
    ])),
    loading: false,
    searchTimeout: null,

    // Быстрые фильтры. '' — «все», 'none' — «не указан». Значения приходят с сервера,
    // чтобы ссылка вида /clients?status=inactive открывалась уже отфильтрованной.
    filters: {
        responsible: @js((string) ($filters['responsible'] ?? '')),
        status:      @js((string) ($filters['status'] ?? '')),
        tax_system:  @js((string) ($filters['tax_system'] ?? '')),
    },

    get isFiltered() {
        return this.searchQuery.length > 0 || Object.values(this.filters).some(v => v !== '');
    },

    // Подписи чипов: показываем выбранное значение, а не название поля —
    // «Асанова А.» читается быстрее, чем «Ответственный: Асанова А.»
    get filterChips() {
        const chips = [];
        const named = (list, id) => (list.find(x => String(x.id) === String(id)) || {}).name || id;

        if (this.filters.responsible === 'none') chips.push({ key: 'responsible', label: 'Без ответственного' });
        else if (this.filters.responsible) chips.push({ key: 'responsible', label: named(this.allEmployees, this.filters.responsible) });

        if (this.filters.status) chips.push({ key: 'status', label: this.filters.status === 'active' ? 'Активные' : 'Неактивные' });

        if (this.filters.tax_system === 'none') chips.push({ key: 'tax_system', label: 'Без СНО' });
        else if (this.filters.tax_system) chips.push({ key: 'tax_system', label: named(this.taxSystems, this.filters.tax_system) });

        if (this.searchQuery) chips.push({ key: 'search', label: '«' + this.searchQuery + '»' });

        return chips;
    },

    // Поиск, фильтры и выгрузка CSV ходят с одними и теми же параметрами,
    // иначе скачается не то, что человек видит на экране.
    filterParams() {
        const params = new URLSearchParams();
        if (this.searchQuery) params.set('search', this.searchQuery);
        Object.entries(this.filters).forEach(([k, v]) => { if (v !== '') params.set(k, v); });
        return params;
    },

    get exportUrl() {
        const qs = this.filterParams().toString();
        return '{{ route('clients.export') }}' + (qs ? '?' + qs : '');
    },

    applyFilters() {
        this.searchClients();
    },

    clearFilter(key) {
        if (key === 'search') this.searchQuery = '';
        else this.filters[key] = '';
        this.searchClients();
    },

    resetFilters() {
        this.searchQuery = '';
        Object.keys(this.filters).forEach(k => { this.filters[k] = ''; });
        this.searchClients();
    },

    // Сортировка: колонка ('name' | 'responsible') + направление ('asc' → А-Я, 'desc' → Я-А).
    // Обе null → порядок с сервера (последний созданный клиент сверху). Сортировка одна на таблицу:
    // клик по другой колонке перехватывает её на себя.
    sortBy: null,
    sortDir: null,

    // Пустые значения («—» у ответственного) всегда в конце, независимо от направления.
    get sortedClients() {
        if (!this.sortBy || !this.sortDir) return this.clients;
        const dir = this.sortDir === 'asc' ? 1 : -1;
        const val = this.sortBy === 'name'
            ? (c => c.name || '')
            : (c => (c.responsible_name && c.responsible_name !== '—') ? c.responsible_name : '');
        return [...this.clients].sort((a, b) => {
            const av = val(a), bv = val(b);
            if (!av && !bv) return 0;
            if (!av) return 1;
            if (!bv) return -1;
            return av.localeCompare(bv, 'ru') * dir;
        });
    },

    // Три состояния по кругу: А-Я → Я-А → сброс к порядку по умолчанию.
    toggleSort(column) {
        if (this.sortBy !== column) {
            this.sortBy = column;
            this.sortDir = 'asc';
            return;
        }
        this.sortDir = this.sortDir === 'asc' ? 'desc' : null;
        if (!this.sortDir) this.sortBy = null;
    },

    async searchClients() {
        this.loading = true;
        try {
            const response = await fetch('/clients/search?' + this.filterParams().toString());
            this.clients = await response.json();
        } catch (error) {
            console.error('Search error:', error);
        }
        this.loading = false;
    },

    onSearchInput() {
        clearTimeout(this.searchTimeout);
        this.searchTimeout = setTimeout(() => {
            this.searchClients();
        }, 300);
    },

    clearSearch() {
        this.searchQuery = '';
        this.searchClients();
    },

    resetCreateForm() {},

    openEditModal(client) {
        // Нормализуем под select: null → '' (иначе пункт «Не назначено» не выберется)
        this.editClient = { ...client, responsible_employee_id: client.responsible_employee_id ?? '' };
        this.showEditModal = true;
    }
}">
    <div class="sm:flex sm:items-center sm:justify-between mb-8">
        <div>
            <p class="text-slate-500">Управление клиентской базой</p>
        </div>
        <div class="mt-4 sm:mt-0 flex items-center gap-3">
            @if ($canManageClients)
            <button @click="showImportModal = true" type="button"
                    class="inline-flex items-center px-4 py-2.5 bg-white text-slate-600 text-sm font-medium rounded-xl border border-slate-200 hover:bg-slate-50 hover:text-slate-800 transition-all duration-200">
                <svg class="-ml-0.5 mr-2 h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                </svg>
                Импорт
            </button>
            @endif
            {{-- Выгружаем то, что человек сейчас видит: с поиском и фильтрами — найденное,
                 без них — всех. Фильтры живут в Alpine, поэтому ссылку собираем на лету. --}}
            <a :href="exportUrl"
               :title="isFiltered ? 'Выгрузить найденных клиентов в CSV' : 'Выгрузить всех клиентов в CSV'"
               class="inline-flex items-center px-4 py-2.5 bg-white text-slate-600 text-sm font-medium rounded-xl border border-slate-200 hover:bg-slate-50 hover:text-slate-800 transition-all duration-200">
                <svg class="-ml-0.5 mr-2 h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                Экспорт
                <span x-show="isFiltered" class="ml-1 text-slate-400" x-text="'(' + clients.length + ')'"></span>
            </a>
            @if ($canManageClients)
            <button @click="showCreateModal = true; resetCreateForm()"
                    type="button"
                    class="inline-flex items-center px-5 py-2.5 bg-gradient-to-r from-violet-500 to-indigo-600 text-white text-sm font-medium rounded-xl shadow-lg shadow-indigo-500/25 hover:shadow-xl hover:shadow-indigo-500/30 hover:-translate-y-0.5 transition-all duration-200">
                <svg class="-ml-1 mr-2 h-5 w-5" fill="none" viewBox="0 0 20 20" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" />
                </svg>
                Добавить клиента
            </button>
            @endif
        </div>
    </div>

    {{-- Итог загрузки: сразу видно, что произошло, и как это отменить --}}
    @if (session('import_result'))
        @php($result = session('import_result'))
        <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 flex items-center justify-between gap-4">
            <p class="text-sm text-emerald-900">
                Импорт завершён: создано <span class="font-semibold">{{ $result['created'] }}</span>,
                обновлено <span class="font-semibold">{{ $result['updated'] }}</span>,
                пропущено <span class="font-semibold">{{ $result['skipped'] }}</span>.
            </p>
            <a href="{{ route('clients.imports.show', $result['id']) }}"
               class="flex-shrink-0 text-sm font-medium text-emerald-800 hover:text-emerald-950 underline">
                Кого затронуло
            </a>
        </div>
    @endif

    {{-- Окно импорта: только выбор файла. Всё, что можно сделать не так,
         человек увидит на следующем экране — до записи в базу. --}}
    @if ($canManageClients)
    <div x-show="showImportModal" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4"
         @click.self="showImportModal = false"
         @keydown.escape.window="showImportModal = false">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-6">
            <div class="flex items-start justify-between gap-4 mb-4">
                <h3 class="text-base font-semibold text-slate-800">Импорт клиентов</h3>
                <button @click="showImportModal = false" type="button" class="text-slate-300 hover:text-slate-500">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            @error('file')
                <p class="mb-3 rounded-xl bg-rose-50 border border-rose-200 px-4 py-2.5 text-sm text-rose-700">{{ $message }}</p>
            @enderror

            <form action="{{ route('clients.import.preview') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <input type="file" name="file" accept=".csv,text/csv" required
                       class="block w-full text-sm text-slate-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200 cursor-pointer">

                <p class="text-xs text-slate-500 mt-3">
                    Файл CSV, до 1000 строк. Обязательны только «Название» и «ИНН».
                    Не знаете формат — <a href="{{ route('clients.import.template') }}" class="text-indigo-600 hover:text-indigo-800 underline">скачайте шаблон</a>
                    или выгрузите текущих клиентов кнопкой «Экспорт».
                </p>
                <p class="text-xs text-slate-500 mt-1.5">
                    Ничего не запишется сразу: сначала покажем, что изменится.
                    <a href="{{ route('clients.imports.index') }}" class="text-indigo-600 hover:text-indigo-800 underline">Прошлые загрузки</a>
                </p>

                <div class="flex gap-3 mt-5">
                    <button type="button" @click="showImportModal = false"
                            class="flex-1 py-2.5 px-4 bg-slate-100 text-slate-600 text-sm font-medium rounded-xl hover:bg-slate-200 transition-colors">
                        Отмена
                    </button>
                    <button type="submit"
                            class="flex-1 py-2.5 px-4 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors">
                        Проверить файл
                    </button>
                </div>
            </form>
        </div>
    </div>
    @endif

    <!-- Поиск -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 mb-6">
        <div class="px-6 py-5">
            <div class="sm:flex sm:items-center sm:gap-4">
                <div class="flex-1">
                    <label for="search" class="sr-only">Поиск</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <svg x-show="!loading" class="h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                            <svg x-show="loading" class="h-5 w-5 text-indigo-500 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </div>
                        <input type="text"
                               id="search"
                               x-model="searchQuery"
                               @input="onSearchInput()"
                               class="block w-full pl-11 pr-10 py-2.5 border border-slate-200 rounded-xl bg-slate-50/50 text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 focus:bg-white transition-all duration-200 sm:text-sm"
                               placeholder="Поиск по названию, ИНН...">
                        <button x-show="searchQuery.length > 0"
                                @click="clearSearch()"
                                type="button"
                                class="absolute inset-y-0 right-0 pr-4 flex items-center text-slate-400 hover:text-slate-600">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>
                <div class="mt-4 sm:mt-0">
                    {{-- «из N» показываем только когда список сужен: иначе цифра сбивает с толку --}}
                    <span x-show="isFiltered" class="text-sm text-slate-500">
                        Найдено: <span class="font-medium text-slate-700" x-text="clients.length"></span>
                        из {{ $totalClients }}
                    </span>
                </div>
            </div>

            {{-- Быстрые фильтры. Значение '' — «все», 'none' — «не указан»:
                 клиент без ответственного или без РН иначе не находится, а именно
                 такие ломают работу (задачи никуда не идут, смета пустая). --}}
            <div class="mt-4 flex flex-wrap items-center gap-2">
                {{-- Фильтр по ответственному нужен только тем, кто видит чужие компании:
                     у остальных в списке одни свои — селект из одного человека это шум. --}}
                @if ($canFilterByPerson)
                <select x-model="filters.responsible" @change="applyFilters()"
                        :class="filters.responsible ? 'border-indigo-300 bg-indigo-50 text-indigo-700' : 'border-slate-200 bg-slate-50/50 text-slate-600'"
                        class="px-3 py-2 border rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 transition-colors">
                    <option value="">Ответственный: все</option>
                    <option value="none">— не назначен —</option>
                    <template x-for="e in allEmployees" :key="e.id">
                        <option :value="e.id" x-text="e.name"></option>
                    </template>
                </select>
                @endif

                <select x-model="filters.status" @change="applyFilters()"
                        :class="filters.status ? 'border-indigo-300 bg-indigo-50 text-indigo-700' : 'border-slate-200 bg-slate-50/50 text-slate-600'"
                        class="px-3 py-2 border rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 transition-colors">
                    <option value="">Статус: все</option>
                    <option value="active">Активные</option>
                    <option value="inactive">Неактивные</option>
                </select>

                <select x-model="filters.tax_system" @change="applyFilters()"
                        :class="filters.tax_system ? 'border-indigo-300 bg-indigo-50 text-indigo-700' : 'border-slate-200 bg-slate-50/50 text-slate-600'"
                        class="px-3 py-2 border rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 transition-colors">
                    <option value="">СНО: все</option>
                    <option value="none">— не указан —</option>
                    <template x-for="t in taxSystems" :key="t.id">
                        <option :value="t.id" x-text="t.name"></option>
                    </template>
                </select>

            </div>

            {{-- Чипы применённых фильтров: видно, почему список короткий, и снимается в один клик --}}
            <div x-show="isFiltered" class="mt-3 flex flex-wrap items-center gap-2">
                <template x-for="chip in filterChips" :key="chip.key">
                    <span class="inline-flex items-center gap-1.5 pl-3 pr-2 py-1 rounded-lg text-xs font-medium bg-indigo-50 text-indigo-700 border border-indigo-100">
                        <span x-text="chip.label"></span>
                        <button type="button" @click="clearFilter(chip.key)" class="text-indigo-400 hover:text-indigo-700" :title="'Снять фильтр: ' + chip.label">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </span>
                </template>
                <button type="button" @click="resetFilters()"
                        class="text-xs font-medium text-slate-500 hover:text-slate-700 underline underline-offset-2">
                    Сбросить всё
                </button>
            </div>
        </div>
    </div>

    <!-- Таблица -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead>
                    <tr class="bg-slate-50/80 border-b border-slate-200/50">
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">
                            ID
                        </th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">
                            <button type="button" @click="toggleSort('name')"
                                    class="group inline-flex items-center gap-1 uppercase tracking-wider hover:text-slate-700 transition-colors select-none">
                                Название
                                <span class="flex flex-col -space-y-1">
                                    <svg class="w-2.5 h-2.5" :class="sortBy === 'name' && sortDir === 'asc' ? 'text-indigo-600' : 'text-slate-300 group-hover:text-slate-400'" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M10 5l4 5H6l4-5z" />
                                    </svg>
                                    <svg class="w-2.5 h-2.5" :class="sortBy === 'name' && sortDir === 'desc' ? 'text-indigo-600' : 'text-slate-300 group-hover:text-slate-400'" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M10 15l-4-5h8l-4 5z" />
                                    </svg>
                                </span>
                            </button>
                        </th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">
                            ИНН
                        </th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">
                            СНО
                        </th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">
                            Тарифный план
                        </th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">
                            <button type="button" @click="toggleSort('responsible')"
                                    class="group inline-flex items-center gap-1 uppercase tracking-wider hover:text-slate-700 transition-colors select-none">
                                Ответственный
                                <span class="flex flex-col -space-y-1">
                                    <svg class="w-2.5 h-2.5" :class="sortBy === 'responsible' && sortDir === 'asc' ? 'text-indigo-600' : 'text-slate-300 group-hover:text-slate-400'" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M10 5l4 5H6l4-5z" />
                                    </svg>
                                    <svg class="w-2.5 h-2.5" :class="sortBy === 'responsible' && sortDir === 'desc' ? 'text-indigo-600' : 'text-slate-300 group-hover:text-slate-400'" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M10 15l-4-5h8l-4 5z" />
                                    </svg>
                                </span>
                            </button>
                        </th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">
                            Статус
                        </th>
                        <th scope="col" class="relative px-6 py-4">
                            <span class="sr-only">Действия</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="client in sortedClients" :key="client.id">
                        <tr class="hover:bg-slate-50/50 transition-colors duration-150">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-slate-500 font-mono" x-text="client.id"></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center gap-2">
                                    <a :href="'/clients/' + client.id" class="flex items-center group">
                                        <div class="w-9 h-9 bg-gradient-to-br from-emerald-100 to-teal-100 rounded-xl flex items-center justify-center mr-3">
                                            <span class="text-sm font-semibold text-teal-600" x-text="client.name.charAt(0)"></span>
                                        </div>
                                        <div class="text-sm font-medium text-slate-800 group-hover:text-indigo-600 transition-colors" x-text="client.name"></div>
                                    </a>
                                    <!-- Смета собрана — ведёт сразу в смету. Нет позиций → бейджа нет вовсе. -->
                                    <a x-show="client.estimate_items_count > 0"
                                       :href="'/clients/' + client.id + '/estimate/edit'"
                                       :title="'Открыть смету — позиций: ' + client.estimate_items_count"
                                       class="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg text-xs font-medium bg-emerald-50 text-emerald-700 hover:bg-emerald-100 hover:text-emerald-800 transition-colors">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                        </svg>
                                        Смета
                                    </a>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-slate-600 font-mono" x-text="client.inn"></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-slate-600" x-text="client.tax_system_name"></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-slate-600" x-text="client.tariff_name"></div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm text-slate-600 max-w-[200px] truncate" x-text="client.responsible_name" :title="client.responsible_name"></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span x-show="client.is_active" class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium bg-emerald-100 text-emerald-700">
                                    Активен
                                </span>
                                <span x-show="!client.is_active" class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium bg-slate-100 text-slate-600">
                                    Неактивен
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <a :href="'/clients/' + client.id"
                                       class="inline-flex items-center p-2 text-slate-400 hover:text-emerald-600 hover:bg-emerald-50 rounded-lg transition-all duration-150"
                                       title="Просмотр">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                        </svg>
                                    </a>
                                    <button type="button"
                                            @click="openEditModal(client)"
                                            class="inline-flex items-center p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-all duration-150"
                                            title="Редактировать">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                    </button>
                                    <button type="button"
                                            @click="deleteClient = { id: client.id, name: client.name }; showDeleteModal = true"
                                            class="inline-flex items-center p-2 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-all duration-150"
                                            title="Удалить">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <!-- Пустое состояние -->
        <div x-show="clients.length === 0" class="px-6 py-16 text-center">
            <div class="w-16 h-16 bg-slate-100 rounded-2xl flex items-center justify-center mx-auto mb-4">
                <svg class="h-8 w-8 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                </svg>
            </div>
            <h3 class="text-sm font-medium text-slate-800 mb-1">Клиенты не найдены</h3>
            <p class="text-sm text-slate-500 mb-6" x-text="isFiltered ? 'Попробуйте изменить поиск или снять фильтры' : 'Начните с добавления нового клиента'"></p>
            {{-- Под фильтром предлагаем снять фильтры, а не заводить нового клиента:
                 клиент, скорее всего, есть — просто не попал в выборку. --}}
            <button x-show="isFiltered" @click="resetFilters()" type="button"
                    class="inline-flex items-center px-5 py-2.5 bg-white text-slate-600 text-sm font-medium rounded-xl border border-slate-200 hover:bg-slate-50 hover:text-slate-800 transition-all duration-200">
                Сбросить фильтры
            </button>
            <button x-show="!isFiltered"
                    @click="showCreateModal = true; resetCreateForm()"
                    type="button"
                    class="inline-flex items-center px-5 py-2.5 bg-gradient-to-r from-violet-500 to-indigo-600 text-white text-sm font-medium rounded-xl shadow-lg shadow-indigo-500/25 hover:shadow-xl hover:shadow-indigo-500/30 transition-all duration-200">
                <svg class="-ml-1 mr-2 h-5 w-5" fill="none" viewBox="0 0 20 20" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" />
                </svg>
                Добавить клиента
            </button>
        </div>
    </div>

    <!-- Modal: Добавить клиента -->
    <div x-show="showCreateModal"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-50 overflow-y-auto"
         style="display: none;">
        <div class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm" @click="showCreateModal = false"></div>

        <div class="flex min-h-full items-center justify-center p-4">
            <div x-show="showCreateModal"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="opacity-0 translate-y-4 scale-95"
                 x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                 x-transition:leave="transition ease-in duration-200"
                 x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                 x-transition:leave-end="opacity-0 translate-y-4 scale-95"
                 @click.away="showCreateModal = false"
                 class="relative bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-hidden">

                <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
                    <h3 class="text-lg font-semibold text-slate-800">Добавить клиента</h3>
                    <button @click="showCreateModal = false" type="button" class="p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-all duration-150">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form action="{{ route('clients.store') }}" method="POST" class="overflow-y-auto max-h-[calc(90vh-140px)]">
                    @csrf
                    @if($errors->createClient->isNotEmpty())
                        <div class="mx-6 mt-4 p-4 bg-red-50 border border-red-200/50 rounded-xl">
                            <ul class="text-sm text-red-700 space-y-1">
                                @foreach($errors->createClient->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    <div class="px-6 py-6">
                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <label for="create_name" class="block text-sm font-medium text-slate-700 mb-2">Название компании <span class="text-red-500">*</span></label>
                                <input type="text" name="name" id="create_name" required value="{{ old('name') }}" class="block w-full px-4 py-2.5 border border-slate-200 rounded-xl bg-slate-50/50 text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 focus:bg-white transition-all duration-200" placeholder="ООО «Компания»">
                            </div>

                            <div>
                                <label for="create_inn" class="block text-sm font-medium text-slate-700 mb-2">ИНН <span class="text-red-500">*</span></label>
                                <input type="text" name="inn" id="create_inn" required maxlength="14" value="{{ old('inn') }}" class="block w-full px-4 py-2.5 border border-slate-200 rounded-xl bg-slate-50/50 text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 focus:bg-white transition-all duration-200 font-mono" placeholder="1234567890">
                                <p class="mt-1 text-xs text-slate-500">До 14 цифр</p>
                            </div>

                            <div>
                                <label for="create_tax_system_id" class="block text-sm font-medium text-slate-700 mb-2">Система налогообложения</label>
                                <select name="tax_system_id" id="create_tax_system_id" class="block w-full px-4 py-2.5 border border-slate-200 rounded-xl bg-slate-50/50 text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 focus:bg-white transition-all duration-200">
                                    <option value="">Не указана</option>
                                    <template x-for="ts in taxSystems" :key="ts.id">
                                        <option :value="ts.id" x-text="ts.name"></option>
                                    </template>
                                </select>
                            </div>

                            <div>
                                <label for="create_tariff_id" class="block text-sm font-medium text-slate-700 mb-2">Тарифный план</label>
                                <select name="tariff_id" id="create_tariff_id" class="block w-full px-4 py-2.5 border border-slate-200 rounded-xl bg-slate-50/50 text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 focus:bg-white transition-all duration-200">
                                    <option value="">Не выбран</option>
                                    <template x-for="tariff in tariffs" :key="tariff.id">
                                        <option :value="tariff.id" x-text="tariff.name"></option>
                                    </template>
                                </select>
                            </div>

                            <div class="sm:col-span-2">
                                <label for="create_responsible" class="block text-sm font-medium text-slate-700 mb-2">Ответственное лицо</label>
                                <select name="responsible_employee_id" id="create_responsible" class="block w-full px-4 py-2.5 border border-slate-200 rounded-xl bg-slate-50/50 text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 focus:bg-white transition-all duration-200">
                                    <option value="">Не назначено</option>
                                    <template x-for="employee in allEmployees" :key="employee.id">
                                        <option :value="employee.id" :selected="'{{ old('responsible_employee_id') }}' == employee.id" x-text="employee.name"></option>
                                    </template>
                                </select>
                                <p class="mt-1 text-xs text-slate-500">На это лицо будут ассайниться все БП клиента</p>
                            </div>

                            <div class="sm:col-span-2">
                                <label class="inline-flex items-center cursor-pointer">
                                    <input type="hidden" name="is_active" value="0">
                                    <input type="checkbox" name="is_active" value="1" checked class="sr-only peer">
                                    <div class="relative w-11 h-6 bg-slate-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-indigo-500/20 rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                                    <span class="ms-3 text-sm font-medium text-slate-700">Активный клиент</span>
                                </label>
                            </div>

                            <div class="sm:col-span-2">
                                <label for="create_notes" class="block text-sm font-medium text-slate-700 mb-2">Примечания</label>
                                <textarea name="notes" id="create_notes" rows="3" class="block w-full px-4 py-2.5 border border-slate-200 rounded-xl bg-slate-50/50 text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 focus:bg-white transition-all duration-200 resize-none" placeholder="Дополнительная информация о клиенте..."></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex justify-end gap-3">
                        <button @click="showCreateModal = false" type="button" class="inline-flex items-center px-5 py-2.5 border border-slate-200 rounded-xl text-sm font-medium text-slate-600 bg-white hover:bg-slate-50 hover:border-slate-300 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 transition-all duration-200">
                            Отмена
                        </button>
                        <button type="submit" class="inline-flex items-center px-5 py-2.5 bg-gradient-to-r from-violet-500 to-indigo-600 text-white text-sm font-medium rounded-xl shadow-lg shadow-indigo-500/25 hover:shadow-xl hover:shadow-indigo-500/30 transition-all duration-200">
                            <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                            </svg>
                            Создать клиента
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Редактировать клиента -->
    <div x-show="showEditModal"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-50 overflow-y-auto"
         style="display: none;">
        <div class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm" @click="showEditModal = false"></div>

        <div class="flex min-h-full items-center justify-center p-4">
            <div x-show="showEditModal"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="opacity-0 translate-y-4 scale-95"
                 x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                 x-transition:leave="transition ease-in duration-200"
                 x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                 x-transition:leave-end="opacity-0 translate-y-4 scale-95"
                 @click.away="showEditModal = false"
                 class="relative bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-hidden">

                <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
                    <h3 class="text-lg font-semibold text-slate-800">Редактировать клиента</h3>
                    <button @click="showEditModal = false" type="button" class="p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-all duration-150">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form :action="'/clients/' + editClient?.id" method="POST" class="overflow-y-auto max-h-[calc(90vh-140px)]">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="client_id" :value="editClient?.id">
                    @if($errors->updateClient->isNotEmpty())
                        <div class="mx-6 mt-4 p-4 bg-red-50 border border-red-200/50 rounded-xl">
                            <ul class="text-sm text-red-700 space-y-1">
                                @foreach($errors->updateClient->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    <div class="px-6 py-6">
                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <label for="edit_name" class="block text-sm font-medium text-slate-700 mb-2">Название компании <span class="text-red-500">*</span></label>
                                <input type="text" name="name" id="edit_name" :value="editClient?.name" required class="block w-full px-4 py-2.5 border border-slate-200 rounded-xl bg-slate-50/50 text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 focus:bg-white transition-all duration-200">
                            </div>

                            <div>
                                <label for="edit_inn" class="block text-sm font-medium text-slate-700 mb-2">ИНН <span class="text-red-500">*</span></label>
                                <input type="text" name="inn" id="edit_inn" :value="editClient?.inn" required maxlength="14" class="block w-full px-4 py-2.5 border border-slate-200 rounded-xl bg-slate-50/50 text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 focus:bg-white transition-all duration-200 font-mono">
                            </div>

                            <div>
                                <label for="edit_tax_system_id" class="block text-sm font-medium text-slate-700 mb-2">Система налогообложения</label>
                                <select name="tax_system_id" id="edit_tax_system_id" x-model="editClient.tax_system_id" class="block w-full px-4 py-2.5 border border-slate-200 rounded-xl bg-slate-50/50 text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 focus:bg-white transition-all duration-200">
                                    <option value="">Не указана</option>
                                    <template x-for="ts in taxSystems" :key="ts.id">
                                        <option :value="ts.id" x-text="ts.name"></option>
                                    </template>
                                </select>
                            </div>

                            <div>
                                <label for="edit_tariff_id" class="block text-sm font-medium text-slate-700 mb-2">Тарифный план</label>
                                <select name="tariff_id" id="edit_tariff_id" x-model="editClient.tariff_id" class="block w-full px-4 py-2.5 border border-slate-200 rounded-xl bg-slate-50/50 text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 focus:bg-white transition-all duration-200">
                                    <option value="">Не выбран</option>
                                    <template x-for="tariff in tariffs" :key="tariff.id">
                                        <option :value="tariff.id" x-text="tariff.name"></option>
                                    </template>
                                </select>
                            </div>

                            <div class="sm:col-span-2">
                                <label for="edit_responsible" class="block text-sm font-medium text-slate-700 mb-2">Ответственное лицо</label>
                                <select name="responsible_employee_id" id="edit_responsible" x-model="editClient.responsible_employee_id" class="block w-full px-4 py-2.5 border border-slate-200 rounded-xl bg-slate-50/50 text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 focus:bg-white transition-all duration-200">
                                    <option value="">Не назначено</option>
                                    <template x-for="employee in allEmployees" :key="employee.id">
                                        <option :value="employee.id" x-text="employee.name"></option>
                                    </template>
                                </select>
                                <p class="mt-1 text-xs text-slate-500">На это лицо будут ассайниться все БП клиента</p>
                            </div>

                            <div class="sm:col-span-2">
                                <label class="inline-flex items-center cursor-pointer">
                                    <input type="hidden" name="is_active" value="0">
                                    <input type="checkbox" name="is_active" value="1" :checked="editClient?.is_active" class="sr-only peer">
                                    <div class="relative w-11 h-6 bg-slate-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-indigo-500/20 rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                                    <span class="ms-3 text-sm font-medium text-slate-700">Активный клиент</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex justify-end gap-3">
                        <button @click="showEditModal = false" type="button" class="inline-flex items-center px-5 py-2.5 border border-slate-200 rounded-xl text-sm font-medium text-slate-600 bg-white hover:bg-slate-50 hover:border-slate-300 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 transition-all duration-200">
                            Отмена
                        </button>
                        <button type="submit" class="inline-flex items-center px-5 py-2.5 bg-gradient-to-r from-violet-500 to-indigo-600 text-white text-sm font-medium rounded-xl shadow-lg shadow-indigo-500/25 hover:shadow-xl hover:shadow-indigo-500/30 transition-all duration-200">
                            <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            Сохранить изменения
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Подтверждение удаления -->
    <div x-show="showDeleteModal"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-50 overflow-y-auto"
         style="display: none;">
        <div class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm" @click="showDeleteModal = false"></div>

        <div class="flex min-h-full items-center justify-center p-4">
            <div x-show="showDeleteModal"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="opacity-0 translate-y-4 scale-95"
                 x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                 x-transition:leave="transition ease-in duration-200"
                 x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                 x-transition:leave-end="opacity-0 translate-y-4 scale-95"
                 @click.away="showDeleteModal = false"
                 class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">

                <div class="pt-6 pb-4 text-center">
                    <div class="mx-auto w-14 h-14 bg-red-100 rounded-full flex items-center justify-center">
                        <svg class="w-7 h-7 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                </div>

                <div class="px-6 pb-6 text-center">
                    <h3 class="text-lg font-semibold text-slate-800 mb-2">Удалить клиента?</h3>
                    <p class="text-sm text-slate-500">
                        Вы уверены, что хотите удалить клиента <span class="font-medium text-slate-700" x-text="deleteClient?.name"></span>? Это действие нельзя отменить.
                    </p>
                </div>

                <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex justify-center gap-3">
                    <button @click="showDeleteModal = false" type="button" class="inline-flex items-center px-5 py-2.5 border border-slate-200 rounded-xl text-sm font-medium text-slate-600 bg-white hover:bg-slate-50 hover:border-slate-300 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 transition-all duration-200">
                        Отмена
                    </button>
                    <form :action="'/clients/' + deleteClient?.id" method="POST" class="inline">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="inline-flex items-center px-5 py-2.5 bg-gradient-to-r from-red-500 to-red-600 text-white text-sm font-medium rounded-xl shadow-lg shadow-red-500/25 hover:shadow-xl hover:shadow-red-500/30 transition-all duration-200">
                            <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                            Удалить
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Маска для ИНН (только цифры)
    function setupInnMask(input) {
        if (!input) return;
        input.addEventListener('input', function(e) {
            e.target.value = e.target.value.replace(/\D/g, '').slice(0, 14);
        });
    }

    setupInnMask(document.getElementById('create_inn'));
    setupInnMask(document.getElementById('edit_inn'));
});
</script>
@endsection
