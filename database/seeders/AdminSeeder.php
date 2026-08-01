<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    use RunsInFirstTenant;

    public function run(): void
    {
        $this->inFirstTenant(function () {
            $adminRole = Role::where('name', 'admin')->first();

            if (!$adminRole) {
                $this->command->error('Роль admin не найдена. Сначала запустите миграции.');
                return;
            }

            // Проверяем, существует ли уже админ
            $existingAdmin = Employee::where('email', 'admin@erp-fineco.local')->first();

            if ($existingAdmin) {
                $this->command->info('Администратор уже существует: admin@erp-fineco.local');
                return;
            }

            Employee::create([
                'full_name' => 'Администратор',
                'position' => 'Системный администратор',
                'email' => 'admin@erp-fineco.local',
                'phone' => '+7 (000) 000-00-00',
                'password' => Hash::make('admin123'),
                'role_id' => $adminRole->id,
                'status' => 'active',
            ]);

            $this->command->info('Администратор создан:');
            $this->command->info('  Email: admin@erp-fineco.local');
            $this->command->info('  Пароль: admin123');
            $this->command->warn('  Не забудьте сменить пароль после первого входа!');
        });
    }
}
