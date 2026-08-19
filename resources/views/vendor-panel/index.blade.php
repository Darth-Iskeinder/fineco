@extends('layouts.vendor')

@section('title', 'Аккаунты')

@section('content')
    {{-- Заход в чужую фирму подтверждается вручную: одно случайное нажатие — и
         вендор правит боевые данные, думая, что смотрит свои. --}}
    <main class="max-w-5xl mx-auto px-6 py-10" x-data="{ confirming: null }">
        <h1 class="text-2xl font-bold text-white">Аккаунты</h1>
        <p class="mt-1 text-sm text-slate-500">
            Бухгалтерские фирмы, которые пользуются системой. Аккаунт-образец в списке не показан.
        </p>

        @if (session('status'))
            <div class="mt-6 rounded-xl bg-amber-500/10 border border-amber-500/30 px-4 py-3 text-sm text-amber-200">
                {{ session('status') }}
            </div>
        @endif

        @error('tenant')
            <div class="mt-6 rounded-xl bg-red-500/10 border border-red-500/30 px-4 py-3 text-sm text-red-300">
                {{ $message }}
            </div>
        @enderror

        @if ($insideTenant)
            <div class="mt-6 rounded-xl bg-amber-500/10 border border-amber-500/30 px-4 py-3 text-sm text-amber-200 flex items-center justify-between gap-4">
                <span>Вы сейчас работаете внутри аккаунта «{{ $insideTenant }}».</span>
                <form action="{{ route('vendor.leave') }}" method="POST">
                    @csrf
                    <button type="submit" class="font-semibold underline hover:no-underline whitespace-nowrap">Выйти из аккаунта</button>
                </form>
            </div>
        @endif

        <div class="mt-6 bg-slate-900 rounded-2xl border border-slate-800 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-900/80 text-slate-500 border-b border-slate-800">
                        <tr>
                            <th class="text-left font-medium px-6 py-3">Фирма</th>
                            <th class="text-left font-medium px-6 py-3">Статус</th>
                            <th class="text-right font-medium px-6 py-3">Клиентов</th>
                            <th class="text-right font-medium px-6 py-3">Сотрудников</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @forelse ($tenants as $tenant)
                            <tr class="hover:bg-slate-800/40 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="font-medium text-white">{{ $tenant->name }}</div>
                                    <div class="text-xs text-slate-500">{{ $tenant->slug }}</div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-medium
                                        {{ $tenant->isActive() ? 'bg-emerald-500/10 text-emerald-300 border border-emerald-500/20' : 'bg-slate-800 text-slate-400 border border-slate-700' }}">
                                        {{ $tenant->status }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right text-slate-300">{{ $tenant->clients_count }}</td>
                                <td class="px-6 py-4 text-right text-slate-300">{{ $tenant->employees_count }}</td>
                                <td class="px-6 py-4 text-right">
                                    <button type="button"
                                            @click="confirming = { name: @js($tenant->name), action: @js(route('vendor.enter', $tenant)) }"
                                            class="px-4 py-2 rounded-lg text-xs font-semibold text-slate-900 bg-slate-100 hover:bg-white transition-colors">
                                        Войти в аккаунт
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-10 text-center text-slate-600">Пока ни одной фирмы</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <p class="mt-4 text-xs text-slate-600">
            Внутри аккаунта вы работаете его администратором, с полными правами. Через
            {{ \App\Support\Impersonation::IDLE_MINUTES }} минут без действий система выведет вас обратно сюда.
        </p>

        <!-- Подтверждение входа в аккаунт -->
        <div x-show="confirming" x-cloak
             @keydown.escape.window="confirming = null"
             class="fixed inset-0 z-50 flex items-center justify-center px-4">
            <div class="absolute inset-0 bg-black/70" @click="confirming = null"></div>

            <div class="relative w-full max-w-md bg-slate-900 border border-slate-700 rounded-2xl shadow-2xl p-6">
                <h2 class="text-lg font-semibold text-white">
                    Войти в аккаунт «<span x-text="confirming?.name"></span>»?
                </h2>

                <p class="mt-3 text-sm text-slate-400">
                    Вы окажетесь внутри системы этой фирмы с полными правами администратора.
                    Всё, что вы там измените, изменится у неё по-настоящему.
                </p>

                <div class="mt-6 flex justify-end gap-3">
                    <button type="button"
                            @click="confirming = null"
                            class="px-4 py-2 rounded-lg text-sm font-medium text-slate-300 hover:text-white hover:bg-slate-800 transition-colors">
                        Отмена
                    </button>

                    <form method="POST" :action="confirming?.action">
                        @csrf
                        <button type="submit"
                                class="px-4 py-2 rounded-lg text-sm font-semibold text-slate-900 bg-amber-400 hover:bg-amber-300 transition-colors">
                            Да, войти
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </main>
@endsection
