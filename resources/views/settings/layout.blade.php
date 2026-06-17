@extends('layouts.app')

@section('page-title', 'Настройки')

@section('content')
<div class="flex -m-6 min-h-[calc(100vh-4rem)]">

    {{-- Settings sidebar --}}
    <aside class="w-56 bg-white border-r border-slate-200 flex-shrink-0 sticky top-0 self-start overflow-y-auto"
           style="height: calc(100vh - 4rem);">
        <nav class="p-3 pt-4 space-y-0.5">
            <a href="{{ route('settings.tax-systems') }}"
               class="block px-3 py-2 rounded-lg text-sm transition-colors
                      {{ request()->routeIs('settings.tax-systems') ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                Режимы налогообложения
            </a>
            <a href="{{ route('settings.activity-types') }}"
               class="block px-3 py-2 rounded-lg text-sm transition-colors
                      {{ request()->routeIs('settings.activity-types') ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                Виды деятельности
            </a>
            <a href="{{ route('settings.tariffs') }}"
               class="block px-3 py-2 rounded-lg text-sm transition-colors
                      {{ request()->routeIs('settings.tariffs') ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                Тарифы
            </a>
            <a href="{{ route('settings.rates') }}"
               class="block px-3 py-2 rounded-lg text-sm transition-colors
                      {{ request()->routeIs('settings.rates') ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                Справочник ставок
            </a>
            <a href="{{ route('settings.services') }}"
               class="block px-3 py-2 rounded-lg text-sm transition-colors
                      {{ request()->routeIs('settings.services') ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                Бизнес процессы
            </a>
            <a href="{{ route('settings.organization-forms') }}"
               class="block px-3 py-2 rounded-lg text-sm transition-colors
                      {{ request()->routeIs('settings.organization-forms') ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                Форма/тип организации
            </a>
            <a href="{{ route('settings.client-statuses') }}"
               class="block px-3 py-2 rounded-lg text-sm transition-colors
                      {{ request()->routeIs('settings.client-statuses') ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                Статус клиента
            </a>
            <a href="{{ route('settings.taxpayer-categories') }}"
               class="block px-3 py-2 rounded-lg text-sm transition-colors
                      {{ request()->routeIs('settings.taxpayer-categories') ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                Категория налогоплательщика
            </a>
            <a href="{{ route('settings.accounting-methods') }}"
               class="block px-3 py-2 rounded-lg text-sm transition-colors
                      {{ request()->routeIs('settings.accounting-methods') ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                Метод учёта
            </a>
            <a href="{{ route('settings.service-types') }}"
               class="block px-3 py-2 rounded-lg text-sm transition-colors
                      {{ request()->routeIs('settings.service-types') ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                Тип обслуживания
            </a>
            <a href="{{ route('settings.categories') }}"
               class="block px-3 py-2 rounded-lg text-sm transition-colors
                      {{ request()->routeIs('settings.categories') ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                Категория
            </a>
            <a href="{{ route('settings.spheres') }}"
               class="block px-3 py-2 rounded-lg text-sm transition-colors
                      {{ request()->routeIs('settings.spheres') ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                Сфера
            </a>
            <a href="{{ route('settings.groups') }}"
               class="block px-3 py-2 rounded-lg text-sm transition-colors
                      {{ request()->routeIs('settings.groups') ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                Группа
            </a>
            <a href="{{ route('settings.periodicities') }}"
               class="block px-3 py-2 rounded-lg text-sm transition-colors
                      {{ request()->routeIs('settings.periodicities') ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                Периодичность
            </a>
            <a href="{{ route('settings.check-types') }}"
               class="block px-3 py-2 rounded-lg text-sm transition-colors
                      {{ request()->routeIs('settings.check-types') ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                Проверка
            </a>
            <a href="{{ route('settings.billings') }}"
               class="block px-3 py-2 rounded-lg text-sm transition-colors
                      {{ request()->routeIs('settings.billings') ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                Биллинг
            </a>
        </nav>
    </aside>

    {{-- Page content --}}
    <div class="flex-1 p-6 overflow-y-auto">
        @yield('settings-content')
    </div>

</div>
@endsection
