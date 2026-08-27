@extends('layouts.app')

@section('page-title', 'Настройки')

@section('content')
{{--
    Меню настроек сворачивается: на страницах вроде «Бизнес процессы» таблица
    широкая, и эти 224 пикселя ей нужнее. Выбор запоминается в браузере:
    каждый раздел настроек это отдельная страница, и без памяти меню
    разворачивалось бы обратно при каждом переходе.
--}}
<div class="flex -m-6 min-h-[calc(100vh-4rem)]"
     x-data="{
        collapsed: false,
        init() {
            try { this.collapsed = localStorage.getItem('settingsMenuCollapsed') === '1'; } catch (e) {}
            this.$watch('collapsed', value => {
                try { localStorage.setItem('settingsMenuCollapsed', value ? '1' : '0'); } catch (e) {}
            });
        },
     }">

    {{-- Settings sidebar --}}
    {{-- w-56 стоит классом, а не только в :class: до загрузки Alpine меню должно
         иметь нормальную ширину, иначе при открытии страницы оно моргает. --}}
    <aside class="w-56 bg-white border-r border-slate-200 flex-shrink-0 sticky top-0 self-start overflow-y-auto"
           :class="collapsed ? 'w-10!' : ''"
           style="height: calc(100vh - 4rem);">

        {{-- Свёрнутое меню: только стрелка, чтобы вернуть его обратно. --}}
        <div x-show="collapsed" style="display: none" class="p-2 pt-4">
            <button @click="collapsed = false" type="button" title="Показать меню"
                    class="w-full flex justify-center p-1.5 rounded-lg text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 transition-colors">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </button>
        </div>

        <nav x-show="!collapsed" class="p-3 pt-4 space-y-0.5">
            <div class="flex justify-end pb-1">
                <button @click="collapsed = true" type="button" title="Скрыть меню"
                        class="p-1.5 rounded-lg text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </button>
            </div>
            {{-- Профиль первым: это про саму фирму, остальное — справочники --}}
            <a href="{{ route('settings.profile') }}"
               class="block px-3 py-2 rounded-lg text-sm transition-colors
                      {{ request()->routeIs('settings.profile') ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                Профиль компании
            </a>
            <div class="h-px bg-slate-100 my-2"></div>
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
            <a href="{{ route('settings.billings') }}"
               class="block px-3 py-2 rounded-lg text-sm transition-colors
                      {{ request()->routeIs('settings.billings') ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                Биллинг
            </a>
            <a href="{{ route('settings.tax-authorities') }}"
               class="block px-3 py-2 rounded-lg text-sm transition-colors
                      {{ request()->routeIs('settings.tax-authorities') ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                Коды налоговых органов
            </a>
        </nav>
    </aside>

    {{-- Page content.
         min-w-0 обязателен: без него флекс-колонка не может стать уже своего содержимого,
         широкая таблица распирает страницу целиком, и горизонтальная прокрутка утаскивает
         вбок всю вёрстку вместе с боковым меню. С ним прокручивается только сама таблица. --}}
    <div class="flex-1 min-w-0 p-6 overflow-y-auto">
        @yield('settings-content')
    </div>

</div>
@endsection
