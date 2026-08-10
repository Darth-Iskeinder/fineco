@extends('layouts.app')

@section('page-title', 'Настройки')

@section('content')
<div class="flex -m-6 min-h-[calc(100vh-4rem)]">

    {{-- Settings sidebar --}}
    <aside class="w-56 bg-white border-r border-slate-200 flex-shrink-0 sticky top-0 self-start overflow-y-auto"
           style="height: calc(100vh - 4rem);">
        <nav class="p-3 pt-4 space-y-0.5">
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
