<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Панель владельца - ERP</title>
    @vite('resources/css/app.css')
</head>
{{-- Тёмный экран — чтобы не спутать со входом сотрудника: это разные двери. --}}
<body class="bg-slate-950 min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8 antialiased">
    <div class="max-w-md w-full space-y-8">
        <div class="text-center">
            <h2 class="text-3xl font-bold text-white">Панель владельца</h2>
            <p class="mt-2 text-sm text-slate-500">
                Вход для обслуживания системы, не для работы в фирме
            </p>
        </div>

        <div class="bg-slate-900 py-8 px-6 rounded-2xl border border-slate-800 shadow-2xl shadow-black/40">
            <form action="{{ route('vendor.login') }}" method="POST" class="space-y-6">
                @csrf

                <div>
                    <label for="email" class="block text-sm font-medium text-slate-300 mb-1.5">Email</label>
                    <input type="email"
                           name="email"
                           id="email"
                           value="{{ old('email') }}"
                           required
                           autocomplete="email"
                           autofocus
                           class="block w-full px-4 py-3 bg-slate-950 border border-slate-800 rounded-xl text-slate-100 placeholder-slate-600
                                  focus:outline-none focus:ring-2 focus:ring-slate-500/30 focus:border-slate-600 transition-all duration-200
                                  @error('email') border-red-500/50 focus:ring-red-500/20 focus:border-red-500 @enderror"
                           placeholder="email@example.com">
                    @error('email')
                        <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-slate-300 mb-1.5">Пароль</label>
                    <input type="password"
                           name="password"
                           id="password"
                           required
                           autocomplete="current-password"
                           class="block w-full px-4 py-3 bg-slate-950 border border-slate-800 rounded-xl text-slate-100 placeholder-slate-600
                                  focus:outline-none focus:ring-2 focus:ring-slate-500/30 focus:border-slate-600 transition-all duration-200
                                  @error('password') border-red-500/50 focus:ring-red-500/20 focus:border-red-500 @enderror"
                           placeholder="Введите пароль">
                    @error('password')
                        <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit"
                        class="w-full flex justify-center py-3 px-4 rounded-xl
                               text-sm font-semibold text-slate-900 bg-slate-100 hover:bg-white
                               focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-slate-900 focus:ring-slate-400
                               transition-all duration-200">
                    Войти
                </button>
            </form>
        </div>

        <p class="text-center text-xs text-slate-600">
            ERP &copy; {{ date('Y') }}
        </p>
    </div>
</body>
</html>
