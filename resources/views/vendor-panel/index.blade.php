<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Аккаунты - Панель владельца</title>
    @vite('resources/css/app.css')
</head>
<body class="bg-slate-100 min-h-screen">
    <header class="bg-slate-900">
        <div class="max-w-5xl mx-auto px-6 h-16 flex items-center justify-between">
            <div class="flex items-baseline space-x-3">
                <span class="text-white font-semibold">Панель владельца</span>
                <span class="text-slate-400 text-sm">{{ auth('vendor')->user()->name }}</span>
            </div>
            <form action="{{ route('vendor.logout') }}" method="POST">
                @csrf
                <button type="submit" class="text-sm text-slate-300 hover:text-white transition-colors">Выйти</button>
            </form>
        </div>
    </header>

    <main class="max-w-5xl mx-auto px-6 py-10">
        <h1 class="text-2xl font-bold text-slate-800">Аккаунты</h1>
        <p class="mt-1 text-sm text-slate-500">
            Бухгалтерские фирмы, которые пользуются системой. Аккаунт-образец в списке не показан.
        </p>

        @if (session('status'))
            <div class="mt-6 rounded-xl bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
                {{ session('status') }}
            </div>
        @endif

        @error('tenant')
            <div class="mt-6 rounded-xl bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
                {{ $message }}
            </div>
        @enderror

        @if ($insideTenant)
            <div class="mt-6 rounded-xl bg-indigo-50 border border-indigo-200 px-4 py-3 text-sm text-indigo-800 flex items-center justify-between">
                <span>Вы сейчас работаете внутри аккаунта «{{ $insideTenant }}».</span>
                <form action="{{ route('vendor.leave') }}" method="POST">
                    @csrf
                    <button type="submit" class="font-semibold underline hover:no-underline">Выйти из аккаунта</button>
                </form>
            </div>
        @endif

        <div class="mt-6 bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-slate-500">
                        <tr>
                            <th class="text-left font-medium px-6 py-3">Фирма</th>
                            <th class="text-left font-medium px-6 py-3">Статус</th>
                            <th class="text-right font-medium px-6 py-3">Клиентов</th>
                            <th class="text-right font-medium px-6 py-3">Сотрудников</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($tenants as $tenant)
                            <tr class="hover:bg-slate-50/70">
                                <td class="px-6 py-4">
                                    <div class="font-medium text-slate-800">{{ $tenant->name }}</div>
                                    <div class="text-xs text-slate-400">{{ $tenant->slug }}</div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-medium
                                        {{ $tenant->isActive() ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                                        {{ $tenant->status }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right text-slate-700">{{ $tenant->clients_count }}</td>
                                <td class="px-6 py-4 text-right text-slate-700">{{ $tenant->employees_count }}</td>
                                <td class="px-6 py-4 text-right">
                                    <form action="{{ route('vendor.enter', $tenant) }}" method="POST">
                                        @csrf
                                        <button type="submit"
                                                class="px-4 py-2 rounded-lg text-xs font-semibold text-white bg-slate-800 hover:bg-slate-900 transition-colors">
                                            Войти в аккаунт
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-10 text-center text-slate-400">Пока ни одной фирмы</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <p class="mt-4 text-xs text-slate-400">
            Внутри аккаунта вы работаете его администратором, с полными правами. Через
            {{ \App\Support\Impersonation::IDLE_MINUTES }} минут без действий система выведет вас обратно сюда.
        </p>
    </main>
</body>
</html>
