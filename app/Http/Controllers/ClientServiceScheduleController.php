<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientServiceSchedule;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Индивидуальное расписание БП у клиента. Живёт отдельно от EstimateController@save
 * (тот пересоздаёт позиции сметы), поэтому override переживает пересохранение сметы.
 */
class ClientServiceScheduleController extends Controller
{
    /** Создать/обновить индивидуальное расписание БП для клиента. */
    public function update(Request $request, Client $client, Service $service)
    {
        $data = $request->validate([
            'periodicity'   => ['nullable', 'string', 'max:100', Rule::exists('periodicities', 'name')],
            'start_month'   => ['nullable', 'array'],
            'start_month.*' => ['integer', 'min:1', 'max:12'],
            // Периодичность без дня срока = БП молча не порождает задач (см. Service::computeDueDates)
            'start_day'     => ['nullable', 'array', 'required_with:periodicity'],
            'start_day.*'   => ['integer', 'min:1', 'max:31'],
        ], [
            'start_day.required_with' => 'Выбрана периодичность — укажите день срока, иначе задачи по этому БП создаваться не будут.',
        ]);

        $schedule = ClientServiceSchedule::updateOrCreate(
            ['client_id' => $client->id, 'service_id' => $service->id],
            [
                'periodicity' => $data['periodicity'] ?? null,
                'start_month' => $data['start_month'] ?? null,
                'start_day'   => $data['start_day'] ?? null,
            ],
        );

        return $this->payload($service, $schedule);
    }

    /** Сбросить к дефолтному расписанию БП (удалить override). */
    public function destroy(Client $client, Service $service)
    {
        ClientServiceSchedule::where('client_id', $client->id)
            ->where('service_id', $service->id)
            ->delete();

        return $this->payload($service, null);
    }

    /** Единый ответ: эффективное расписание (для префилла формы) + подписи + флаг кастома. */
    private function payload(Service $service, ?ClientServiceSchedule $schedule)
    {
        $resolved = $service->resolveForClient($schedule);

        return response()->json([
            'success'   => true,
            'is_custom' => $schedule !== null,
            'schedule'  => [
                'periodicity' => $resolved['periodicity'] ?? '',
                'start_month' => $resolved['months'],
                'start_day'   => $resolved['days'],
            ],
            'labels'    => $service->deadlineLabelsForClient($schedule),
        ]);
    }
}
