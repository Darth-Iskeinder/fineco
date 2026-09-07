<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\ClientStatus;
use App\Models\Employee;
use App\Models\OrganizationForm;
use App\Models\Tariff;
use App\Models\TaxSystem;
use App\Services\ClientResponsibleTransfer;
use App\Services\ClientTaskHistory;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $me = auth('employee')->user();

        // Вторичная сортировка по id: у импортированных клиентов created_at совпадает
        // с точностью до секунды, и без неё порядок «последний созданный сверху» плавает.
        $clients = Client::visibleTo($me)
            ->with(['taxSystem', 'tariff', 'responsibleEmployee', 'organizationForm', 'clientStatus'])
            ->withCount('estimateRootItems')
            ->filter($request->only(Client::FILTER_KEYS))
            // При поиске сверху то, что человек искал: точный ИНН и название с начала строки.
            ->sortBySearch($request->search)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        // Фильтр по ответственному нужен только тем, кто видит чужие компании:
        // у остальных в списке и так одни свои — селект из одного человека это шум.
        $seesEveryone = Client::canBeManagedBy($me);

        return view('clients.index', [
            'clients' => $clients,
            'clientRows' => $clients->map(fn ($c) => $this->clientRow($c)),
            'search' => $request->search,
            'filters' => $request->only(Client::FILTER_KEYS),
            // Всего клиентов без фильтров — для счётчика «Найдено N из M»
            'totalClients' => Client::visibleTo($me)->count(),
            'taxSystems' => TaxSystem::active()->ordered()->get(),
            // Статусы для фильтра: те же, что в карточке клиента.
            'clientStatuses' => ClientStatus::orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            // Список сотрудников нужен и рядовым: из него выбирают ответственного
            // в модалке правки клиента. Скрыт от них только фильтр по ответственному.
            'employees' => Employee::active()->orderBy('full_name')->get(),
            'tariffs' => Tariff::active()->ordered()->get(),
            'organizationForms' => OrganizationForm::orderBy('name')->get(['id', 'name']),
            'canManageClients' => $seesEveryone,
            'canFilterByPerson' => $seesEveryone,
        ]);
    }

    /**
     * Строка клиента для списка на экране.
     *
     * Одна на всех, кто этот список наполняет: первая отрисовка страницы, поиск
     * с фильтрами и ответ на правку клиента. Разъедутся форматы — строка после
     * сохранения станет отличаться от соседних, и заметят это не сразу.
     */
    private function clientRow(Client $client): array
    {
        return [
            'id' => $client->id,
            'name' => $client->name,
            'inn' => $client->inn,
            'company_number' => $client->company_number,
            'tax_system_id' => $client->tax_system_id,
            'tax_system_name' => $client->taxSystem?->name ?? '—',
            'organization_form_id' => $client->organization_form_id,
            // Пусто оставляем пустым, а не прочерком: форма показывается мелкой
            // строкой под названием, и прочерк там был бы шумом.
            'organization_form_name' => $client->organizationForm?->name,
            'tariff_id' => $client->tariff_id,
            'tariff_name' => $client->tariff?->name ?? '—',
            // Статус клиента, а не флаг активности: бейдж в списке и бейдж в карточке
            // должны говорить одно и то же. Флаг оставлен для старых клиентов, кому
            // статус ещё не проставили.
            'is_active' => $client->is_active,
            'status_name' => $client->clientStatus?->name,
            'status_color' => $client->clientStatus?->color,
            'responsible_employee_id' => $client->responsible_employee_id,
            'responsible_name' => $client->responsibleEmployee?->full_name ?? '—',
            'estimate_items_count' => $client->estimate_root_items_count,
        ];
    }

    /**
     * Удалённые клиенты своей фирмы.
     *
     * Удаление мягкое, и удалённый клиент продолжает держать свой ИНН: пока его
     * не вернёшь, того же клиента не завести заново, а из интерфейса он не виден
     * вовсе. Разбирать такое приходилось запросом в базу (07.09.2026: на бою
     * четырнадцать таких клиентов, из-за одного не заводился ИП Керимов).
     */
    public function trashed()
    {
        $this->authorizeManage();

        $clients = Client::onlyTrashed()
            ->orderByDesc('deleted_at')
            ->get(['id', 'name', 'inn', 'company_number', 'deleted_at']);

        return response()->json($clients->map(fn (Client $client) => $this->trashedRow($client)));
    }

    /** Строка удалённого клиента: одна на список и на подсказку о занятом ИНН. */
    private function trashedRow(Client $client): array
    {
        return [
            'id' => $client->id,
            'name' => $client->name,
            'inn' => $client->inn,
            'company_number' => $client->company_number,
            'deleted_at' => $client->deleted_at?->format('d.m.Y'),
            // Возвращать может тот же, кто заводит и удаляет: рядовому сотруднику
            // удалённый клиент и на глаза попадать не должен.
            'can_restore' => Client::canBeManagedBy(auth('employee')->user()),
        ];
    }

    /** Вернуть удалённого клиента: карточка, смета и история остаются прежними. */
    public function restore(Client $client)
    {
        $this->authorizeManage();

        if ($client->trashed()) {
            $client->restore();
        }

        if (request()->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Клиент ' . $client->name . ' возвращён',
                'client' => $this->clientRow($client->fresh()->load([
                    'taxSystem', 'tariff', 'responsibleEmployee', 'organizationForm', 'clientStatus',
                ])->loadCount('estimateRootItems')),
            ]);
        }

        return redirect()
            ->route('clients.show', $client)
            ->with('success', 'Клиент ' . $client->name . ' возвращён');
    }

    public function search(Request $request)
    {
        // q — прежнее имя параметра поиска, оставлено для внешних ссылок
        $filters = $request->only(Client::FILTER_KEYS);
        $filters['search'] = $filters['search'] ?? $request->get('q', '');

        $clients = Client::visibleTo(auth('employee')->user())
            ->with(['taxSystem', 'tariff', 'responsibleEmployee', 'organizationForm', 'clientStatus'])
            ->withCount('estimateRootItems')
            ->filter($filters)
            ->sortBySearch($filters['search'])
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->get()
            ->map(fn ($client) => $this->clientRow($client));

        return response()->json($clients);
    }

    public function show(Client $client)
    {
        $this->authorizeClient($client);

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

        // Историю задач видят не все: без права секция не должна попадать в разметку
        // вообще, а не прятаться стилями.
        $canSeeTaskHistory = app(ClientTaskHistory::class)
            ->canView(auth('employee')->user(), $client);

        return view('clients.show', [
            'client'            => $client,
            'canSeeTaskHistory' => $canSeeTaskHistory,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeManage();

        $validated = $request->validateWithBag('createClient', [
            'name' => ['required', 'string', 'max:255'],
            // Форма собственности хранится отдельно от названия: в названии остаётся
            // «Ромашка», а «ОсОО» приходит сюда. Необязательна — клиента часто заводят
            // на бегу, зная только название и ИНН.
            'organization_form_id' => ['nullable', 'exists:organization_forms,id'],
            // Уникальность ИНН — в пределах своей фирмы. Правило проверки ходит
            // мимо фильтра по фирме (оно смотрит таблицу напрямую), поэтому
            // ограничиваем вручную. Иначе фирма получала бы «ИНН занят» из-за
            // чужого клиента, которого не видит и найти не может.
            'inn' => ['required', 'string', 'max:14', $this->innIsFreeInTenant()],
            'company_number' => $this->companyNumberRules(),
            'tax_system_id' => ['nullable', 'exists:tax_systems,id'],
            'tariff_id' => ['nullable', 'exists:tariffs,id'],
            'responsible_employee_id' => ['nullable', 'exists:employees,id'],
            'notes' => ['nullable', 'string'],
        ], [
            'inn.required' => 'Введите ИНН',
            'inn.unique' => 'Клиент с таким ИНН уже существует',
            'company_number.unique' => 'Этот номер компании уже занят другим клиентом',
            'company_number.integer' => 'Номер компании — целое число',
            'company_number.min' => 'Номер компании должен быть больше нуля',
            'company_number.max' => 'Номер компании слишком длинный',
        ]);

        // Удалённый клиент держит и ИНН, и номер компании. Проверку он проходит
        // (правила смотрят только на живых), поэтому ловим здесь и предлагаем вернуть.
        if ($conflict = $this->trashedConflictFor($request, [
            'inn' => $validated['inn'],
            'company_number' => self::companyNumber($validated),
        ], 'createClient')) {
            return $conflict;
        }

        $client = Client::create([
            'name' => $validated['name'],
            'organization_form_id' => $validated['organization_form_id'] ?? null,
            'inn' => $validated['inn'],
            'company_number' => self::companyNumber($validated),
            'tax_system_id' => $validated['tax_system_id'] ?? null,
            'tariff_id' => $validated['tariff_id'] ?? null,
            'responsible_employee_id' => $validated['responsible_employee_id'] ?? null,
            'is_active' => true,
            'client_status_id' => ClientStatus::where('stops_tasks', false)
                ->orderBy('sort_order')
                ->value('id'),
            'notes' => $validated['notes'] ?? null,
            'service_start_date' => $validated['service_start_date'] ?? now()->toDateString(),
        ]);

        return redirect()
            ->route('clients.show', $client)
            ->with('success', 'Клиент ' . $client->name . ' успешно создан');
    }

    /**
     * Предпросмотр смены ответственного: что переедет на нового сотрудника.
     *
     * Отдельным запросом, а не вместе с сохранением: окно подтверждения показывается
     * ДО того, как поле изменилось, и человек должен видеть цифры заранее, а не узнавать
     * о переезде задач постфактум.
     */
    public function responsiblePreview(Request $request, Client $client)
    {
        $this->authorizeClient($client);

        $validated = $request->validate([
            'employee_id' => ['nullable', 'exists:employees,id'],
        ]);

        return response()->json(
            (new ClientResponsibleTransfer())->preview($client, $validated['employee_id'] ?? null)
        );
    }

    /**
     * Сохранение карточки клиента. Если сменился ответственный, вместе с ним на нового
     * переезжает вся незакрытая работа по клиенту (см. ClientResponsibleTransfer).
     *
     * Одной транзакцией: состояние «у клиента новый ответственный, а смета и задачи
     * остались на прежнем» — ровно то, из-за чего задачи продолжали идти уволенному.
     *
     * @return array{items:int, reminders:int, adhoc:int}
     */
    private function saveClient(Client $client, array $attrs): array
    {
        $previousResponsibleId = $client->responsible_employee_id;

        return DB::transaction(function () use ($client, $attrs, $previousResponsibleId) {
            $client->update($attrs);

            return (new ClientResponsibleTransfer())->apply($client, $previousResponsibleId);
        });
    }

    public function update(Request $request, Client $client)
    {
        $this->authorizeClient($client);

        $validated = $request->validateWithBag('updateClient', [
            'name' => ['required', 'string', 'max:255'],
            'organization_form_id' => ['nullable', 'exists:organization_forms,id'],
            'inn' => ['required', 'string', 'max:14', $this->innIsFreeInTenant($client->id)],
            'company_number' => $this->companyNumberRules($client->id),
            'tax_system_id' => ['nullable', 'exists:tax_systems,id'],
            'tariff_id' => ['nullable', 'exists:tariffs,id'],
            'responsible_employee_id' => ['nullable', 'exists:employees,id'],
            'notes' => ['nullable', 'string'],
        ], [
            'inn.required' => 'Введите ИНН',
            'inn.unique' => 'Клиент с таким ИНН уже существует',
            'company_number.unique' => 'Этот номер компании уже занят другим клиентом',
            'company_number.integer' => 'Номер компании — целое число',
            'company_number.min' => 'Номер компании должен быть больше нуля',
            'company_number.max' => 'Номер компании слишком длинный',
        ]);

        if ($conflict = $this->trashedConflictFor($request, [
            'inn' => $validated['inn'],
            'company_number' => self::companyNumber($validated),
        ], 'updateClient')) {
            return $conflict;
        }

        // `is_active` тут нет намеренно: обслуживанием распоряжается статус клиента
        // в карточке, и только он. Пока флаг был ещё и в этой форме, список и
        // карточка показывали про одного клиента разное: тумблер гасил флаг, а
        // статус оставался «Активен» — и задачи продолжали идти.
        $this->saveClient($client, [
            'name' => $validated['name'],
            'organization_form_id' => $validated['organization_form_id'] ?? null,
            'inn' => $validated['inn'],
            'company_number' => self::companyNumber($validated),
            'tax_system_id' => $validated['tax_system_id'] ?? null,
            'tariff_id' => $validated['tariff_id'] ?? null,
            'responsible_employee_id' => $validated['responsible_employee_id'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        // Правка из списка идёт фоновым запросом: страница не перезагружается,
        // поэтому прокрутка, поиск и фильтры остаются на месте. Со ста клиентами
        // перезагрузка означала «листай вниз заново».
        if ($request->expectsJson()) {
            $client->load(['taxSystem', 'tariff', 'responsibleEmployee', 'organizationForm', 'clientStatus'])->loadCount('estimateRootItems');

            return response()->json([
                'success' => true,
                'message' => 'Данные клиента обновлены',
                'client'  => $this->clientRow($client),
            ]);
        }

        return redirect()
            ->route('clients.index')
            ->with('success', 'Данные клиента обновлены');
    }

    public function updateSection(Request $request, Client $client)
    {
        $this->authorizeClient($client);

        $section = $request->input('section');

        $rules = match($section) {
            'basic' => [
                'name' => ['required', 'string', 'max:255'],
                'organization_form_id' => ['nullable', 'exists:organization_forms,id'],
                'inn' => ['required', 'string', 'max:14', $this->innIsFreeInTenant($client->id)],
                'company_number' => $this->companyNumberRules($client->id),
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
                // Тип обслуживания: любое сочетание отметок. Ни одной отметки и все три
                // означают одно и то же — ведём клиента целиком.
                'serves_accounting' => ['boolean'],
                'serves_tax' => ['boolean'],
                'serves_payroll' => ['boolean'],
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
                'tunduk_password' => ['nullable', 'string'],
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
                // Характеристики с количеством
                'has_fixed_assets' => ['boolean'],
                'fixed_assets_count' => ['nullable', 'integer', 'min:0', 'max:9999'],
                'has_fuel' => ['boolean'],
                'fuel_count' => ['nullable', 'integer', 'min:0', 'max:9999'],
                'has_loans' => ['boolean'],
                'loans_count' => ['nullable', 'integer', 'min:0', 'max:9999'],
                'has_branches' => ['boolean'],
                'branches' => ['nullable', 'array'],
                'branches.*.no_code' => ['required', 'string', 'max:10'],
                'branches.*.city' => ['nullable', 'string', 'max:255'],
                // Характеристики-переключатели
                'has_excise' => ['boolean'],
                'has_nonresident_services' => ['boolean'],
                'has_property' => ['boolean'],
                'has_bank_client' => ['boolean'],
                'has_separate_books' => ['boolean'],
                'has_nonstandard_contracts' => ['boolean'],
                'has_foreign_trade' => ['boolean'],
                'has_vat_refund' => ['boolean'],
                'has_special_reporting' => ['boolean'],
                'has_currency_operations' => ['boolean'],
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
            ],
            default => [],
        };

        if (empty($rules)) {
            return response()->json(['error' => 'Unknown section'], 400);
        }

        // Сообщения по-русски для полей, где ошибиться проще всего: номер компании
        // человек ставит руками, и «занят» он узнаёт только при сохранении.
        $validated = $request->validate($rules, [
            'inn.unique' => 'Клиент с таким ИНН уже существует',
            'company_number.unique' => 'Этот номер компании уже занят другим клиентом',
            'company_number.integer' => 'Номер компании — целое число',
            'company_number.min' => 'Номер компании должен быть больше нуля',
            'company_number.max' => 'Номер компании слишком длинный',
        ]);

        if ($section === 'basic') {
            $conflict = $this->trashedConflictFor($request, [
                'inn' => $validated['inn'] ?? null,
                'company_number' => self::companyNumber($validated),
            ], 'default');

            if ($conflict) {
                return $conflict;
            }
        }

        // Обработка employees отдельно
        if (isset($validated['employees'])) {
            $client->employees()->sync($validated['employees']);
            unset($validated['employees']);
        }

        // Логика статуса: статус, границы окна задач и флаг активности едут вместе.
        // Останавливающий статус («Приостановлен», «Завершен») закрывает окно сверху
        // датой остановки, возврат в работу открывает его снизу — первым числом
        // следующего месяца, чтобы за перерыв не приехала просрочка.
        if ($section === 'status') {
            $statusChanged = array_key_exists('client_status_id', $validated)
                && (string) $validated['client_status_id'] !== (string) $client->client_status_id;
            $endDateAdded = !empty($validated['service_end_date'])
                && $validated['service_end_date'] !== optional($client->service_end_date)->toDateString();

            if ($statusChanged && !empty($validated['client_status_id'])) {
                // Пользователь поменял статус — он главный
                $status = ClientStatus::find($validated['client_status_id']);
                if ($status) {
                    $validated = $status->stops_tasks
                        ? array_merge($validated, Client::serviceStopAttributes($validated['service_end_date'] ?? null))
                        : array_merge($validated, $client->serviceResumeAttributes());
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

        $this->saveClient($client, $validated);
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
        $this->authorizeClient($client);

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

            $path = $file->storeAs('clients/' . $client->id, $safeName, 'local');

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
        $this->authorizeClient($client);

        if ($document->client_id !== $client->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        Storage::disk('local')->delete($document->path);
        $document->delete();

        return response()->json(['success' => true]);
    }

    public function destroy(Client $client)
    {
        $this->authorizeManage();

        $name = $client->name;
        $client->delete();

        return redirect()
            ->route('clients.index')
            ->with('success', 'Клиент ' . $name . ' удалён');
    }

    /** Чужая компания — 403: прямая ссылка не должна открывать то, чего нет в списке. */
    private function authorizeClient(Client $client): void
    {
        abort_unless($client->isVisibleTo(auth('employee')->user()), 403, 'Это не ваш клиент');
    }

    /** Заводить и удалять компании может только админ и руководитель. */
    private function authorizeManage(): void
    {
        abort_unless(Client::canBeManagedBy(auth('employee')->user()), 403, 'Недостаточно прав');
    }

    /**
     * Правило «такой ИНН у нас ещё не занят» — только в своей фирме и только среди
     * живых клиентов. Удалённые тоже держат ИНН, но про них человеку нужно сказать
     * другое: не «уже существует», а «удалён тогда-то, вот он, верните» —
     * см. trashedHolder() и trashedConflict().
     */
    private function innIsFreeInTenant(?int $exceptId = null): \Illuminate\Validation\Rules\Unique
    {
        $rule = Rule::unique('clients', 'inn')
            ->where('tenant_id', TenantContext::id())
            ->whereNull('deleted_at');

        return $exceptId ? $rule->ignore($exceptId) : $rule;
    }

    /**
     * Кто из удалённых держит это значение.
     *
     * Уникальный индекс в базе удалённых считает, поэтому пропустить их через
     * проверку и упасть на вставке нельзя: ловим до сохранения и объясняем.
     */
    private function trashedHolder(string $column, $value): ?Client
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Client::onlyTrashed()->where($column, $value)->first();
    }

    /**
     * Ответ «значение держит удалённый клиент».
     *
     * Фоновому запросу отдаём 422 с данными о клиенте: список и карточка рисуют
     * плашку с кнопкой «Вернуть». Обычной отправке формы — редирект назад с той же
     * ошибкой и клиентом во флеш-сессии, чтобы окно создания открылось с плашкой.
     */
    private function trashedConflict(Request $request, string $field, Client $held, string $errorBag)
    {
        $what = $field === 'inn' ? 'Клиент с таким ИНН' : 'Клиент с таким номером компании';
        $message = $what . ' удалён ' . $held->deleted_at->format('d.m.Y') . ': ' . $held->name . '.';
        $row = $this->trashedRow($held);

        $message .= $row['can_restore']
            ? ' Верните его, чтобы продолжить.'
            : ' Обратитесь к руководителю, чтобы его вернуть.';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'errors' => [$field => [$message]],
                'trashed' => $row,
            ], 422);
        }

        return back()
            ->withInput()
            ->withErrors([$field => $message], $errorBag)
            ->with('trashedClient', $row);
    }

    /**
     * Занято ли что-нибудь из введённого удалённым клиентом.
     *
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse|null
     */
    private function trashedConflictFor(Request $request, array $fields, string $errorBag)
    {
        foreach ($fields as $field => $value) {
            if ($held = $this->trashedHolder($field, $value)) {
                return $this->trashedConflict($request, $field, $held, $errorBag);
            }
        }

        return null;
    }

    /**
     * Проверка номера компании: целое число больше нуля, свободное в своей фирме.
     *
     * Номер фирма ведёт сама, и он должен указывать на одного клиента: два
     * восьмых номера сделали бы бессмысленным сам способ звать клиентов по
     * номерам. У чужих фирм нумерация своя, поэтому сверяем внутри своей.
     */
    private function companyNumberRules(?int $exceptId = null): array
    {
        $unique = Rule::unique('clients', 'company_number')
            ->where('tenant_id', TenantContext::id())
            ->whereNull('deleted_at');

        return ['nullable', 'integer', 'min:1', 'max:999999999', $exceptId ? $unique->ignore($exceptId) : $unique];
    }

    /** Пустое поле формы — это «номера нет», а не ноль. */
    private static function companyNumber(array $validated): ?int
    {
        $value = $validated['company_number'] ?? null;

        return $value === null || $value === '' ? null : (int) $value;
    }
}
