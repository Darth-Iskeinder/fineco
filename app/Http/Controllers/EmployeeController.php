<?php

namespace App\Http\Controllers;

use App\Models\BuhAdhocTask;
use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\EstimateItem;
use App\Models\Module;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
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
            'roles' => Role::where('name', '!=', Role::MANAGER)->get(),
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
        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:employees,email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role_id' => ['required', 'exists:roles,id'],
            'modules' => ['array'],
            'modules.*' => ['exists:modules,id'],
        ], [
            'password.required' => 'Введите пароль',
            'password.min' => 'Пароль должен быть минимум 8 символов',
            'password.confirmed' => 'Пароли не совпадают',
        ]);

        $employee = Employee::create([
            'full_name' => $validated['full_name'],
            'position' => $validated['position'] ?? '',
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
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
        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'position' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('employees')->ignore($employee->id)],
            'phone' => ['nullable', 'string', 'max:20'],
            'role_id' => ['required', 'exists:roles,id'],
            'modules' => ['array'],
            'modules.*' => ['exists:modules,id'],
        ]);

        $employee->update([
            'full_name' => $validated['full_name'],
            'position' => $validated['position'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
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

        switch ($section) {
            case 'info':
                $validated = $request->validate([
                    'full_name' => ['required', 'string', 'max:255'],
                    'role_id' => ['required', 'exists:roles,id'],
                    'email' => ['required', 'email', Rule::unique('employees')->ignore($employee->id)],
                    'phone' => ['nullable', 'string', 'max:20'],
                    'employee_number' => ['nullable', 'string', 'max:50'],
                ]);
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
                    'fired_at' => ['nullable', 'date'],
                    'employment_status' => ['required', 'in:employed,fired'],
                ]);
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
            'clients' => $employee->clients->map(fn($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'inn' => $c->inn,
            ])->values()->toArray(),
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
