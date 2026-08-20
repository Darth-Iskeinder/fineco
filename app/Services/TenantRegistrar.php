<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Role;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Регистрация новой бухфирмы: аккаунт + стартовый набор справочников + её админ.
 *
 * Всё тремя шагами в одной транзакции. Порядок важен: если стартовый набор
 * скопировать не удалось, аккаунт создавать не за чем — фирма вошла бы в пустую
 * систему, где нельзя завести ни одного клиента, и решила бы, что всё сломано.
 *
 * Пробного периода нет (решение 03.08.2026): аккаунт сразу рабочий. Статус
 * `trial` в таблице остаётся на будущее, под подписку.
 *
 * Первый сотрудник получает роль в зависимости от галочки в форме: без неё —
 * администратор (заводит людей и настраивает систему), с ней — руководитель
 * (то же самое плюс свой дашборд). Роль руководителя в админке не назначается
 * (её нет в списке ролей), поэтому регистрация — единственное место, где фирма
 * может выбрать её сама, не заходя в базу.
 */
class TenantRegistrar
{
    public function __construct(private TenantTemplate $template)
    {
    }

    /**
     * Заводит фирму и возвращает её первого сотрудника — владельца аккаунта.
     *
     * @param array{company_name: string, full_name: string, email: string, phone?: ?string, password: string, as_manager?: bool} $data
     */
    public function register(array $data): Employee
    {
        $roleName = !empty($data['as_manager']) ? Role::MANAGER : Role::ADMIN;
        $role = Role::where('name', $roleName)->first();

        if (!$role) {
            throw new RuntimeException("Роль {$roleName} не найдена — некому отдать новый аккаунт");
        }

        $position = $roleName === Role::MANAGER ? 'Руководитель' : 'Администратор';

        return DB::transaction(function () use ($data, $role, $position) {
            $tenant = Tenant::create([
                'name'   => $data['company_name'],
                'slug'   => $this->uniqueSlug($data['company_name']),
                'status' => Tenant::STATUS_ACTIVE,
            ]);

            $this->template->copyTo($tenant);

            // Сотрудник создаётся уже «внутри» новой фирмы: tenant_id проставит
            // трейт BelongsToTenant, в $fillable его нет и руками он не задаётся.
            return TenantContext::for($tenant, fn () => Employee::create([
                'full_name' => $data['full_name'],
                'position'  => $position,
                'email'     => $data['email'],
                'phone'     => $data['phone'] ?? null,
                'password'  => $data['password'],
                'role_id'   => $role->id,
                'status'    => Employee::STATUS_ACTIVE,
            ]));
        });
    }

    /**
     * Адрес аккаунта из названия фирмы.
     *
     * Названия кириллические, Str::slug их транслитерирует; если после этого не
     * осталось ни одной буквы (название из одних символов) — берём запасное имя.
     * Совпадения разводим номером: две «Ромашки» — обычное дело, и вторая не
     * должна упереться в уникальный индекс. Удалённые аккаунты тоже считаем:
     * строка остаётся в таблице, индекс её видит.
     */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'firma';
        $slug = $base;
        $suffix = 2;

        while (Tenant::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $suffix++;
        }

        return $slug;
    }
}
