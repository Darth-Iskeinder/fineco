<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Профиль фирмы: как она называется, как выглядит и какими реквизитами
 * представляется клиентам в актах и сметах.
 *
 * Смотреть может любой, кто допущен в настройки, — сотруднику полезно видеть
 * реквизиты своей фирмы. Менять — только руководитель и админ: название и
 * логотип видят все, а реквизиты уходят в документы наружу.
 */
class CompanyProfileController extends Controller
{
    /** Закрытый диск: логотип отдаём своим маршрутом, а не из public. */
    private const LOGO_DISK = 'local';

    public function show()
    {
        return view('settings.profile', [
            'tenant'  => $this->tenant(),
            'canEdit' => $this->canEdit(),
        ]);
    }

    public function update(Request $request)
    {
        abort_unless($this->canEdit(), 403);

        $tenant = $this->tenant();

        // Числовые поля проверяем по существу, а не «строка до N символов»:
        // ИНН с буквой или счёт с пробелом уедут в акт клиенту и всплывут там.
        $validated = $request->validate([
            'name'          => ['required', 'string', 'max:255'],
            'legal_name'    => ['nullable', 'string', 'max:255'],
            'inn'           => ['nullable', 'digits:14'],
            'address'       => ['nullable', 'string', 'max:255'],
            'phone'         => ['nullable', 'string', 'max:30', 'regex:/^[0-9 ()+\-]+$/'],
            'email'         => ['nullable', 'email', 'max:255'],
            // ФИО: буквы, пробел, дефис, точка, апостроф. Цифрам в имени не место.
            'director_name' => ['nullable', 'string', 'max:255', 'regex:/^[\p{L}\s.\'\-]+$/u'],
            'bank_name'     => ['nullable', 'string', 'max:255'],
            'bank_account'  => ['nullable', 'digits_between:10,34'],
            'bank_bik'      => ['nullable', 'digits_between:6,9'],
        ], [
            'name.required'          => 'Название фирмы обязательно',
            'email.email'            => 'Проверьте адрес почты',
            'inn.digits'             => 'ИНН — это 14 цифр',
            'phone.regex'            => 'Телефон: только цифры, пробелы и знаки + ( ) -',
            'phone.max'              => 'Телефон длиннее 30 знаков',
            'director_name.regex'    => 'Имя пишется буквами, без цифр',
            'bank_account.digits_between' => 'Расчётный счёт — от 10 до 34 цифр',
            'bank_bik.digits_between'     => 'БИК — от 6 до 9 цифр',
        ]);

        $tenant->update($validated);

        return back()->with('profile_saved', true);
    }

    /** Загрузка логотипа отдельным действием: файл сохраняется сразу, без «Сохранить». */
    public function updateLogo(Request $request)
    {
        abort_unless($this->canEdit(), 403);

        $request->validate([
            // SVG намеренно нет: он умеет исполнять скрипты, а логотип отдаётся
            // по прямой ссылке с нашего домена.
            'logo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
        ], [
            'logo.mimes' => 'Логотип должен быть PNG, JPG или WEBP',
            'logo.max'   => 'Файл больше 1 МБ',
        ]);

        $tenant = $this->tenant();

        $this->deleteLogoFile($tenant);

        $path = $request->file('logo')->store('tenants/' . $tenant->id, self::LOGO_DISK);
        $tenant->update(['logo_path' => $path]);

        return back()->with('profile_saved', true);
    }

    /**
     * Отдать логотип своей фирмы.
     *
     * Отдельный маршрут вместо ссылки в public: диск закрытый, а чужой логотип
     * недоступен просто потому, что путь берётся из фирмы вошедшего.
     */
    public function logo()
    {
        $tenant = $this->tenant();

        abort_unless($tenant->logo_path && Storage::disk(self::LOGO_DISK)->exists($tenant->logo_path), 404);

        return Storage::disk(self::LOGO_DISK)->response($tenant->logo_path, null, [
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function destroyLogo()
    {
        abort_unless($this->canEdit(), 403);

        $tenant = $this->tenant();

        $this->deleteLogoFile($tenant);
        $tenant->update(['logo_path' => null]);

        return back()->with('profile_saved', true);
    }

    /**
     * Своя фирма.
     *
     * Через TenantContext, а не через сотрудника напрямую: если однажды один
     * человек сможет работать в нескольких фирмах, менять придётся одно место.
     */
    private function tenant(): Tenant
    {
        return Tenant::findOrFail(TenantContext::id());
    }

    private function canEdit(): bool
    {
        $employee = auth('employee')->user();

        return $employee && ($employee->isAdmin() || $employee->isManager());
    }

    private function deleteLogoFile(Tenant $tenant): void
    {
        if ($tenant->logo_path) {
            Storage::disk(self::LOGO_DISK)->delete($tenant->logo_path);
        }
    }
}
