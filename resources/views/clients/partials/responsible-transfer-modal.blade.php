{{-- Подтверждение смены ответственного у клиента.

     Смена ответственного забирает с собой незакрытую работу: позиции сметы, напоминания
     и внеплановые задачи прежнего сотрудника переезжают на нового (ClientResponsibleTransfer).
     Молча это делать нельзя — человек должен видеть, что именно переедет, до сохранения.

     Общий блок для списка клиентов и карточки: цифры считает сервер тем же кодом, что
     потом выполняет перенос, поэтому окно не может обещать одно, а сделать другое.

     Подключается двумя строками: подмешать `...responsibleTransferMixin()` в x-data
     компонента и вставить этот партиал в разметку. --}}

<script>
    function responsibleTransferMixin() {
        return {
            transfer: { open: false, loading: false, data: null, clientName: '', onConfirm: null },

            /**
             * Спросить подтверждение перед сменой ответственного.
             * $onConfirm вызывается, только если человек согласился.
             */
            async askResponsibleTransfer(clientId, employeeId, clientName, onConfirm) {
                this.transfer = { open: true, loading: true, data: null, clientName, onConfirm };

                try {
                    const query = employeeId ? ('?employee_id=' + employeeId) : '';
                    const response = await fetch('/clients/' + clientId + '/responsible-preview' + query, {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    });

                    if (!response.ok) throw new Error('preview failed');

                    this.transfer.data = await response.json();
                    this.transfer.loading = false;
                } catch {
                    // Посчитать не вышло — не держим человека из-за справочной цифры:
                    // сохраняем как раньше, перенос всё равно сделает сервер.
                    this.transfer.open = false;
                    this.transfer.onConfirm = null;
                    onConfirm();
                }
            },

            confirmResponsibleTransfer() {
                const proceed = this.transfer.onConfirm;
                this.transfer.open = false;
                this.transfer.onConfirm = null;
                if (proceed) proceed();
            },

            cancelResponsibleTransfer() {
                this.transfer.open = false;
                this.transfer.onConfirm = null;
            },

            /** «2 задачи» вместо «2 задача»: без склонения окно читается как машинное. */
            transferPlural(count, one, few, many) {
                const mod10 = count % 10;
                const mod100 = count % 100;
                if (mod10 === 1 && mod100 !== 11) return count + ' ' + one;
                if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return count + ' ' + few;
                return count + ' ' + many;
            },
        };
    }
</script>

<div x-show="transfer.open"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     {{-- click.stop: под этим окном открыта модалка правки со своим @click.away —
          без остановки всплытия клик по «Отмена» закрывал бы заодно и её. --}}
     @click.stop
     class="fixed inset-0 z-[60] overflow-y-auto"
     style="display: none;">
    <div class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm" @click="cancelResponsibleTransfer()"></div>

    <div class="flex min-h-full items-center justify-center p-4">
        <div x-show="transfer.open"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 translate-y-4 scale-95"
             x-transition:enter-end="opacity-100 translate-y-0 scale-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100 translate-y-0 scale-100"
             x-transition:leave-end="opacity-0 translate-y-4 scale-95"
             class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden">

            <div class="flex items-center gap-3 px-6 py-4 border-b border-slate-100">
                <div class="w-10 h-10 bg-amber-100 rounded-full flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </div>
                <div>
                    <h3 class="text-lg font-semibold text-slate-800">Сменить ответственного?</h3>
                    <p class="text-xs text-slate-500" x-text="transfer.clientName"></p>
                </div>
            </div>

            <template x-if="transfer.loading">
                <div class="px-6 py-8 text-center text-sm text-slate-500">Считаем, что переедет…</div>
            </template>

            <template x-if="!transfer.loading && transfer.data">
                <div class="px-6 py-5 space-y-4">
                    <div class="flex items-center gap-2 text-sm">
                        <span class="px-2 py-1 rounded-lg bg-slate-100 text-slate-600"
                              x-text="transfer.data.from ? transfer.data.from.name : 'Не назначен'"></span>
                        <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                        <span class="px-2 py-1 rounded-lg bg-indigo-100 text-indigo-700 font-medium"
                              x-text="transfer.data.to ? transfer.data.to.name : 'Не назначен'"></span>
                    </div>

                    {{-- Ответственного сняли: переносить некому, и клиент выпадает из чужих списков. --}}
                    <template x-if="!transfer.data.to">
                        <div class="p-3 rounded-xl bg-amber-50 border border-amber-200/70 text-sm text-amber-800">
                            Без ответственного клиент пропадёт из БухСметы и задачника у всех, кроме админа
                            и руководителя. Задачи останутся на прежнем сотруднике.
                        </div>
                    </template>

                    <template x-if="transfer.data.to">
                        <div class="space-y-3">
                            {{-- Переносить нечего: вся работа по клиенту стоит на других
                                 сотрудниках. Пустой список «перейдёт: 0 позиций» только путал бы. --}}
                            <template x-if="!transfer.data.items && !transfer.data.reminders && !transfer.data.adhoc">
                                <p class="text-sm text-slate-600">
                                    Незакрытой работы за
                                    <span x-text="transfer.data.from ? transfer.data.from.name : 'прежним ответственным'"></span>
                                    нет — переносить нечего.
                                </p>
                            </template>

                            <template x-if="transfer.data.items || transfer.data.reminders || transfer.data.adhoc">
                                <div>
                                    <p class="text-sm font-medium text-slate-700 mb-2">
                                        Перейдёт к <span x-text="transfer.data.to.name"></span>:
                                    </p>
                                    <ul class="space-y-1 text-sm text-slate-600">
                                        <li x-show="transfer.data.items"
                                            x-text="'• ' + transferPlural(transfer.data.items, 'позиция сметы', 'позиции сметы', 'позиций сметы')"></li>
                                        <li x-show="transfer.data.reminders"
                                            x-text="'• ' + transferPlural(transfer.data.reminders, 'незакрытая задача', 'незакрытые задачи', 'незакрытых задач')"></li>
                                        <li x-show="transfer.data.adhoc"
                                            x-text="'• ' + transferPlural(transfer.data.adhoc, 'внеплановая задача', 'внеплановые задачи', 'внеплановых задач')"></li>
                                    </ul>
                                </div>
                            </template>

                            <template x-if="transfer.data.stays.length">
                                <div>
                                    <p class="text-sm font-medium text-slate-700 mb-2">Останется у других:</p>
                                    <ul class="space-y-1 text-sm text-slate-600">
                                        <template x-for="row in transfer.data.stays" :key="row.name">
                                            <li x-text="'• ' + row.name + ' — ' + transferPlural(row.count, 'позиция', 'позиции', 'позиций')"></li>
                                        </template>
                                    </ul>
                                </div>
                            </template>

                            <template x-if="transfer.data.review">
                                <p class="text-sm text-slate-600"
                                   x-text="transferPlural(transfer.data.review, 'задача ждёт', 'задачи ждут', 'задач ждут')
                                       + ' проверки — проверять будет ' + transfer.data.to.name + '.'"></p>
                            </template>

                            {{-- Исполнителей в смете назначает только главбух клиента или админ. --}}
                            <template x-if="!transfer.data.to_can_assign">
                                <div class="p-3 rounded-xl bg-amber-50 border border-amber-200/70 text-sm text-amber-800">
                                    <span x-text="transfer.data.to.name"></span> не главный бухгалтер, поэтому
                                    раздавать БП другим сотрудникам в смете этого клиента не сможет.
                                </div>
                            </template>
                        </div>
                    </template>

                    <p class="text-xs text-slate-400">
                        Выполненные задачи остаются в истории за теми, кто их делал.
                    </p>
                </div>
            </template>

            <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex justify-end gap-3">
                <button type="button" @click="cancelResponsibleTransfer()"
                        class="inline-flex items-center px-5 py-2.5 border border-slate-200 rounded-xl text-sm font-medium text-slate-600 bg-white hover:bg-slate-50 hover:border-slate-300 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 transition-all duration-200">
                    Отмена
                </button>
                <button type="button" @click="confirmResponsibleTransfer()" :disabled="transfer.loading"
                        class="inline-flex items-center px-5 py-2.5 bg-gradient-to-r from-violet-500 to-indigo-600 text-white text-sm font-medium rounded-xl shadow-lg shadow-indigo-500/25 hover:shadow-xl hover:shadow-indigo-500/30 transition-all duration-200"
                        :class="transfer.loading ? 'opacity-50' : ''">
                    <span x-text="transfer.data && !transfer.data.to ? 'Сохранить' : 'Перенести и сохранить'"></span>
                </button>
            </div>
        </div>
    </div>
</div>
