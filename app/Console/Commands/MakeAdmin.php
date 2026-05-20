<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Role;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class MakeAdmin extends Command
{
    protected $signature = 'make:admin
                            {--email= : Email администратора}
                            {--name= : ФИО администратора}
                            {--password= : Пароль (минимум 8 символов)}';

    protected $description = 'Создать нового администратора системы';

    public function handle(): int
    {
        $this->info('');
        $this->info('╔══════════════════════════════════════╗');
        $this->info('║     Создание администратора ERP      ║');
        $this->info('╚══════════════════════════════════════╝');
        $this->info('');

        $adminRole = Role::where('name', 'admin')->first();

        if (!$adminRole) {
            $this->error('Роль admin не найдена. Сначала запустите миграции:');
            $this->line('  php artisan migrate');
            return Command::FAILURE;
        }

        // Получаем данные интерактивно или из опций
        $email = $this->option('email') ?? $this->ask('Email');
        $name = $this->option('name') ?? $this->ask('ФИО', 'Администратор');
        $password = $this->option('password') ?? $this->secret('Пароль (минимум 8 символов)');

        // Валидация
        $validator = Validator::make([
            'email' => $email,
            'full_name' => $name,
            'password' => $password,
        ], [
            'email' => ['required', 'email', 'unique:employees,email'],
            'full_name' => ['required', 'string', 'min:2'],
            'password' => ['required', 'string', 'min:8'],
        ], [
            'email.required' => 'Email обязателен',
            'email.email' => 'Введите корректный email',
            'email.unique' => 'Сотрудник с таким email уже существует',
            'full_name.required' => 'ФИО обязательно',
            'full_name.min' => 'ФИО должно быть минимум 2 символа',
            'password.required' => 'Пароль обязателен',
            'password.min' => 'Пароль должен быть минимум 8 символов',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }
            return Command::FAILURE;
        }

        // Создаём администратора
        $employee = Employee::create([
            'full_name' => $name,
            'position' => 'Администратор',
            'email' => $email,
            'password' => Hash::make($password),
            'role_id' => $adminRole->id,
            'status' => 'active',
        ]);

        $this->info('');
        $this->info('Администратор успешно создан!');
        $this->table(
            ['Поле', 'Значение'],
            [
                ['ID', $employee->id],
                ['ФИО', $employee->full_name],
                ['Email', $employee->email],
                ['Роль', 'Администратор'],
                ['Статус', 'Активен'],
            ]
        );
        $this->info('');
        $this->warn('Сохраните учётные данные в надёжном месте!');

        return Command::SUCCESS;
    }
}
