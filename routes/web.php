<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BuhSmetaController;
use App\Http\Controllers\BuhTasksController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ClientServiceScheduleController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EstimateController;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\Vendor\VendorAuthController;
use App\Http\Controllers\Vendor\VendorPanelController;
use Illuminate\Support\Facades\Route;

// Публичные маршруты
Route::get('/', function () {
    $employee = auth('employee')->user();

    if (!$employee) {
        return redirect()->route('login');
    }

    // Руководитель живёт вне системы модулей — сразу на свой дашборд
    if ($employee->isManager()) {
        return redirect()->route('dashboard.index');
    }

    // Модули в порядке приоритета (hasAccessToModule уже возвращает true для admin)
    $availableModules = ['employees', 'clients', 'buhsmeta', 'buhtasks', 'settings'];

    foreach ($availableModules as $moduleName) {
        if ($employee->hasAccessToModule($moduleName)) {
            return redirect()->route($moduleName . '.index');
        }
    }

    // Если нет доступа ни к одному модулю
    return redirect()->route('no-access');
})->middleware('auth:employee');

Route::get('/no-access', function () {
    return view('errors.no-access');
})->name('no-access')->middleware('auth:employee');

// Аутентификация
Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Регистрация новой бухфирмы: заводит аккаунт, стартовый набор справочников
// и его администратора. Публичный роут — работает до авторизации. Ссылки на
// него нет нигде намеренно: фирмы заводим сами, заходя по прямому адресу.
Route::get('/onboarding', [RegisterController::class, 'showForm'])->name('onboarding');
Route::post('/onboarding', [RegisterController::class, 'register']);

// Панель владельца системы. Своя проходная: вендор не сотрудник ни одной фирмы,
// поэтому и вход у него отдельный от employee.
Route::prefix('vendor')->name('vendor.')->group(function () {
    Route::get('/login', [VendorAuthController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [VendorAuthController::class, 'login']);

    Route::middleware('auth:vendor')->group(function () {
        Route::post('/logout', [VendorAuthController::class, 'logout'])->name('logout');

        Route::get('/', [VendorPanelController::class, 'index'])->name('index');
        Route::post('/tenants/{tenant}/enter', [VendorPanelController::class, 'enter'])->name('enter');
        Route::post('/leave', [VendorPanelController::class, 'leave'])->name('leave');
    });
});

// Защищённые маршруты (требуют аутентификации)
Route::middleware('auth:employee')->group(function () {
    // Документы с приватного диска — единственный способ их получить.
    // Проверка доступа внутри контроллера: у документов клиента и задачи разные модули.
    Route::get('/documents/client/{document}', [DocumentController::class, 'client'])->name('documents.client');
    Route::get('/documents/task/{document}', [DocumentController::class, 'task'])->name('documents.task');

    // Страница руководителя (только роль manager, вне системы модулей)
    Route::prefix('dashboard')->name('dashboard.')->middleware('manager')->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('index');
    });

    // Модуль сотрудников
    Route::prefix('employees')->name('employees.')->middleware('module:employees')->group(function () {
        Route::get('/', [EmployeeController::class, 'index'])->name('index');
        Route::get('/search', [EmployeeController::class, 'search'])->name('search');
        Route::post('/', [EmployeeController::class, 'store'])->name('store');
        Route::get('/{employee}', [EmployeeController::class, 'show'])->name('show');
        Route::patch('/{employee}', [EmployeeController::class, 'updateSection'])->name('update-section');
        Route::delete('/{employee}', [EmployeeController::class, 'destroy'])->name('destroy');
    });

    // Модуль клиентов
    Route::prefix('clients')->name('clients.')->middleware('module:clients')->group(function () {
        Route::get('/', [ClientController::class, 'index'])->name('index');
        Route::get('/search', [ClientController::class, 'search'])->name('search');
        Route::post('/', [ClientController::class, 'store'])->name('store');
        Route::get('/{client}', [ClientController::class, 'show'])->name('show');
        Route::put('/{client}', [ClientController::class, 'update'])->name('update');
        Route::patch('/{client}', [ClientController::class, 'updateSection'])->name('update-section');
        Route::delete('/{client}', [ClientController::class, 'destroy'])->name('destroy');
        Route::post('/{client}/documents', [ClientController::class, 'uploadDocument'])->name('documents.upload');
        Route::delete('/{client}/documents/{document}', [ClientController::class, 'deleteDocument'])->name('documents.delete');
        Route::get('/{client}/estimate/edit', [EstimateController::class, 'edit'])->name('estimate.edit');
        Route::get('/{client}/estimate', [EstimateController::class, 'show'])->name('estimate.show');
        Route::post('/{client}/estimate', [EstimateController::class, 'save'])->name('estimate.save');
        Route::get('/{client}/estimate/pdf', [EstimateController::class, 'pdf'])->name('estimate.pdf');

        // Индивидуальное расписание БП у клиента (override дефолта)
        Route::put('/{client}/services/{service}/schedule', [ClientServiceScheduleController::class, 'update'])->name('service-schedule.update');
        Route::delete('/{client}/services/{service}/schedule', [ClientServiceScheduleController::class, 'destroy'])->name('service-schedule.destroy');
    });

    // Модуль БухСмета
    Route::prefix('buhsmeta')->name('buhsmeta.')->middleware('module:buhsmeta')->group(function () {
        Route::get('/', [BuhSmetaController::class, 'index'])->name('index');
        Route::get('/client/{client}/avr', [BuhSmetaController::class, 'avr'])->name('avr');
    });

    // Модуль БухЗадачник
    Route::prefix('buhtasks')->name('buhtasks.')->middleware('module:buhtasks')->group(function () {
        Route::get('/', [BuhTasksController::class, 'index'])->name('index');
        Route::post('/logs', [BuhTasksController::class, 'getOrCreateLog'])->name('logs.get');
        Route::post('/logs/{log}/start', [BuhTasksController::class, 'start'])->name('logs.start');
        Route::post('/logs/{log}/pause', [BuhTasksController::class, 'pause'])->name('logs.pause');
        Route::post('/logs/{log}/complete', [BuhTasksController::class, 'complete'])->name('logs.complete');
        Route::post('/logs/{log}/force-complete', [BuhTasksController::class, 'forceComplete'])->name('logs.force-complete');
        Route::post('/logs/{log}/reset', [BuhTasksController::class, 'reset'])->name('logs.reset');
        Route::post('/logs/{log}/quantity', [BuhTasksController::class, 'updateQuantity'])->name('logs.quantity');
        Route::post('/logs/{log}/comment', [BuhTasksController::class, 'updateComment'])->name('logs.comment');
        Route::post('/logs/{log}/document', [BuhTasksController::class, 'uploadDocument'])->name('logs.document');
        Route::post('/logs/{log}/documents/{document}/delete', [BuhTasksController::class, 'deleteDocument'])->name('logs.document-delete');
        // Внеплановые задачи
        Route::post('/adhoc', [BuhTasksController::class, 'storeAdhoc'])->name('adhoc.store');
        Route::post('/adhoc/{task}/start', [BuhTasksController::class, 'startAdhoc'])->name('adhoc.start');
        Route::post('/adhoc/{task}/pause', [BuhTasksController::class, 'pauseAdhoc'])->name('adhoc.pause');
        Route::post('/adhoc/{task}/complete', [BuhTasksController::class, 'completeAdhoc'])->name('adhoc.complete');
        Route::post('/adhoc/{task}/reset', [BuhTasksController::class, 'resetAdhoc'])->name('adhoc.reset');
        Route::post('/adhoc/{task}/comment', [BuhTasksController::class, 'updateCommentAdhoc'])->name('adhoc.comment');
        Route::post('/adhoc/{task}/document', [BuhTasksController::class, 'uploadDocumentAdhoc'])->name('adhoc.document');
        Route::post('/adhoc/{task}/documents/{document}/delete', [BuhTasksController::class, 'deleteDocumentAdhoc'])->name('adhoc.document-delete');
        Route::post('/adhoc/{task}/delete', [BuhTasksController::class, 'destroyAdhoc'])->name('adhoc.destroy');
        // Проверка главбухом задач бухгалтеров — принять/вернуть прямо со страницы задач (шаг 7.2)
        Route::post('/logs/{log}/review-approve', [BuhTasksController::class, 'approveReview'])->name('logs.review-approve');
        Route::post('/logs/{log}/review-reject', [BuhTasksController::class, 'rejectReview'])->name('logs.review-reject');
        Route::post('/adhoc/{task}/review-approve', [BuhTasksController::class, 'approveReviewAdhoc'])->name('adhoc.review-approve');
        Route::post('/adhoc/{task}/review-reject', [BuhTasksController::class, 'rejectReviewAdhoc'])->name('adhoc.review-reject');
        // Напоминания о сроках (выход воркера tasks:generate)
        Route::post('/reminders/{reminder}/complete', [BuhTasksController::class, 'completeReminder'])->name('reminders.complete');
        Route::post('/reminders/{reminder}/reopen', [BuhTasksController::class, 'reopenReminder'])->name('reminders.reopen');
    });

    // Модуль Аудит — независимая проверка качества закрытой работы
    Route::prefix('audit')->name('audit.')->middleware('audit-access')->group(function () {
        Route::get('/', [AuditController::class, 'index'])->name('index');
        Route::post('/', [AuditController::class, 'store'])->name('store');

        // Реестр замечаний (объявлен до /{audit}, иначе «findings» примут за id аудита)
        Route::get('/findings', [AuditController::class, 'findings'])->name('findings');
        Route::post('/findings/{review}/send', [AuditController::class, 'sendFinding'])->name('findings.send');
        Route::post('/findings/{review}/resolve', [AuditController::class, 'resolveFinding'])->name('findings.resolve');
        Route::post('/findings/{review}/return', [AuditController::class, 'returnFinding'])->name('findings.return');
        Route::post('/findings/{review}/reassign', [AuditController::class, 'reassignFinding'])->name('findings.reassign');

        Route::get('/{audit}', [AuditController::class, 'show'])->name('show');
        Route::delete('/{audit}', [AuditController::class, 'destroy'])->name('destroy');
        Route::post('/{audit}/complete', [AuditController::class, 'complete'])->name('complete');
        Route::post('/{audit}/reopen', [AuditController::class, 'reopen'])->name('reopen');

        // Вердикты по закрытым БП
        Route::post('/{audit}/verdict', [AuditController::class, 'saveVerdict'])->name('verdict.save');
        Route::post('/{audit}/verdict/delete', [AuditController::class, 'deleteVerdict'])->name('verdict.delete');

        // Чек-лист
        Route::post('/{audit}/checklist', [AuditController::class, 'storeChecklistItem'])->name('checklist.store');
        Route::put('/{audit}/checklist/{item}', [AuditController::class, 'updateChecklistItem'])->name('checklist.update');
        Route::delete('/{audit}/checklist/{item}', [AuditController::class, 'destroyChecklistItem'])->name('checklist.destroy');
        Route::post('/{audit}/checklist/section/rename', [AuditController::class, 'renameSection'])->name('checklist.section.rename');
        Route::post('/{audit}/checklist/section/delete', [AuditController::class, 'destroySection'])->name('checklist.section.destroy');
    });

    // Настройки (доступ по модулю settings; админ имеет доступ всегда)
    Route::prefix('settings')->name('settings.')->middleware('module:settings')->group(function () {
        Route::get('/', fn() => redirect()->route('settings.tax-systems'))->name('index');

        // Страницы разделов
        Route::get('/tax-systems', [SettingsController::class, 'taxSystemsPage'])->name('tax-systems');
        Route::get('/activity-types', [SettingsController::class, 'activityTypesPage'])->name('activity-types');
        Route::get('/tariffs', [SettingsController::class, 'tariffsPage'])->name('tariffs');
        Route::get('/rates', [SettingsController::class, 'ratesPage'])->name('rates');
        Route::get('/services', [SettingsController::class, 'servicesPage'])->name('services');

        // Системы налогообложения — только просмотр, роутов на запись нет намеренно:
        // список задаёт государство, он одинаков для всех аккаунтов и меняется
        // централизованно. Удаление к тому же стирало привязки режима у всех БП.

        // CRUD: Виды деятельности
        Route::post('/activity-types', [SettingsController::class, 'storeActivityType'])->name('activity-types.store');
        Route::put('/activity-types/{activityType}', [SettingsController::class, 'updateActivityType'])->name('activity-types.update');
        Route::delete('/activity-types/{activityType}', [SettingsController::class, 'destroyActivityType'])->name('activity-types.destroy');

        // CRUD: Справочник ставок
        Route::post('/rates', [SettingsController::class, 'storeRate'])->name('rates.store');
        Route::put('/rates/{rate}', [SettingsController::class, 'updateRate'])->name('rates.update');
        Route::delete('/rates/{rate}', [SettingsController::class, 'destroyRate'])->name('rates.destroy');

        // CRUD: Тарифы
        Route::post('/tariffs', [SettingsController::class, 'storeTariff'])->name('tariffs.store');
        Route::put('/tariffs/{tariff}', [SettingsController::class, 'updateTariff'])->name('tariffs.update');
        Route::delete('/tariffs/{tariff}', [SettingsController::class, 'destroyTariff'])->name('tariffs.destroy');
        Route::get('/tariffs/{tariff}', [SettingsController::class, 'showTariff'])->name('tariffs.show');
        Route::post('/tariffs/{tariff}/services', [SettingsController::class, 'attachService'])->name('tariffs.services.attach');
        Route::delete('/tariffs/{tariff}/services/{service}', [SettingsController::class, 'detachService'])->name('tariffs.services.detach');

        // CRUD: Бизнес-процессы
        Route::post('/services', [SettingsController::class, 'storeService'])->name('services.store');
        Route::put('/services/{service}', [SettingsController::class, 'updateService'])->name('services.update');
        Route::delete('/services/{service}', [SettingsController::class, 'destroyService'])->name('services.destroy');

        // Форма/тип организации и статус клиента настройками не являются — разделов
        // нет намеренно. Форма организации задаётся государством. Статус клиента —
        // не список, а механика: на флаге closes_service висит закрытие обслуживания,
        // а выставить этот флаг из настроек было нельзя, только название. Свой статус
        // выглядел рабочим, но обслуживание не закрывал. Оба выбираются селектором
        // в карточке клиента.

        // Категория налогоплательщика — классификация ГНС, три значения на всех.
        // Раздела нет намеренно, выбирается селектором в карточке клиента.

        // Метод учёта — кассовый или начисления, список зашит в коде
        // (Client::$accountingMethods). Раздела нет: справочник accounting_methods
        // ни к чему не подключён, правка в нём ни на что не влияла.

        // Тип обслуживания — список зашит в коде (Client::$serviceTypes).
        // Раздела нет: таблица service_types ни к чему не подключена,
        // правка в ней на карточку клиента не влияла.

        // Категория БП — не настройка, а механика: код ищет категории по точному
        // названию (Service::MANDATORY_CATEGORIES / RECOMMENDED_CATEGORIES) и по
        // ним решает, что подтягивать в смету. Переименование ломало подтягивание
        // молча. Раздела нет; список по-прежнему кормит форму бизнес-процессов.

        // Сфера
        Route::get('/spheres', [SettingsController::class, 'spheresPage'])->name('spheres');
        Route::post('/spheres', [SettingsController::class, 'storeSphere'])->name('spheres.store');
        Route::put('/spheres/{sphere}', [SettingsController::class, 'updateSphere'])->name('spheres.update');
        Route::delete('/spheres/{sphere}', [SettingsController::class, 'destroySphere'])->name('spheres.destroy');

        // Группа
        Route::get('/groups', [SettingsController::class, 'groupsPage'])->name('groups');
        Route::post('/groups', [SettingsController::class, 'storeGroup'])->name('groups.store');
        Route::put('/groups/{serviceGroup}', [SettingsController::class, 'updateGroup'])->name('groups.update');
        Route::delete('/groups/{serviceGroup}', [SettingsController::class, 'destroyGroup'])->name('groups.destroy');

        // Периодичность — не настройка, а механика: по kind (weekly/monthly/
        // quarterly/yearly) считаются сроки сдачи (Service.php). Правка молча
        // ломала расчёт дедлайнов, то есть пропущенный отчёт у клиента.
        // Раздела нет; список по-прежнему кормит форму бизнес-процессов.

        // Проверка — раздела нет: таблица check_types пустая и ни к чему не
        // подключена, поля для неё нет даже в форме бизнес-процессов.

        // Биллинг
        Route::get('/billings', [SettingsController::class, 'billingsPage'])->name('billings');
        Route::post('/billings', [SettingsController::class, 'storeBilling'])->name('billings.store');
        Route::put('/billings/{billing}', [SettingsController::class, 'updateBilling'])->name('billings.update');
        Route::delete('/billings/{billing}', [SettingsController::class, 'destroyBilling'])->name('billings.destroy');

        // Коды налоговых органов
        Route::get('/tax-authorities', [SettingsController::class, 'taxAuthoritiesPage'])->name('tax-authorities');
        Route::post('/tax-authorities', [SettingsController::class, 'storeTaxAuthority'])->name('tax-authorities.store');
        // Правки и удаления нет намеренно: список общий для всех аккаунтов,
        // чужую строку трогать нельзя. Добавить недостающий УГНС можно.
    });
});
