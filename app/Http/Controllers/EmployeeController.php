<?php

namespace App\Http\Controllers;

use App\Models\BuhAdhocTask;
use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\EstimateItem;
use App\Models\Module;
use App\Models\Role;
use App\Services\EmployeeWorkTransfer;
use App\Support\KgPhone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeController extends Controller
{
    /** Телефон — девять национальных цифр: код страны в форме подписан у рамки. */
    private const PHONE_RULES = ['nullable', 'digits:9'];

    private const PHONE_MESSAGES = [
        'phone.digits' => 'Номер телефона — 9 цифр после +996, например 779 779 979',
    ];

    /**
     * Сводит присланный номер к девяти национальным цифрам — до валидации.
     *
     * Вставку из мессенджера («+996 779…», ведущий ноль) и старую запись из базы
     * («+996 (779) 779-979», её подставляет карточка) отбивать ошибкой формата
     * незачем: это тот же номер.
     */
    private function normalizePhone(Request $request): void
    {
        if ($request->has('phone')) {
            $request->merge(['phone' => KgPhone::digits($request->input('phone'))]);
        }
    }

    public function index(Request $request)
    {
        $employees = Employee::with(['role', 'modules'])
            ->search($request->search)
            ->orderBy('created_at', 'desc')
            ->paginate(15)
            ->withQueryString();

        return view('employees.index', [
            'employees' => $employees,
            'search' => $request->search,
            // Роль «Руководитель» назначается только напрямую в базе — в списках админки её не показываем
            'roles' => Role::where('name', '!=', Role::MANAGER)->get(),
            'modules' => Module::active()->ordered()->get(),
        ]);
    }

    public function show(Employee $employee)
    {
        $employee->load(['role', 'modules', 'clients']);

        return view('employees.show', [
            'employee' => $employee,
            'clients' => $this->clientsOfEmployee($employee),
            // Кому можно передать работу при увольнении: работающие, кроме него самого.
            'recipients' => $this->recipients($employee),
            // «Руководитель» в выборе роли не предлагается — её выдаёт только
            // регистрация фирмы. Но если сотрудник уже руководитель, роль обязана
            // быть в списке: иначе селект встанет на чужое значение и первое же
            // сохранение карточки молча снимет роль.
            'roles' => Role::where('name', '!=', Role::MANAGER)
                ->orWhere('id', $employee->role_id)
                ->get(),
            'modules' => Module::active()->ordered()->get(),
        ]);
    }

    /**
     * Компании, к которым сотрудник прикреплён.
     *
     * Прикрепление в системе оформляется тремя способами, и раньше профиль знал
     * только про один (команду клиента), из-за чего список был неполным:
     *   - ответственное лицо клиента (clients.responsible_employee_id);
     *   - исполнитель БП в смете (estimate_items.assignee_id);
     *   - сотрудник в команде клиента (client_employee).
     *
     * Фактически закрытые задачи источником не считаем: задачу может взять кто угодно
     * (в живом списке исполнителем становится тот, кто её открыл), и такой клиент
     * попадал бы в профиль случайного человека.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\Client>
     */
    private function clientsOfEmployee(Employee $employee)
    {
        $assigneeClientIds = EstimateItem::query()
            ->where('assignee_id', $employee->id)
            ->join('estimates', 'estimates.id', '=', 'estimate_items.estimate_id')
            ->distinct()
            ->pluck('estimates.client_id');

        $ids = Client::where('responsible_employee_id', $employee->id)->pluck('id')
            ->merge($assigneeClientIds)
            ->merge($employee->clients->pluck('id'))
            ->unique();

        if ($ids->isEmpty()) {
            return collect();
        }

        return Client::whereIn('id', $ids)
            ->orderBy('name')
            ->get(['id', 'name', 'inn'])
            ->map(fn (Client $client) => [
                'id'   => $client->id,
                'name' => $client->name,
                'inn'  => $client->inn,
            ])
            ->values();
    }

    public function search(Request $request)
    {
        $search = $request->get('q', '');

        $employees = Employee::with(['role'])
            ->search($search)
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(fn($e) => [
                'id' => $e->id,
                'full_name' => $e->full_name,
                'position' => $e->position,
                'email' => $e->email,
                'phone' => $e->phone,
                'role_name' => $e->role->display_name,
                'employment_status' => $e->employment_status,
            ]);

        return response()->json($employees);
    }

    public function store(Request $request)
    {
        $this->normalizePhone($request);

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:employees,email'],
            'phone' => self::PHONE_RULES,
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role_id' => ['required', 'exists:roles,id'],
            'modules' => ['array'],
            'modules.*' => ['exists:modules,id'],
        ], self::PHONE_MESSAGES + [
            'password.required' => 'Введите пароль',
            'password.min' => 'Пароль должен быть минимум 8 символов',
            'password.confirmed' => 'Пароли не совпадают',
        ]);

        $employee = Employee::create([
            'full_name' => $validated['full_name'],
            'position' => $validated['position'] ?? '',
            'email' => $validated['email'],
            'phone' => isset($validated['phone']) ? KgPhone::format($validated['phone']) : null,
            'password' => Hash::make($validated['password']),
            'role_id' => $validated['role_id'],
            'status' => Employee::STATUS_ACTIVE,
        ]);

        // Привязываем модули (если не админ)
        if (!$employee->isAdmin() && !empty($validated['modules'])) {
            $employee->modules()->sync($validated['modules']);
        }

        return redirect()
            ->route('employees.index')
            ->with('success', 'Сотрудник ' . $employee->full_name . ' успешно создан');
    }

    public function update(Request $request, Employee $employee)
    {
        $this->normalizePhone($request);

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'position' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('employees')->ignore($employee->id)],
            'phone' => self::PHONE_RULES,
            'role_id' => ['required', 'exists:roles,id'],
            'modules' => ['array'],
            'modules.*' => ['exists:modules,id'],
        ], self::PHONE_MESSAGES);

        $employee->update([
            'full_name' => $validated['full_name'],
            'position' => $validated['position'],
            'email' => $validated['email'],
            'phone' => isset($validated['phone']) ? KgPhone::format($validated['phone']) : null,
            'role_id' => $validated['role_id'],
        ]);

        // Обновляем модули (если не админ)
        if ($employee->isAdmin()) {
            $employee->modules()->detach();
        } else {
            $employee->modules()->sync($validated['modules'] ?? []);
        }

        return redirect()
            ->route('employees.index')
            ->with('success', 'Данные сотрудника обновлены');
    }

    public function updateSection(Request $request, Employee $employee)
    {
        $section = $request->input('section');

        $this->normalizePhone($request);

        switch ($section) {
            case 'info':
                $validated = $request->validate([
                    'full_name' => ['required', 'string', 'max:255'],
                    'role_id' => ['required', 'exists:roles,id'],
                    'email' => ['required', 'email', Rule::unique('employees')->ignore($employee->id)],
                    'phone' => self::PHONE_RULES,
                    'employee_number' => ['nullable', 'string', 'max:50'],
                ], self::PHONE_MESSAGES);
                $validated['phone'] = isset($validated['phone']) ? KgPhone::format($validated['phone']) : null;
                $employee->update($validated);
                // Роль «Администратор» имеет доступ ко всем модулям — снимаем индивидуальные.
                if ($employee->fresh()->isAdmin()) {
                    $employee->modules()->detach();
                }
                break;

            case 'personal':
                $validated = $request->validate([
                    'birth_date' => ['nullable', 'date'],
                    'hired_at' => ['nullable', 'date'],
                    // Без даты не видно, с какого дня человек не работает, и его
                    // прошлые закрытые задачи нельзя отличить от чужих.
                    'fired_at' => ['nullable', 'date', 'required_if:employment_status,' . Employee::EMPLOYMENT_FIRED],
                    'employment_status' => ['required', 'in:employed,fired'],
                    'recipient_id' => ['nullable', 'integer'],
                ], [
                    'fired_at.required_if' => 'Укажите дату увольнения',
                ]);
                $recipientId = $validated['recipient_id'] ?? null;
                unset($validated['recipient_id']);

                // Увольнение вместе с передачей работы, одной транзакцией: либо уволен
                // и всё передано, либо ничего. Иначе работа оставалась на уволенном.
                if ($validated['employment_status'] === Employee::EMPLOYMENT_FIRED && !$employee->isFired()) {
                    $recipient = $this->recipientFor($employee, $recipientId);

                    DB::transaction(function () use ($employee, $validated, $recipient) {
                        $employee->update($validated);
                        (new EmployeeWorkTransfer())->apply($employee, $recipient);
                    });
                    break;
                }

                $employee->update($validated);
                break;

            case 'access':
                $validated = $request->validate([
                    'modules' => ['array'],
                    'modules.*' => ['exists:modules,id'],
                ]);
                // Роль теперь редактируется в разделе «info»; здесь — только модули.
                if ($employee->isAdmin()) {
                    $employee->modules()->detach();
                } else {
                    $employee->modules()->sync($validated['modules'] ?? []);
                }
                break;

            default:
                return response()->json(['success' => false, 'message' => 'Неизвестный раздел'], 422);
        }

        $employee->load(['role', 'modules', 'clients']);

        return response()->json([
            'success' => true,
            'employee' => $this->formatEmployeeForJson($employee),
        ]);
    }

    /**
     * Что сейчас на сотруднике: для окна «Кому передать работу» перед увольнением.
     * Только чтение.
     */
    public function workPreview(Employee $employee)
    {
        return response()->json((new EmployeeWorkTransfer())->preview($employee));
    }

    /**
     * Передать работу уже уволенного. Для тех, кого уволили до появления окна
     * передачи, и на ком что-то осталось.
     */
    public function transferWork(Request $request, Employee $employee)
    {
        abort_unless($employee->isFired(), 422, 'Передать работу можно только у уволенного сотрудника');

        $recipient = $this->recipientFor($employee, $request->integer('recipient_id') ?: null);

        $result = DB::transaction(fn () => (new EmployeeWorkTransfer())->apply($employee, $recipient));

        $employee->load(['role', 'modules']);

        return response()->json([
            'success'  => true,
            'result'   => $result,
            'employee' => $this->formatEmployeeForJson($employee),
        ]);
    }

    /**
     * Кому передают работу. Нужен, только если на сотруднике есть живая работа;
     * получателем может быть лишь тот, кому вообще можно поручать (работает, учётка
     * открыта), и не он сам. Проверка через модель, то есть в пределах своей фирмы.
     */
    private function recipientFor(Employee $employee, ?int $recipientId): ?Employee
    {
        $needed = (new EmployeeWorkTransfer())->preview($employee)['needs_recipient'];

        if ($recipientId === null) {
            if ($needed) {
                throw ValidationException::withMessages([
                    'recipient_id' => 'Выберите, кому передать работу сотрудника',
                ]);
            }

            return null;
        }

        $recipient = Employee::assignable()->whereKeyNot($employee->id)->find($recipientId);

        if (!$recipient) {
            throw ValidationException::withMessages([
                'recipient_id' => 'Этому сотруднику нельзя передать работу: он уволен или его учётка закрыта',
            ]);
        }

        return $recipient;
    }

    /** @return \Illuminate\Support\Collection<int, array{id:int, full_name:string, role:?string}> */
    private function recipients(Employee $employee)
    {
        return Employee::assignable()
            ->whereKeyNot($employee->id)
            ->with('role:id,display_name')
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'role_id'])
            ->map(fn (Employee $e) => ['id' => $e->id, 'full_name' => $e->full_name, 'role' => $e->role?->display_name])
            ->values();
    }

    private function formatEmployeeForJson(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'full_name' => $employee->full_name,
            'position' => $employee->position,
            'email' => $employee->email,
            'phone' => $employee->phone,
            'employee_number' => $employee->employee_number,
            'birth_date' => $employee->birth_date?->format('Y-m-d'),
            'hired_at' => $employee->hired_at?->format('Y-m-d'),
            'fired_at' => $employee->fired_at?->format('Y-m-d'),
            'employment_status' => $employee->employment_status ?? 'employed',
            'role_id' => $employee->role_id,
            'role_name' => $employee->role?->display_name,
            'module_ids' => $employee->modules->pluck('id')->toArray(),
            'module_names' => $employee->modules->pluck('display_name')->toArray(),
            // Тот же список, что при открытии страницы: иначе после сохранения он
            // сжимался до одной команды клиента, а после передачи работы не менялся.
            'clients' => $this->clientsOfEmployee($employee)->toArray(),
            // Что осталось на уволенном: по этому карточка показывает «Передать работу».
            'work' => $employee->isFired() ? (new EmployeeWorkTransfer())->preview($employee) : null,
        ];
    }

    public function destroy(Employee $employee)
    {
        $name = $employee->full_name;
        $employee->delete();

        return redirect()
            ->route('employees.index')
            ->with('success', 'Сотрудник ' . $name . ' удалён');
    }
}
