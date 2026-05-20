<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BuhSmetaController;
use App\Http\Controllers\BuhTasksController;
use App\Http\Controllers\ClientController;
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
    $availableModules = ['employees', 'clients', 'buhsmeta', 'buhtasks'];

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
        Route::put('/{employee}', [EmployeeController::class, 'update'])->name('update');
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
        Route::get('/{client}/estimate/edit', [EstimateController::class, 'edit'])->name('estimate.edit');
        Route::get('/{client}/estimate', [EstimateController::class, 'show'])->name('estimate.show');
        Route::post('/{client}/estimate', [EstimateController::class, 'save'])->name('estimate.save');
        Route::get('/{client}/estimate/pdf', [EstimateController::class, 'pdf'])->name('estimate.pdf');
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
    });

    // Настройки (только для админов)
    Route::prefix('settings')->name('settings.')->middleware('admin')->group(function () {
        Route::get('/', [SettingsController::class, 'index'])->name('index');

        // Системы налогообложения
        Route::post('/tax-systems', [SettingsController::class, 'storeTaxSystem'])->name('tax-systems.store');
        Route::put('/tax-systems/{taxSystem}', [SettingsController::class, 'updateTaxSystem'])->name('tax-systems.update');
        Route::delete('/tax-systems/{taxSystem}', [SettingsController::class, 'destroyTaxSystem'])->name('tax-systems.destroy');

        // Виды деятельности
        Route::post('/activity-types', [SettingsController::class, 'storeActivityType'])->name('activity-types.store');
        Route::put('/activity-types/{activityType}', [SettingsController::class, 'updateActivityType'])->name('activity-types.update');
        Route::delete('/activity-types/{activityType}', [SettingsController::class, 'destroyActivityType'])->name('activity-types.destroy');

        // Тарифы
        Route::post('/tariffs', [SettingsController::class, 'storeTariff'])->name('tariffs.store');
        Route::put('/tariffs/{tariff}', [SettingsController::class, 'updateTariff'])->name('tariffs.update');
        Route::delete('/tariffs/{tariff}', [SettingsController::class, 'destroyTariff'])->name('tariffs.destroy');
        Route::get('/tariffs/{tariff}', [SettingsController::class, 'showTariff'])->name('tariffs.show');
        Route::post('/tariffs/{tariff}/services', [SettingsController::class, 'attachService'])->name('tariffs.services.attach');
        Route::delete('/tariffs/{tariff}/services/{service}', [SettingsController::class, 'detachService'])->name('tariffs.services.detach');

        // Бизнес процессы (услуги)
        Route::post('/services', [SettingsController::class, 'storeService'])->name('services.store');
        Route::put('/services/{service}', [SettingsController::class, 'updateService'])->name('services.update');
        Route::delete('/services/{service}', [SettingsController::class, 'destroyService'])->name('services.destroy');
    });
});
