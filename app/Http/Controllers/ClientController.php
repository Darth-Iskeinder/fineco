<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Tariff;
use App\Models\TaxSystem;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $clients = Client::with(['taxSystem', 'tariff', 'employees'])
            ->search($request->search)
            ->orderBy('created_at', 'desc')
            ->paginate(15)
            ->withQueryString();

        return view('clients.index', [
            'clients' => $clients,
            'search' => $request->search,
            'taxSystems' => TaxSystem::active()->ordered()->get(),
            'employees' => Employee::active()->orderBy('full_name')->get(),
            'tariffs' => Tariff::active()->ordered()->get(),
        ]);
    }

    public function search(Request $request)
    {
        $search = $request->get('q', '');

        $clients = Client::with(['taxSystem', 'tariff', 'employees'])
            ->search($search)
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(function ($client) {
                return [
                    'id' => $client->id,
                    'name' => $client->name,
                    'inn' => $client->inn,
                    'tax_system_id' => $client->tax_system_id,
                    'tax_system_name' => $client->taxSystem?->name ?? '—',
                    'tariff_id' => $client->tariff_id,
                    'tariff_name' => $client->tariff?->name ?? '—',
                    'is_active' => $client->is_active,
                    'employee_ids' => $client->employees->pluck('id')->toArray(),
                    'employees_list' => $client->employees->pluck('full_name')->implode(', ') ?: '—',
                ];
            });

        return response()->json($clients);
    }

    public function show(Client $client)
    {
        $client->load(['taxSystem', 'activityType', 'tariff', 'employees']);

        return view('clients.show', [
            'client' => $client,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'inn' => ['required', 'string', 'max:14', 'unique:clients,inn'],
            'tax_system_id' => ['nullable', 'exists:tax_systems,id'],
            'tariff_id' => ['nullable', 'exists:tariffs,id'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string'],
            'employees' => ['array'],
            'employees.*' => ['exists:employees,id'],
        ], [
            'inn.required' => 'Введите ИНН',
            'inn.unique' => 'Клиент с таким ИНН уже существует',
        ]);

        $client = Client::create([
            'name' => $validated['name'],
            'inn' => $validated['inn'],
            'tax_system_id' => $validated['tax_system_id'] ?? null,
            'tariff_id' => $validated['tariff_id'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'notes' => $validated['notes'] ?? null,
        ]);

        if (!empty($validated['employees'])) {
            $client->employees()->sync($validated['employees']);
        }

        return redirect()
            ->route('clients.show', $client)
            ->with('success', 'Клиент ' . $client->name . ' успешно создан');
    }

    public function update(Request $request, Client $client)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'inn' => ['required', 'string', 'regex:/^\d{10}(\d{2})?$/', Rule::unique('clients')->ignore($client->id)],
            'tax_system_id' => ['nullable', 'exists:tax_systems,id'],
            'tariff_id' => ['nullable', 'exists:tariffs,id'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string'],
            'employees' => ['array'],
            'employees.*' => ['exists:employees,id'],
        ], [
            'inn.required' => 'Введите ИНН',
            'inn.regex' => 'ИНН должен содержать 10 или 12 цифр',
            'inn.unique' => 'Клиент с таким ИНН уже существует',
        ]);

        $client->update([
            'name' => $validated['name'],
            'inn' => $validated['inn'],
            'tax_system_id' => $validated['tax_system_id'] ?? null,
            'tariff_id' => $validated['tariff_id'] ?? null,
            'is_active' => $request->boolean('is_active'),
            'notes' => $validated['notes'] ?? null,
        ]);

        $client->employees()->sync($validated['employees'] ?? []);

        return redirect()
            ->route('clients.index')
            ->with('success', 'Данные клиента обновлены');
    }

    public function updateSection(Request $request, Client $client)
    {
        $section = $request->input('section');

        $rules = match($section) {
            'basic' => [
                'name' => ['required', 'string', 'max:255'],
                'inn' => ['required', 'string', 'max:14', Rule::unique('clients')->ignore($client->id)],
                'director_inn' => ['nullable', 'string', 'max:14'],
                'tax_office_code' => ['nullable', 'string', 'max:10'],
                'activity_type_id' => ['nullable', 'exists:activity_types,id'],
            ],
            'tax' => [
                'tax_system_id' => ['nullable', 'exists:tax_systems,id'],
                'accounting_method' => ['nullable', 'string', Rule::in(array_keys(Client::$accountingMethods))],
                'taxpayer_category' => ['nullable', 'string', Rule::in(array_keys(Client::$taxpayerCategories))],
            ],
            'contract' => [
                'service_type' => ['nullable', 'string', Rule::in(array_keys(Client::$serviceTypes))],
                'tariff_id' => ['nullable', 'exists:tariffs,id'],
                'contract_with' => ['nullable', 'string', 'max:255'],
                'service_start_date' => ['nullable', 'date'],
                'service_end_date' => ['nullable', 'date'],
                'contract_url' => ['nullable', 'string', 'max:500'],
                'requisites_url' => ['nullable', 'string', 'max:500'],
                'founding_docs_urls' => ['nullable', 'array'],
                'employees' => ['nullable', 'array'],
                'employees.*' => ['exists:employees,id'],
            ],
            'attorney' => [
                'power_of_attorney_name' => ['nullable', 'string', 'max:255'],
                'power_of_attorney_expires' => ['nullable', 'date'],
            ],
            'eds' => [
                'eds_password' => ['nullable', 'string'],
                'eds_expires' => ['nullable', 'date'],
                'cabinet_credentials' => ['nullable', 'array'],
                'esf_user_credentials' => ['nullable', 'array'],
                'ettn_user_credentials' => ['nullable', 'array'],
            ],
            'its' => [
                'its_enabled' => ['boolean'],
                'connection_type' => ['nullable', 'string', Rule::in(array_keys(Client::$connectionTypes))],
                'its_contact' => ['nullable', 'string', 'max:255'],
                'its_credentials' => ['nullable', 'array'],
                'database_path' => ['nullable', 'string', 'max:500'],
                'onec_connect_credentials' => ['nullable', 'array'],
            ],
            'banks' => [
                'bank_credentials' => ['nullable', 'array'],
            ],
            'notes' => [
                'notes' => ['nullable', 'string'],
                'is_active' => ['boolean'],
            ],
            default => [],
        };

        if (empty($rules)) {
            return response()->json(['error' => 'Unknown section'], 400);
        }

        $validated = $request->validate($rules);

        // Обработка employees отдельно
        if (isset($validated['employees'])) {
            $client->employees()->sync($validated['employees']);
            unset($validated['employees']);
        }

        $client->update($validated);
        $client->load(['taxSystem', 'activityType', 'tariff', 'employees']);

        return response()->json([
            'success' => true,
            'message' => 'Данные обновлены',
            'client' => $client,
        ]);
    }

    public function destroy(Client $client)
    {
        $name = $client->name;
        $client->delete();

        return redirect()
            ->route('clients.index')
            ->with('success', 'Клиент ' . $name . ' удалён');
    }
}
