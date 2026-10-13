{{-- Решение руководителя по ответу бухгалтера: те же маршруты и проверки, что на «Все сверки».
     После решения страница возвращается туда, откуда нажали. Кнопка гаснет после первого
     нажатия: двойной клик давал два одинаковых решения. --}}
<div class="flex flex-wrap items-start gap-2 pt-1">
    <form method="POST" action="{{ route('auto-audit.findings.accept', $finding) }}" onsubmit="this.querySelector('button').disabled = true">
        @csrf
        <input type="hidden" name="result_id" value="{{ $result->id }}">
        <button type="submit" class="px-2.5 py-1 rounded-lg bg-emerald-50 text-[13px] text-emerald-700 font-semibold hover:bg-emerald-100 disabled:opacity-50 transition-colors">Принять</button>
    </form>
    {{-- Комментарий обязателен: бухгалтеру надо понять, что не так. --}}
    <details class="flex-1 min-w-[12rem]">
        <summary class="inline-block px-2.5 py-1 rounded-lg bg-red-50 text-[13px] text-red-700 font-semibold cursor-pointer" style="list-style: none">Не принято</summary>
        <form method="POST" action="{{ route('auto-audit.findings.reject', $finding) }}" class="mt-2 space-y-2" onsubmit="this.querySelector('button').disabled = true">
            @csrf
            <input type="hidden" name="result_id" value="{{ $result->id }}">
            <textarea name="body" rows="3" required maxlength="2000" placeholder="Что не так с ответом"
                      class="w-full rounded-lg border border-slate-200 text-[13px] px-2 py-1"></textarea>
            <button type="submit" class="px-2.5 py-1 rounded-lg bg-indigo-600 text-[13px] text-white font-semibold hover:bg-indigo-700 disabled:opacity-50 transition-colors">Отправить бухгалтеру</button>
        </form>
    </details>
</div>
