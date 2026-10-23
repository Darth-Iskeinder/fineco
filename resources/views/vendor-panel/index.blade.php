@extends('layouts.vendor')

@section('title', 'Аккаунты')

@section('content')
    {{-- Заход в чужую фирму подтверждается вручную: одно случайное нажатие — и
         вендор правит боевые данные, думая, что смотрит свои. --}}
    {{-- Поиск в браузере, без запроса на сервер: фирм немного, и все они уже на странице. --}}
    @php($haystacks = $tenants->mapWithKeys(fn ($t) => [
        $t->id => mb_strtolower(implode(' ', [$t->name, $t->slug, $t->legal_name, $t->inn])),
    ]))
    <main class="max-w-6xl mx-auto px-6 py-10"
          x-data="{
              confirming: null,
              q: '',
              copiedId: null,
              haystacks: @js($haystacks),
              tenantMatches(id, text) {
                  const q = this.q.trim().toLowerCase();
                  return q === '' || String(id) === q || text.includes(q);
              },
              get foundCount() {
                  return Object.entries(this.haystacks).filter(([id, text]) => this.tenantMatches(id, text)).length;
              },
              copyId(id) {
                  navigator.clipboard?.writeText(String(id));
                  this.copiedId = id;
                  setTimeout(() => { if (this.copiedId === id) this.copiedId = null; }, 1200);
              },
          }">
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

        <div class="mt-6">
            <input type="search" x-model="q" placeholder="Поиск по названию, slug, ИНН или ID"
                   class="w-full sm:w-96 px-4 py-2 rounded-lg bg-slate-900 border border-slate-700 text-sm text-slate-200 placeholder-slate-600 focus:outline-none focus:border-slate-500">
        </div>

        <div class="mt-4 bg-slate-900 rounded-2xl border border-slate-800 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-900/80 text-slate-500 border-b border-slate-800">
                        <tr>
                            <th class="text-left font-medium px-6 py-3">ID</th>
                            <th class="text-left font-medium px-6 py-3">Фирма</th>
                            <th class="text-left font-medium px-6 py-3">Статус</th>
                            <th class="text-left font-medium px-6 py-3">Автоаудит</th>
                            <th class="text-right font-medium px-6 py-3">Клиентов</th>
                            <th class="text-right font-medium px-6 py-3">Сотрудников</th>
                            <th class="text-right font-medium px-6 py-3">Сбои</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @forelse ($tenants as $tenant)
                            <tr class="hover:bg-slate-800/40 transition-colors"
                                x-show="tenantMatches({{ $tenant->id }}, haystacks[{{ $tenant->id }}])">
                                {{-- ID нужен в командах на сервере (autoaudit:access и другие), поэтому копируется по клику. --}}
                                <td class="px-6 py-4">
                                    <button type="button" @click="copyId({{ $tenant->id }})" title="Скопировать ID"
                                            class="font-mono text-slate-300 hover:text-white transition-colors">
                                        <span x-show="copiedId !== {{ $tenant->id }}">#{{ $tenant->id }}</span>
                                        <span x-show="copiedId === {{ $tenant->id }}" x-cloak class="text-emerald-300 text-xs">скопирован</span>
                                    </button>
                                </td>
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
                                {{-- Флаги автоаудита. Раньше их было видно только через tinker на сервере. --}}
                                <td class="px-6 py-4">
                                    @php($auditFlags = array_filter([
                                        'страница'    => $tenant->autoAuditEnabled(),
                                        'находки'     => $tenant->autoAuditFindingsEnabled(),
                                        'по клиентам' => $tenant->autoAuditClientsEnabled(),
                                        'ночью'       => $tenant->autoAuditNightly(),
                                    ]))
                                    <div class="flex flex-wrap gap-1">
                                        @forelse ($auditFlags as $label => $on)
                                            <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-sky-500/10 text-sky-300 border border-sky-500/20">{{ $label }}</span>
                                        @empty
                                            <span class="text-xs text-slate-600">выключен</span>
                                        @endforelse
                                        @if ($tenant->autoAuditFrom())
                                            <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-slate-800 text-slate-400 border border-slate-700">с {{ $tenant->autoAuditFrom() }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-right text-slate-300">{{ $tenant->clients_count }}</td>
                                <td class="px-6 py-4 text-right text-slate-300">{{ $tenant->employees_count }}</td>
                                <td class="px-6 py-4 text-right">
                                    @if ($tenant->unresolved_errors_count > 0)
                                        <a href="{{ route('vendor.errors.index', ['tenant' => $tenant->id]) }}"
                                           title="Неразобранные сбои этой фирмы"
                                           class="px-2 py-0.5 rounded-full text-xs font-semibold bg-red-500/15 text-red-300 border border-red-500/30 hover:bg-red-500/25 transition-colors">
                                            {{ $tenant->unresolved_errors_count }}
                                        </a>
                                    @else
                                        <span class="text-slate-600">0</span>
                                    @endif
                                </td>
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
                                <td colspan="8" class="px-6 py-10 text-center text-slate-600">Пока ни одной фирмы</td>
                            </tr>
                        @endforelse
                        @if ($tenants->isNotEmpty())
                            <tr x-show="foundCount === 0" x-cloak>
                                <td colspan="8" class="px-6 py-10 text-center text-slate-600">Ничего не нашлось</td>
                            </tr>
                        @endif
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
