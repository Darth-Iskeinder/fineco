<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'ERP Fineco')</title>
    {{-- Скомпилированный Tailwind (Vite). НЕ возвращать Play CDN (cdn.tailwindcss.com):
         он компилит CSS в браузере и пересканирует DOM на каждый рендер Alpine — это давало ~7с LCP. --}}
    @vite('resources/css/app.css')
    @stack('styles')
    <style>
        /* Smooth scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        ::-webkit-scrollbar-track {
            background: transparent;
        }
        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        /* Dropdown animation */
        .dropdown-enter {
            opacity: 0;
            transform: translateY(-8px) scale(0.96);
        }
        .dropdown-enter-active {
            opacity: 1;
            transform: translateY(0) scale(1);
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .dropdown-leave-active {
            opacity: 0;
            transform: translateY(-8px) scale(0.96);
            transition: all 0.15s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Alert animations */
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .alert-animate {
            animation: slideIn 0.3s ease-out;
        }
    </style>
</head>
<body class="bg-slate-50 min-h-screen antialiased">
    <div class="flex min-h-screen">
        <!-- Sidebar -->
        <aside class="w-72 bg-white border-r border-slate-200/80 flex flex-col fixed h-full shadow-sm">
            <!-- Logo -->
            <div class="h-16 flex items-center px-6 border-b border-slate-100">
                <a href="{{ route('employees.index') }}" class="flex items-center space-x-3 group">
                    <img src="{{ asset('images/Fineco-logo.png') }}" alt="Fineco" class="h-9 w-auto transition-transform duration-300 group-hover:scale-105">
                    <span class="text-lg font-semibold bg-gradient-to-r from-slate-800 to-slate-600 bg-clip-text text-transparent">ERP Fineco</span>
                </a>
            </div>

            <!-- Navigation -->
            <nav class="flex-1 px-4 py-6 space-y-1.5 overflow-y-auto">
                @php
                    $sidebarModules = \App\Models\Module::active()->ordered()->get();
                    $currentEmployee = auth('employee')->user();

                    $moduleIcons = [
                        'employees' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />',
                        'clients' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />',
                        'buhsmeta' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />',
                        'buhtasks' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />',
                        'audit' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />',
                        'settings' =>'<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />',
                    ];

                    // Модули с готовыми маршрутами
                    $activeRoutes = ['employees', 'clients', 'buhsmeta', 'buhtasks', 'audit'];
                @endphp

                @foreach($sidebarModules as $module)
                    @php
                        $hasAccess = $currentEmployee && $currentEmployee->hasAccessToModule($module->name);
                        $isActive = request()->routeIs($module->route . '.*');
                        $hasRoute = in_array($module->name, $activeRoutes);
                    @endphp
                    @if($module->name === 'settings') @continue @endif
                    {{-- Аудит пока только для руководителя (админ — служебный доступ) --}}
                    @if($module->name === 'audit' && !($currentEmployee && ($currentEmployee->isManager() || $currentEmployee->isAdmin()))) @continue @endif

                    @if($hasAccess)
                        @if($hasRoute)
                            <a href="{{ route($module->route . '.index') }}"
                               class="flex items-center px-4 py-3 rounded-xl text-sm font-medium transition-all duration-200
                                      @if($isActive)
                                          bg-gradient-to-r from-violet-500 to-indigo-600 text-white shadow-lg shadow-indigo-500/25
                                      @else
                                          text-slate-600 hover:bg-slate-100 hover:text-slate-900
                                      @endif">
                                <svg class="w-5 h-5 mr-3 transition-transform duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    {!! $moduleIcons[$module->name] ?? '' !!}
                                </svg>
                                {{ $module->display_name }}
                                @if($module->name === 'buhtasks' && ($sidebarUrgentCount ?? 0) > 0)
                                    <span class="ml-auto inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 text-xs font-bold text-white bg-red-500 rounded-full">
                                        {{ $sidebarUrgentCount > 99 ? '99+' : $sidebarUrgentCount }}
                                    </span>
                                @endif
                            </a>
                        @else
                            <div class="flex items-center px-4 py-3 rounded-xl text-sm font-medium text-slate-400 cursor-not-allowed transition-all duration-200">
                                <svg class="w-5 h-5 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    {!! $moduleIcons[$module->name] ?? '' !!}
                                </svg>
                                {{ $module->display_name }}
                                <span class="ml-auto text-xs bg-slate-100 text-slate-500 px-2 py-1 rounded-lg font-normal">Скоро</span>
                            </div>
                        @endif
                    @endif
                @endforeach

                {{-- Дашборд руководителя: вне системы модулей, виден только роли manager --}}
                @if($currentEmployee && $currentEmployee->isManager())
                    <a href="{{ route('dashboard.index') }}"
                       class="flex items-center px-4 py-3 rounded-xl text-sm font-medium transition-all duration-200
                              @if(request()->routeIs('dashboard.*'))
                                  bg-gradient-to-r from-violet-500 to-indigo-600 text-white shadow-lg shadow-indigo-500/25
                              @else
                                  text-slate-600 hover:bg-slate-100 hover:text-slate-900
                              @endif">
                        <svg class="w-5 h-5 mr-3 transition-transform duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                        </svg>
                        Руководитель
                    </a>
                @endif

                @if($currentEmployee && $currentEmployee->hasAccessToModule('settings'))
                    <div class="pt-4 mt-4 border-t border-slate-100">
                        <p class="px-4 mb-2 text-xs font-semibold text-slate-400 uppercase tracking-wider">Администрирование</p>
                        <a href="{{ route('settings.index') }}"
                           class="flex items-center px-4 py-3 rounded-xl text-sm font-medium transition-all duration-200
                                  @if(request()->routeIs('settings.*'))
                                      bg-gradient-to-r from-violet-500 to-indigo-600 text-white shadow-lg shadow-indigo-500/25
                                  @else
                                      text-slate-600 hover:bg-slate-100 hover:text-slate-900
                                  @endif">
                            <svg class="w-5 h-5 mr-3 transition-transform duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                {!! $moduleIcons['settings'] !!}
                            </svg>
                            Настройки
                        </a>
                    </div>
                @endif
            </nav>

            <!-- Support Block -->
            <div class="px-4 py-4 border-t border-slate-100">
                <div class="bg-gradient-to-br from-slate-50 to-slate-100 rounded-2xl p-4 border border-slate-200/50">
                    <div class="flex items-center mb-3">
                        <div class="w-8 h-8 bg-gradient-to-br from-violet-100 to-indigo-100 rounded-lg flex items-center justify-center mr-3">
                            <svg class="w-4 h-4 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z" />
                            </svg>
                        </div>
                        <span class="text-sm font-semibold text-slate-700">Техподдержка</span>
                    </div>
                    <p class="text-xs text-slate-500 mb-3 leading-relaxed">Возникли вопросы? Мы готовы помочь!</p>
                    <button disabled class="w-full text-center bg-slate-200 text-slate-400 text-sm font-medium py-2.5 px-4 rounded-xl cursor-not-allowed transition-all duration-200">
                        Написать
                    </button>
                </div>
            </div>
        </aside>

        <!-- Main Content -->
        <div class="flex-1 ml-72 flex flex-col">
            <!-- Header -->
            <header class="h-16 bg-white/80 backdrop-blur-xl border-b border-slate-200/50 flex items-center justify-between px-6 sticky top-0 z-10">
                <div>
                    <h1 class="text-lg font-semibold text-slate-800">@yield('page-title', 'ERP Fineco')</h1>
                </div>
                <div class="flex items-center space-x-4">
                    @auth('employee')
                        <!-- Profile Dropdown -->
                        <div class="relative" x-data="{ open: false }">
                            <button @click="open = !open"
                                    class="flex items-center space-x-3 p-2 rounded-xl hover:bg-slate-100 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-indigo-500/20">
                                <div class="w-9 h-9 bg-gradient-to-br from-violet-500 to-indigo-600 rounded-xl flex items-center justify-center shadow-lg shadow-indigo-500/20">
                                    <span class="text-sm font-semibold text-white">
                                        {{ mb_substr(auth('employee')->user()->full_name, 0, 1) }}
                                    </span>
                                </div>
                                <div class="text-left hidden sm:block">
                                    <p class="text-sm font-medium text-slate-700">{{ auth('employee')->user()->full_name }}</p>
                                    <p class="text-xs text-slate-400">{{ auth('employee')->user()->position ?? 'Сотрудник' }}</p>
                                </div>
                                <svg class="w-4 h-4 text-slate-400 transition-transform duration-200" :class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                </svg>
                            </button>

                            <!-- Dropdown Menu -->
                            <div x-show="open"
                                 x-transition:enter="transition ease-out duration-200"
                                 x-transition:enter-start="opacity-0 translate-y-2 scale-95"
                                 x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                                 x-transition:leave="transition ease-in duration-150"
                                 x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                                 x-transition:leave-end="opacity-0 translate-y-2 scale-95"
                                 @click.away="open = false"
                                 class="absolute right-0 mt-2 w-56 bg-white rounded-2xl shadow-xl shadow-slate-200/50 border border-slate-200/50 py-2 z-50"
                                 style="display: none;">
                                <div class="px-4 py-3 border-b border-slate-100">
                                    <p class="text-sm font-medium text-slate-700">{{ auth('employee')->user()->full_name }}</p>
                                    <p class="text-xs text-slate-400 mt-0.5">{{ auth('employee')->user()->email }}</p>
                                </div>
                                <div class="py-2">
                                    <a href="#" class="flex items-center px-4 py-2.5 text-sm text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition-colors duration-150">
                                        <svg class="w-4 h-4 mr-3 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                        </svg>
                                        Мой профиль
                                    </a>
                                    <a href="#" class="flex items-center px-4 py-2.5 text-sm text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition-colors duration-150">
                                        <svg class="w-4 h-4 mr-3 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        </svg>
                                        Настройки
                                    </a>
                                </div>
                                <div class="border-t border-slate-100 pt-2">
                                    <form action="{{ route('logout') }}" method="POST">
                                        @csrf
                                        <button type="submit" class="flex items-center w-full px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition-colors duration-150">
                                            <svg class="w-4 h-4 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                                            </svg>
                                            Выйти
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endauth
                </div>
            </header>

            <!-- Page Content -->
            <main class="flex-1 p-6">
                @if(session('success'))
                    <div class="mb-6 rounded-2xl bg-emerald-50 border border-emerald-200/50 p-4 alert-animate">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <div class="w-8 h-8 bg-emerald-100 rounded-xl flex items-center justify-center">
                                    <svg class="h-4 w-4 text-emerald-600" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-emerald-800">{{ session('success') }}</p>
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Ошибки валидации: без этого блока форма молча возвращала на страницу,
                     и выглядело так, будто действие прошло, хотя ничего не сохранилось --}}
                @if($errors->any())
                    <div class="mb-6 rounded-2xl bg-red-50 border border-red-200/50 p-4 alert-animate">
                        <div class="flex items-start">
                            <div class="flex-shrink-0">
                                <div class="w-8 h-8 bg-red-100 rounded-xl flex items-center justify-center">
                                    <svg class="h-4 w-4 text-red-600" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-red-800">
                                    {{ $errors->count() === 1 ? 'Не удалось сохранить' : 'Не удалось сохранить — исправьте ошибки:' }}
                                </p>
                                <ul class="mt-1 space-y-0.5">
                                    @foreach($errors->all() as $error)
                                        <li class="text-sm text-red-700">{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    </div>
                @endif

                @if(session('error'))
                    <div class="mb-6 rounded-2xl bg-red-50 border border-red-200/50 p-4 alert-animate">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <div class="w-8 h-8 bg-red-100 rounded-xl flex items-center justify-center">
                                    <svg class="h-4 w-4 text-red-600" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-red-800">{{ session('error') }}</p>
                            </div>
                        </div>
                    </div>
                @endif

                @yield('content')
            </main>
        </div>
    </div>

    <!-- Alpine.js for dropdown -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</body>
</html>
