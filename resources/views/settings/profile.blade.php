@extends('settings.layout')
@section('page-title', 'Профиль компании')

@section('settings-content')
{{-- Поля с цифрами чистим прямо при вводе: буква в ИНН или пробел в счёте
     уедут в акт клиенту, и там это заметят раньше нас. Серверная проверка
     всё равно стоит — фильтр в браузере лишь избавляет от лишнего шага. --}}
<div class="space-y-4" x-data="{
        digits(event, max) {
            const cleaned = event.target.value.replace(/\D+/g, '').slice(0, max);
            if (event.target.value !== cleaned) event.target.value = cleaned;
        },
        phone(event) {
            const cleaned = event.target.value.replace(/[^0-9 ()+\-]/g, '').slice(0, 30);
            if (event.target.value !== cleaned) event.target.value = cleaned;
        },
        letters(event) {
            const cleaned = event.target.value.replace(/[0-9]/g, '');
            if (event.target.value !== cleaned) event.target.value = cleaned;
        },
     }">

    @if (session('profile_saved'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            Сохранено
        </div>
    @endif

    @unless ($canEdit)
        {{-- Смотреть реквизиты своей фирмы полезно всем, менять — нет.
             Говорим об этом прямо, а не прячем кнопку молча. --}}
        <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
            Данные фирмы меняют руководитель и администратор. Здесь вы их видите, но не редактируете.
        </div>
    @endunless

    {{-- ЛОГОТИП ФИРМЫ — временно убран из интерфейса.
         В меню теперь знак системы (Kubik), а в сметы и акты логотип фирмы
         никогда и не подставлялся, так что показывать его было негде.
         Загрузку не удаляем: маршруты, контроллер и тесты живы, вернуть
         раздел = снять комментарий с блока ниже.

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h2 class="text-lg font-semibold text-slate-800">Логотип</h2>
            <p class="text-sm text-slate-500 mt-0.5">Появится в боковом меню и в шапке смет и актов для клиентов</p>
        </div>
        <div class="px-6 py-5 flex items-center gap-6">
            // Показываем ровно то, что видно сотрудникам в меню: либо логотип,
            // либо значок с буквой, который система рисует вместо него.
            <div class="flex flex-col items-center gap-2 flex-shrink-0">
                <div class="w-28 h-28 rounded-2xl border border-dashed border-slate-200 flex items-center justify-center bg-slate-50 overflow-hidden">
                    @if ($tenant->logoUrl())
                        <img src="{{ $tenant->logoUrl() }}" alt="{{ $tenant->name }}" class="max-w-full max-h-full object-contain">
                    @else
                        <span class="w-16 h-16 rounded-2xl bg-gradient-to-br from-violet-500 to-indigo-600 text-white flex items-center justify-center text-2xl font-semibold">{{ $tenant->initial() }}</span>
                    @endif
                </div>
                @unless ($tenant->logoUrl())
                    <span class="text-xs text-slate-400">Логотип не загружен</span>
                @endunless
            </div>

            @if ($canEdit)
                <div class="flex-1">
                    @error('logo')
                        <p class="mb-2 text-sm text-rose-600">{{ $message }}</p>
                    @enderror

                    <form action="{{ route('settings.profile.logo') }}" method="POST" enctype="multipart/form-data" class="flex items-center gap-3">
                        @csrf
                        <input type="file" name="logo" accept=".png,.jpg,.jpeg,.webp" required
                               class="block text-sm text-slate-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200 cursor-pointer">
                        <button type="submit"
                                class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors whitespace-nowrap">
                            Загрузить
                        </button>
                    </form>

                    <p class="text-xs text-slate-400 mt-2">PNG, JPG или WEBP, до 1 МБ. Лучше квадратный, на прозрачном фоне.</p>

                    @if ($tenant->logo_path)
                        <form action="{{ route('settings.profile.logo.destroy') }}" method="POST" class="mt-2">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs text-slate-400 hover:text-rose-600 underline decoration-dotted">
                                Удалить логотип
                            </button>
                        </form>
                    @endif
                </div>
            @endif
        </div>
    </div>
    --}}

    <form action="{{ route('settings.profile.update') }}" method="POST" class="space-y-4">
        @csrf
        @method('PUT')

        {{-- Название --}}
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100">
                <h2 class="text-lg font-semibold text-slate-800">Название</h2>
                <p class="text-sm text-slate-500 mt-0.5">Короткое видят сотрудники в системе, полное уходит в документы</p>
            </div>
            <div class="px-6 py-5 grid gap-5 md:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Название в системе <span class="text-rose-500">*</span></label>
                    <input type="text" name="name" value="{{ old('name', $tenant->name) }}" @disabled(!$canEdit) required maxlength="255"
                           class="block w-full px-3 py-2 border rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 disabled:bg-slate-50 disabled:text-slate-500 @error('name') border-rose-300 @else border-slate-200 @enderror">
                    @error('name')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @else
                        <p class="text-xs text-slate-400 mt-1">Показывается в боковом меню</p>
                    @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Полное юридическое название</label>
                    <input type="text" name="legal_name" value="{{ old('legal_name', $tenant->legal_name) }}" @disabled(!$canEdit) maxlength="255"
                           placeholder="ОсОО «Название»"
                           class="block w-full px-3 py-2 border rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 disabled:bg-slate-50 disabled:text-slate-500 @error('legal_name') border-rose-300 @else border-slate-200 @enderror">
                    @error('legal_name')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @else
                        <p class="text-xs text-slate-400 mt-1">Если пусто, в документах будет название из системы</p>
                    @enderror
                </div>
            </div>
        </div>

        {{-- Реквизиты --}}
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100">
                <h2 class="text-lg font-semibold text-slate-800">Реквизиты</h2>
                <p class="text-sm text-slate-500 mt-0.5">Попадут в шапку смет и актов как данные исполнителя</p>
            </div>
            <div class="px-6 py-5 grid gap-5 md:grid-cols-3">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">ИНН</label>
                    <input type="text" name="inn" value="{{ old('inn', $tenant->inn) }}" @disabled(!$canEdit)
                           inputmode="numeric" maxlength="14" @if($canEdit) @input="digits($event, 14)" @endif
                           placeholder="14 цифр"
                           class="block w-full px-3 py-2 border rounded-xl text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 disabled:bg-slate-50 disabled:text-slate-500 @error('inn') border-rose-300 @else border-slate-200 @enderror">
                    @error('inn')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @else
                        <p class="text-xs text-slate-400 mt-1">Только цифры</p>
                    @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Телефон</label>
                    <input type="text" name="phone" value="{{ old('phone', $tenant->phone) }}" @disabled(!$canEdit)
                           inputmode="tel" maxlength="30" @if($canEdit) @input="phone($event)" @endif
                           placeholder="+996 700 000 000"
                           class="block w-full px-3 py-2 border rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 disabled:bg-slate-50 disabled:text-slate-500 @error('phone') border-rose-300 @else border-slate-200 @enderror">
                    @error('phone')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Электронная почта</label>
                    <input type="email" name="email" value="{{ old('email', $tenant->email) }}" @disabled(!$canEdit) maxlength="255"
                           placeholder="info@firma.kg"
                           class="block w-full px-3 py-2 border rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 disabled:bg-slate-50 disabled:text-slate-500 @error('email') border-rose-300 @else border-slate-200 @enderror">
                    @error('email')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Адрес</label>
                    <input type="text" name="address" value="{{ old('address', $tenant->address) }}" @disabled(!$canEdit) maxlength="255"
                           placeholder="г. Бишкек, ул. Примерная, 1"
                           class="block w-full px-3 py-2 border rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 disabled:bg-slate-50 disabled:text-slate-500 @error('address') border-rose-300 @else border-slate-200 @enderror">
                    @error('address')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Руководитель</label>
                    <input type="text" name="director_name" value="{{ old('director_name', $tenant->director_name) }}" @disabled(!$canEdit)
                           maxlength="255" @if($canEdit) @input="letters($event)" @endif
                           placeholder="Иванов И.И."
                           class="block w-full px-3 py-2 border rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 disabled:bg-slate-50 disabled:text-slate-500 @error('director_name') border-rose-300 @else border-slate-200 @enderror">
                    @error('director_name')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @else
                        <p class="text-xs text-slate-400 mt-1">Подписывает акты</p>
                    @enderror
                </div>
            </div>
        </div>

        {{-- Банк --}}
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100">
                <h2 class="text-lg font-semibold text-slate-800">Банковские реквизиты</h2>
                <p class="text-sm text-slate-500 mt-0.5">Уходят в подвал акта как реквизиты для оплаты. Можно не заполнять</p>
            </div>
            <div class="px-6 py-5 grid gap-5 md:grid-cols-3">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Банк</label>
                    <input type="text" name="bank_name" value="{{ old('bank_name', $tenant->bank_name) }}" @disabled(!$canEdit) maxlength="255"
                           class="block w-full px-3 py-2 border rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 disabled:bg-slate-50 disabled:text-slate-500 @error('bank_name') border-rose-300 @else border-slate-200 @enderror">
                    @error('bank_name')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Расчётный счёт</label>
                    <input type="text" name="bank_account" value="{{ old('bank_account', $tenant->bank_account) }}" @disabled(!$canEdit)
                           inputmode="numeric" maxlength="34" @if($canEdit) @input="digits($event, 34)" @endif
                           class="block w-full px-3 py-2 border rounded-xl text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 disabled:bg-slate-50 disabled:text-slate-500 @error('bank_account') border-rose-300 @else border-slate-200 @enderror">
                    @error('bank_account')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @else
                        <p class="text-xs text-slate-400 mt-1">Только цифры</p>
                    @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">БИК</label>
                    <input type="text" name="bank_bik" value="{{ old('bank_bik', $tenant->bank_bik) }}" @disabled(!$canEdit)
                           inputmode="numeric" maxlength="9" @if($canEdit) @input="digits($event, 9)" @endif
                           class="block w-full px-3 py-2 border rounded-xl text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 disabled:bg-slate-50 disabled:text-slate-500 @error('bank_bik') border-rose-300 @else border-slate-200 @enderror">
                    @error('bank_bik')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @else
                        <p class="text-xs text-slate-400 mt-1">6–9 цифр</p>
                    @enderror
                </div>
            </div>
        </div>

        @if ($canEdit)
            <div class="flex justify-end">
                <button type="submit"
                        class="px-5 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors">
                    Сохранить
                </button>
            </div>
        @endif
    </form>
</div>
@endsection
