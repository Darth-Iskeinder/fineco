<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title') - Панель владельца</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/kubik-icon.svg') }}">
    @vite('resources/css/app.css')
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>[x-cloak] { display: none !important; }</style>
</head>
{{-- Панель нарочно тёмная: чтобы с одного взгляда было видно, что это не
     рабочая система фирмы, а служебный экран владельца. --}}
<body class="bg-slate-950 min-h-screen text-slate-200 antialiased">
    <header class="border-b border-slate-800 bg-slate-900/60 backdrop-blur">
        <div class="max-w-5xl mx-auto px-6 h-16 flex items-center justify-between">
            <div class="flex items-baseline space-x-3">
                <span class="text-white font-semibold">Панель владельца</span>
                <span class="text-slate-500 text-sm">{{ auth('vendor')->user()->name }}</span>
            </div>

            <div class="flex items-center gap-6">
                @php($tab = 'text-sm transition-colors')
                <a href="{{ route('vendor.index') }}"
                   class="{{ $tab }} {{ request()->routeIs('vendor.index') ? 'text-white font-medium' : 'text-slate-400 hover:text-white' }}">
                    Аккаунты
                </a>

                {{-- Счётчик рядом со ссылкой — то, ради чего журнал и заведён:
                     поломку видно из панели, без захода в него. --}}
                <a href="{{ route('vendor.errors.index') }}"
                   class="{{ $tab }} flex items-center gap-2 {{ request()->routeIs('vendor.errors.*') ? 'text-white font-medium' : 'text-slate-400 hover:text-white' }}">
                    Сбои
                    @if ($unresolvedErrorCount > 0)
                        <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-red-500/15 text-red-300 border border-red-500/30">
                            {{ $unresolvedErrorCount }}
                        </span>
                    @endif
                </a>

                <form action="{{ route('vendor.logout') }}" method="POST">
                    @csrf
                    <button type="submit" class="text-sm text-slate-400 hover:text-white transition-colors">Выйти</button>
                </form>
            </div>
        </div>
    </header>

    @yield('content')
</body>
</html>
