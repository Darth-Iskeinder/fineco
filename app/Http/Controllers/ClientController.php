<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\ClientStatus;
use App\Models\Employee;
use App\Models\Tariff;
use App\Models\TaxSystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $clients = Client::with(['taxSystem', 'tariff', 'responsibleEmployee'])
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

        $clients = Client::with(['taxSystem', 'tariff', 'responsibleEmployee'])
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
                    'responsible_employee_id' => $client->responsible_employee_id,
                    'responsible_name' => $client->responsibleEmployee?->full_name ?? '—',
                ];
            });

        return response()->json($clients);
    }

    public function show(Client $client)
    {
        $client->load([
            'organizationForm',
            'taxSystem',
            'activityType',
            'tariff',
            'employees',
            'responsibleEmployee',
            'clientStatus',
            'taxpayerCategoryModel',
            'documents',
        ]);

        return view('clients.show', [
            'client' => $client,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validateWithBag('createClient', [
            'name' => ['required', 'string', 'max:255'],
            'inn' => ['required', 'string', 'max:14', 'unique:clients,inn'],
            'tax_system_id' => ['nullable', 'exists:tax_systems,id'],
            'tariff_id' => ['nullable', 'exists:tariffs,id'],
            'responsible_employee_id' => ['nullable', 'exists:employees,id'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string'],
        ], [
            'inn.required' => 'Введите ИНН',
            'inn.unique' => 'Клиент с таким ИНН уже существует',
        ]);

        $client = Client::create([
            'name' => $validated['name'],
            'inn' => $validated['inn'],
            'tax_system_id' => $validated['tax_system_id'] ?? null,
            'tariff_id' => $validated['tariff_id'] ?? null,
            'responsible_employee_id' => $validated['responsible_employee_id'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'client_status_id' => ClientStatus::where('closes_service', false)
                ->orderBy('sort_order')
                ->value('id'),
            'notes' => $validated['notes'] ?? null,
            'service_start_date' => $validated['service_start_date'] ?? now()->toDateString(),
        ]);

        return redirect()
            ->route('clients.show', $client)
            ->with('success', 'Клиент ' . $client->name . ' успешно создан');
    }

    public function update(Request $request, Client $client)
    {
        $validated = $request->validateWithBag('updateClient', [
            'name' => ['required', 'string', 'max:255'],
            'inn' => ['required', 'string', 'max:14', Rule::unique('clients')->ignore($client->id)],
            'tax_system_id' => ['nullable', 'exists:tax_systems,id'],
            'tariff_id' => ['nullable', 'exists:tariffs,id'],
            'responsible_employee_id' => ['nullable', 'exists:employees,id'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string'],
        ], [
            'inn.required' => 'Введите ИНН',
            'inn.unique' => 'Клиент с таким ИНН уже существует',
        ]);

        $client->update([
            'name' => $validated['name'],
            'inn' => $validated['inn'],
            'tax_system_id' => $validated['tax_system_id'] ?? null,
            'tariff_id' => $validated['tariff_id'] ?? null,
            'responsible_employee_id' => $validated['responsible_employee_id'] ?? null,
            'is_active' => $request->boolean('is_active'),
            'notes' => $validated['notes'] ?? null,
        ]);

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
                'organization_form_id' => ['nullable', 'exists:organization_forms,id'],
                'inn' => ['required', 'string', 'max:14', Rule::unique('clients')->ignore($client->id)],
                'director_inn' => ['nullable', 'string', 'max:14'],
                'tax_office_code' => ['nullable', 'string', 'max:10'],
                'activity_type_id' => ['nullable', 'exists:activity_types,id'],
            ],
            'status' => [
                'client_status_id' => ['nullable', 'exists:client_statuses,id'],
                'service_start_date' => ['nullable', 'date'],
                'service_end_date' => ['nullable', 'date'],
            ],
            'tax' => [
                'tax_system_id' => ['nullable', 'exists:tax_systems,id'],
                'accounting_method' => ['nullable', 'string', Rule::in(array_keys(Client::$accountingMethods))],
                'taxpayer_category' => ['nullable', 'string', Rule::in(array_keys(Client::$taxpayerCategories))],
                'taxpayer_category_id' => ['nullable', 'exists:taxpayer_categories,id'],
            ],
            'contract' => [
                'service_type' => ['nullable', 'string', Rule::in(array_keys(Client::$serviceTypes))],
                'tariff_id' => ['nullable', 'exists:tariffs,id'],
                'contract_with' => ['nullable', 'string', 'max:255'],
                'contract_url' => ['nullable', 'string', 'max:500'],
                'requisites_url' => ['nullable', 'string', 'max:500'],
                'founding_docs_urls' => ['nullable', 'array'],
                'responsible_employee_id' => ['nullable', 'exists:employees,id'],
            ],
            'attorney' => [
                'power_of_attorney_name' => ['nullable', 'array'],
                'power_of_attorney_name.*' => ['string', 'max:255'],
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
            'flags' => [
                'is_zero_movement' => ['boolean'],
                'has_employees' => ['boolean'],
                'employees_count' => ['nullable', 'integer', 'min:0', 'max:9999'],
                'has_kkm' => ['boolean'],
                'kkm_count' => ['nullable', 'integer', 'min:0', 'max:9999'],
                'has_marketplaces' => ['boolean'],
                'marketplaces_count' => ['nullable', 'integer', 'min:0', 'max:9999'],
                'import_eaeu' => ['boolean'],
                'import_third_countries' => ['boolean'],
                'has_export' => ['boolean'],
                'pvt_mode' => ['boolean'],
                'pki_mode' => ['boolean'],
                'has_alcohol' => ['boolean'],
                'has_insurance_policy' => ['boolean'],
                'has_mbt' => ['boolean'],
                'has_crypto_exchange' => ['boolean'],
                'has_payment_aggregators' => ['boolean'],
                'has_production' => ['boolean'],
                'has_management_report' => ['boolean'],
                'edo_operator' => ['nullable', 'string', 'max:255'],
            ],
            'contacts_info' => [
                'contacts' => ['nullable', 'array'],
                'contacts.*.type' => ['required', 'string', 'in:phone,email,telegram,whatsapp,viber,other'],
                'contacts.*.value' => ['required', 'string', 'max:255'],
                'contacts.*.note' => ['nullable', 'string', 'max:255'],
                'related_persons' => ['nullable', 'array'],
                'related_persons.*.name' => ['required', 'string', 'max:255'],
                'related_persons.*.role' => ['nullable', 'string', 'max:255'],
                'related_persons.*.inn' => ['nullable', 'string', 'max:14'],
                'related_persons.*.note' => ['nullable', 'string', 'max:255'],
            ],
            'extras' => [
                'client_folder_url' => ['nullable', 'string', 'max:500'],
                'access_instructions' => ['nullable', 'string'],
                'extra_fields' => ['nullable', 'array'],
                'extra_fields.*.label' => ['required', 'string', 'max:100'],
                'extra_fields.*.value' => ['nullable', 'string', 'max:500'],
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

        // Логика статуса: синхронизация статуса, даты завершения и is_active
        if ($section === 'status') {
            $statusChanged = array_key_exists('client_status_id', $validated)
                && (string) $validated['client_status_id'] !== (string) $client->client_status_id;
            $endDateAdded = !empty($validated['service_end_date'])
                && $validated['service_end_date'] !== optional($client->service_end_date)->toDateString();

            if ($statusChanged && !empty($validated['client_status_id'])) {
                // Пользователь поменял статус — он главный
                $status = ClientStatus::find($validated['client_status_id']);
                if ($status) {
                    if ($status->closes_service) {
                        // «Завершен» — закрываем обслуживание, при необходимости проставляем дату
                        if (empty($validated['service_end_date'])) {
                            $validated['service_end_date'] = now()->toDateString();
                        }
                        $validated['is_active'] = false;
                    } else {
                        // «Активен» / «Приостановлен» — снимаем дату завершения
                        $validated['service_end_date'] = null;
                        $validated['is_active'] = true;
                    }
                }
            } elseif ($endDateAdded) {
                // Поставили дату завершения → статус «Завершен»
                $closingStatus = ClientStatus::where('closes_service', true)
                    ->orderBy('sort_order')
                    ->first();
                if ($closingStatus) {
                    $validated['client_status_id'] = $closingStatus->id;
                }
                $validated['is_active'] = false;
            }
        }

        $client->update($validated);
        $client->load([
            'organizationForm',
            'taxSystem',
            'activityType',
            'tariff',
            'employees',
            'responsibleEmployee',
            'clientStatus',
            'taxpayerCategoryModel',
            'documents',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Данные обновлены',
            'client' => $client,
        ]);
    }

    public function uploadDocument(Request $request, Client $client)
    {
        $request->validate([
            'files' => ['required', 'array'],
            'files.*' => ['required', 'file', 'max:40960'],
        ], [
            'files.required' => 'Выберите файлы для загрузки.',
            'files.*.file' => 'Не удалось прочитать файл — возможно, он превышает лимит сервера.',
            'files.*.max' => 'Файл не должен превышать 40 МБ.',
        ]);

        $uploaded = [];

        foreach ($request->file('files') as $file) {
            $originalName = $file->getClientOriginalName();
            $extension = $file->getClientOriginalExtension();
            $nameWithoutExt = pathinfo($originalName, PATHINFO_FILENAME);
            $safeName = $nameWithoutExt . '_' . time() . '.' . $extension;

            $path = $file->storeAs('clients/' . $client->id, $safeName, 'public');

            $document = $client->documents()->create([
                'name' => $safeName,
                'original_name' => $originalName,
                'path' => $path,
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
            ]);

            $uploaded[] = $document;
        }

        $client->load('documents');

        return response()->json([
            'success' => true,
            'documents' => $client->documents,
        ]);
    }

    public function deleteDocument(Client $client, ClientDocument $document)
    {
        if ($document->client_id !== $client->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        Storage::disk('public')->delete($document->path);
        $document->delete();

        return response()->json(['success' => true]);
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
