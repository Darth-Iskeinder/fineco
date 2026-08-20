<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Регистрация фирмы - Kubik</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/kubik-icon.svg') }}">
    @vite('resources/css/app.css')
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>[x-cloak] { display: none !important; }</style>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-md w-full space-y-8">
        <div class="text-center">
            {{-- Знак системы, а не какой-то одной фирмы: логотип загружает
                 сама фирма, и внутри системы он подставляется в меню. --}}
            <div class="flex justify-center mb-6">
                <img src="{{ asset('images/kubik-vertical.svg') }}" alt="Kubik" class="h-20 w-auto">
            </div>
            <h2 class="text-3xl font-bold bg-gradient-to-r from-slate-800 to-slate-600 bg-clip-text text-transparent">
                Регистрация фирмы
            </h2>
            <p class="mt-2 text-sm text-slate-500">
                Заведём аккаунт вашей бухфирмы и вашу учётную запись
            </p>
        </div>

        <div class="bg-white py-8 px-6 shadow-xl shadow-slate-200/50 rounded-2xl border border-slate-200/50">
            <form action="{{ route('onboarding') }}" method="POST" class="space-y-6">
                @csrf

                <div>
                    <label for="company_name" class="block text-sm font-medium text-slate-700 mb-1.5">Название фирмы</label>
                    <input type="text"
                           name="company_name"
                           id="company_name"
                           value="{{ old('company_name') }}"
                           required
                           autofocus
                           class="block w-full px-4 py-3 border border-slate-200 rounded-xl shadow-sm text-slate-900 placeholder-slate-400
                                  focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all duration-200
                                  @error('company_name') border-red-300 focus:ring-red-500/20 focus:border-red-500 @enderror"
                           placeholder="ОсОО «Ромашка»">
                    <p class="mt-1.5 text-xs text-slate-400">Ваша бухгалтерская фирма, а не обслуживаемая компания</p>
                    @error('company_name')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="full_name" class="block text-sm font-medium text-slate-700 mb-1.5">Ваше ФИО</label>
                    <input type="text"
                           name="full_name"
                           id="full_name"
                           value="{{ old('full_name') }}"
                           required
                           autocomplete="name"
                           class="block w-full px-4 py-3 border border-slate-200 rounded-xl shadow-sm text-slate-900 placeholder-slate-400
                                  focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all duration-200
                                  @error('full_name') border-red-300 focus:ring-red-500/20 focus:border-red-500 @enderror"
                           placeholder="Иванова Анна Петровна">
                    <p class="mt-1.5 text-xs text-slate-400">Вы — владелец аккаунта: сможете добавить сотрудников и раздать доступы</p>
                    @error('full_name')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium text-slate-700 mb-1.5">Email</label>
                    <input type="email"
                           name="email"
                           id="email"
                           value="{{ old('email') }}"
                           required
                           autocomplete="email"
                           class="block w-full px-4 py-3 border border-slate-200 rounded-xl shadow-sm text-slate-900 placeholder-slate-400
                                  focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all duration-200
                                  @error('email') border-red-300 focus:ring-red-500/20 focus:border-red-500 @enderror"
                           placeholder="email@example.com">
                    <p class="mt-1.5 text-xs text-slate-400">С этим адресом вы будете входить в систему</p>
                    @error('email')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="phone" class="block text-sm font-medium text-slate-700 mb-1.5">
                        Телефон <span class="text-slate-400 font-normal">— необязательно</span>
                    </label>
                    <x-phone-field :value="old('phone')"
                                   :class="'block w-full pl-[100px] pr-4 py-3 border rounded-xl shadow-sm text-slate-900 placeholder-slate-400
                                            focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all duration-200 '
                                           . ($errors->has('phone')
                                               ? 'border-red-300 focus:ring-red-500/20 focus:border-red-500'
                                               : 'border-slate-200')" />
                    @error('phone')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div x-data="{ showPassword: false }" class="space-y-6">
                    <div>
                        <label for="password" class="block text-sm font-medium text-slate-700 mb-1.5">Пароль</label>
                        <div class="relative">
                            <input :type="showPassword ? 'text' : 'password'"
                                   name="password"
                                   id="password"
                                   required
                                   autocomplete="new-password"
                                   class="block w-full px-4 py-3 pr-12 border border-slate-200 rounded-xl shadow-sm text-slate-900 placeholder-slate-400
                                          focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all duration-200
                                          @error('password') border-red-300 focus:ring-red-500/20 focus:border-red-500 @enderror"
                                   placeholder="Минимум 8 символов">
                            <button type="button"
                                    @click="showPassword = !showPassword"
                                    class="absolute inset-y-0 right-0 flex items-center pr-4 text-slate-400 hover:text-slate-600 transition-colors duration-150"
                                    :aria-label="showPassword ? 'Скрыть пароль' : 'Показать пароль'">
                                <svg x-show="!showPassword" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                </svg>
                                <svg x-show="showPassword" x-cloak class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                                </svg>
                            </button>
                        </div>
                        @error('password')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="password_confirmation" class="block text-sm font-medium text-slate-700 mb-1.5">Повторите пароль</label>
                        <input :type="showPassword ? 'text' : 'password'"
                               name="password_confirmation"
                               id="password_confirmation"
                               required
                               autocomplete="new-password"
                               class="block w-full px-4 py-3 border border-slate-200 rounded-xl shadow-sm text-slate-900 placeholder-slate-400
                                      focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all duration-200"
                               placeholder="Ещё раз тот же пароль">
                    </div>
                </div>

                {{-- Кем регистрирующий войдёт в свою фирму. По умолчанию — администратор:
                     он заводит сотрудников и настраивает систему. Руководитель умеет то же
                     самое, но ещё видит дашборд по фирме. В админке эта роль не выдаётся,
                     поэтому выбрать её можно только здесь. --}}
                <label for="as_manager" class="flex items-start gap-3 p-4 rounded-xl border border-slate-200 cursor-pointer hover:bg-slate-50 transition-colors duration-150">
                    <input type="checkbox"
                           name="as_manager"
                           id="as_manager"
                           value="1"
                           @checked(old('as_manager'))
                           class="mt-0.5 h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500/40">
                    <span class="text-sm">
                        <span class="block font-medium text-slate-700">Я руководитель фирмы</span>
                        <span class="block mt-1 text-slate-500">
                            Кроме прав администратора откроется дашборд с показателями по фирме.
                            Без галочки аккаунт будет администратором.
                        </span>
                    </span>
                </label>

                <button type="submit"
                        class="w-full flex justify-center py-3 px-4 border border-transparent rounded-xl shadow-lg shadow-indigo-500/25
                               text-sm font-semibold text-white bg-gradient-to-r from-violet-500 to-indigo-600
                               hover:from-violet-600 hover:to-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500
                               transition-all duration-200 transform hover:scale-[1.02]">
                    Создать аккаунт
                </button>

                <p class="text-center text-sm text-slate-500">
                    Уже есть аккаунт?
                    <a href="{{ route('login') }}" class="font-medium text-indigo-600 hover:text-indigo-700">Войти</a>
                </p>
            </form>
        </div>

        <p class="text-center text-xs text-slate-400">
            Kubik &copy; {{ date('Y') }}
        </p>
    </div>
</body>
</html>
