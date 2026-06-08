<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BuhSmetaController;
use App\Http\Controllers\BuhTasksController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ClientServiceScheduleController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EstimateController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

// Публичные маршруты
Route::get('/', function () {
    $employee = auth('employee')->user();

    if (!$employee) {
        return redirect()->route('login');
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

// Защищённые маршруты (требуют аутентификации)
Route::middleware('auth:employee')->group(function () {
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
        Route::post('/extra', [BuhTasksController::class, 'storeExtra'])->name('extra.store');
        Route::post('/logs', [BuhTasksController::class, 'getOrCreateLog'])->name('logs.get');
        Route::post('/logs/{log}/start', [BuhTasksController::class, 'start'])->name('logs.start');
        Route::post('/logs/{log}/pause', [BuhTasksController::class, 'pause'])->name('logs.pause');
        Route::post('/logs/{log}/complete', [BuhTasksController::class, 'complete'])->name('logs.complete');
        Route::post('/logs/{log}/reset', [BuhTasksController::class, 'reset'])->name('logs.reset');
        // Внеплановые задачи
        Route::post('/adhoc', [BuhTasksController::class, 'storeAdhoc'])->name('adhoc.store');
        Route::post('/adhoc/{task}/start', [BuhTasksController::class, 'startAdhoc'])->name('adhoc.start');
        Route::post('/adhoc/{task}/pause', [BuhTasksController::class, 'pauseAdhoc'])->name('adhoc.pause');
        Route::post('/adhoc/{task}/complete', [BuhTasksController::class, 'completeAdhoc'])->name('adhoc.complete');
        Route::post('/adhoc/{task}/reset', [BuhTasksController::class, 'resetAdhoc'])->name('adhoc.reset');
        // Напоминания о сроках (выход воркера tasks:generate)
        Route::post('/reminders/{reminder}/complete', [BuhTasksController::class, 'completeReminder'])->name('reminders.complete');
        Route::post('/reminders/{reminder}/reopen', [BuhTasksController::class, 'reopenReminder'])->name('reminders.reopen');
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

        // CRUD: Системы налогообложения
        Route::post('/tax-systems', [SettingsController::class, 'storeTaxSystem'])->name('tax-systems.store');
        Route::put('/tax-systems/{taxSystem}', [SettingsController::class, 'updateTaxSystem'])->name('tax-systems.update');
        Route::delete('/tax-systems/{taxSystem}', [SettingsController::class, 'destroyTaxSystem'])->name('tax-systems.destroy');

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

        // Форма/тип организации
        Route::get('/organization-forms', [SettingsController::class, 'organizationFormsPage'])->name('organization-forms');
        Route::post('/organization-forms', [SettingsController::class, 'storeOrganizationForm'])->name('organization-forms.store');
        Route::put('/organization-forms/{organizationForm}', [SettingsController::class, 'updateOrganizationForm'])->name('organization-forms.update');
        Route::delete('/organization-forms/{organizationForm}', [SettingsController::class, 'destroyOrganizationForm'])->name('organization-forms.destroy');

        // Статус клиента
        Route::get('/client-statuses', [SettingsController::class, 'clientStatusesPage'])->name('client-statuses');
        Route::post('/client-statuses', [SettingsController::class, 'storeClientStatus'])->name('client-statuses.store');
        Route::put('/client-statuses/{clientStatus}', [SettingsController::class, 'updateClientStatus'])->name('client-statuses.update');
        Route::delete('/client-statuses/{clientStatus}', [SettingsController::class, 'destroyClientStatus'])->name('client-statuses.destroy');

        // Категория налогоплательщика
        Route::get('/taxpayer-categories', [SettingsController::class, 'taxpayerCategoriesPage'])->name('taxpayer-categories');
        Route::post('/taxpayer-categories', [SettingsController::class, 'storeTaxpayerCategory'])->name('taxpayer-categories.store');
        Route::put('/taxpayer-categories/{taxpayerCategory}', [SettingsController::class, 'updateTaxpayerCategory'])->name('taxpayer-categories.update');
        Route::delete('/taxpayer-categories/{taxpayerCategory}', [SettingsController::class, 'destroyTaxpayerCategory'])->name('taxpayer-categories.destroy');

        // Метод учёта
        Route::get('/accounting-methods', [SettingsController::class, 'accountingMethodsPage'])->name('accounting-methods');
        Route::post('/accounting-methods', [SettingsController::class, 'storeAccountingMethod'])->name('accounting-methods.store');
        Route::put('/accounting-methods/{accountingMethod}', [SettingsController::class, 'updateAccountingMethod'])->name('accounting-methods.update');
        Route::delete('/accounting-methods/{accountingMethod}', [SettingsController::class, 'destroyAccountingMethod'])->name('accounting-methods.destroy');

        // Тип обслуживания
        Route::get('/service-types', [SettingsController::class, 'serviceTypesPage'])->name('service-types');
        Route::post('/service-types', [SettingsController::class, 'storeServiceType'])->name('service-types.store');
        Route::put('/service-types/{serviceType}', [SettingsController::class, 'updateServiceType'])->name('service-types.update');
        Route::delete('/service-types/{serviceType}', [SettingsController::class, 'destroyServiceType'])->name('service-types.destroy');

        // Категория
        Route::get('/categories', [SettingsController::class, 'categoriesPage'])->name('categories');
        Route::post('/categories', [SettingsController::class, 'storeCategory'])->name('categories.store');
        Route::put('/categories/{category}', [SettingsController::class, 'updateCategory'])->name('categories.update');
        Route::delete('/categories/{category}', [SettingsController::class, 'destroyCategory'])->name('categories.destroy');

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

        // Периодичность
        Route::get('/periodicities', [SettingsController::class, 'periodicitiesPage'])->name('periodicities');
        Route::post('/periodicities', [SettingsController::class, 'storePeriodicity'])->name('periodicities.store');
        Route::put('/periodicities/{periodicity}', [SettingsController::class, 'updatePeriodicity'])->name('periodicities.update');
        Route::delete('/periodicities/{periodicity}', [SettingsController::class, 'destroyPeriodicity'])->name('periodicities.destroy');

        // Проверка
        Route::get('/check-types', [SettingsController::class, 'checkTypesPage'])->name('check-types');
        Route::post('/check-types', [SettingsController::class, 'storeCheckType'])->name('check-types.store');
        Route::put('/check-types/{checkType}', [SettingsController::class, 'updateCheckType'])->name('check-types.update');
        Route::delete('/check-types/{checkType}', [SettingsController::class, 'destroyCheckType'])->name('check-types.destroy');
    });
});
