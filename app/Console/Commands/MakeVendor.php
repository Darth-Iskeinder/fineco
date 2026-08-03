<?php

namespace App\Console\Commands;

use App\Models\VendorUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Завести владельца системы. Только отсюда: из интерфейса добавить себя
 * в этот список нельзя, иначе смысл отдельной проходной теряется.
 */
class MakeVendor extends Command
{
    protected $signature = 'make:vendor
                            {--email= : Email}
                            {--name= : Имя}
                            {--password= : Пароль (минимум 8 символов)}';

    protected $description = 'Создать владельца системы (вход в панель /vendor)';

    public function handle(): int
    {
        $this->info('');
        $this->info('╔══════════════════════════════════════╗');
        $this->info('║   Создание владельца системы         ║');
        $this->info('╚══════════════════════════════════════╝');
        $this->info('');

        $email    = $this->option('email') ?? $this->ask('Email');
        $name     = $this->option('name') ?? $this->ask('Имя', 'Владелец');
        $password = $this->option('password') ?? $this->secret('Пароль (минимум 8 символов)');

        $validator = Validator::make(compact('email', 'name', 'password'), [
            'email'    => ['required', 'email', 'unique:vendor_users,email'],
            'name'     => ['required', 'string', 'min:2'],
            'password' => ['required', 'string', 'min:8'],
        ], [
            'email.required'    => 'Email обязателен',
            'email.email'       => 'Введите корректный email',
            'email.unique'      => 'Владелец с таким email уже есть',
            'name.required'     => 'Имя обязательно',
            'name.min'          => 'Имя должно быть минимум 2 символа',
            'password.required' => 'Пароль обязателен',
            'password.min'      => 'Пароль должен быть минимум 8 символов',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return Command::FAILURE;
        }

        $vendor = VendorUser::create([
            'name'     => $name,
            'email'    => $email,
            'password' => $password,
        ]);

        $this->info('');
        $this->info('Готово. Вход в панель: /vendor/login');
        $this->table(['Поле', 'Значение'], [
            ['ID', $vendor->id],
            ['Имя', $vendor->name],
            ['Email', $vendor->email],
        ]);
        $this->info('');
        $this->warn('Это доступ ко всем фирмам сразу — храните пароль соответственно.');

        return Command::SUCCESS;
    }
}
