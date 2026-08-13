<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientImport;
use App\Models\ClientImportRow;
use App\Services\ClientCsvParser;
use App\Services\ClientCsvSchema;
use App\Services\ClientCsvTemplate;
use App\Services\ClientCsvWriter;
use App\Services\ClientImportPlanner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Обмен списком клиентов через CSV.
 *
 * Загрузка идёт в два шага: сначала показываем, что будет (preview), и только
 * по подтверждению пишем. Разбор обоих шагов делает один и тот же планировщик,
 * поэтому увиденное на экране и попавшее в базу совпадают.
 */
class ClientImportController extends Controller
{
    /** Где лежит файл между «загрузил» и «подтвердил». Диск закрытый. */
    private const UPLOAD_DIR = 'imports';

    /** Разобрать загруженный файл и показать, что произойдёт. Базу не трогаем. */
    public function preview(Request $request)
    {
        $request->validate([
            // Excel и почтовые клиенты присваивают CSV разные типы, поэтому
            // проверяем расширение, а не то, чем файл представился.
            'file' => ['required', 'file', 'max:2048', 'mimes:csv,txt'],
        ], [
            'file.required' => 'Выберите файл',
            'file.max'      => 'Файл больше 2 МБ',
            'file.mimes'    => 'Нужен файл CSV',
        ]);

        $token = (string) Str::uuid();
        $path  = self::UPLOAD_DIR . '/' . $token . '.csv';

        Storage::disk('local')->put($path, file_get_contents($request->file('file')->getRealPath()));

        $parsed = (new ClientCsvParser)->parse(Storage::disk('local')->path($path));

        if ($parsed['error']) {
            Storage::disk('local')->delete($path);

            return back()->withErrors(['file' => $parsed['error']]);
        }

        // Токен держим в сессии: по одной ссылке чужой файл не подобрать.
        $request->session()->put('client_import.' . $token, [
            'name' => $request->file('file')->getClientOriginalName(),
        ]);

        $plan = (new ClientImportPlanner)->plan($parsed['rows']);

        return view('clients.import-preview', [
            'token'    => $token,
            'fileName' => $request->file('file')->getClientOriginalName(),
            'plan'     => $plan,
            'columns'  => ClientCsvSchema::COLUMNS,
        ]);
    }

    /**
     * Журнал загрузок.
     *
     * Отвечает на вопрос, который задают через полгода: откуда взялась эта
     * пачка клиентов и кто её залил. Только чтение — отменять загрузку отсюда
     * нельзя: экран проверки не даёт записать лишнее, а массовое удаление
     * задним числом опаснее самой ошибки, от которой защищало бы.
     */
    public function history()
    {
        return view('clients.imports', [
            'imports' => ClientImport::with('employee')
                ->withCount('rows')
                ->orderByDesc('id')
                ->paginate(20),
        ]);
    }

    /** Кого именно затронула одна загрузка. */
    public function show(ClientImport $import)
    {
        return view('clients.import-show', [
            'import' => $import->load('employee'),
            'rows'   => $import->rows()->with('client')->orderBy('id')->paginate(50),
        ]);
    }

    /** Подтверждённая запись: делаем ровно то, что человек видел на экране проверки. */
    public function apply(Request $request, string $token)
    {
        $meta = $request->session()->get('client_import.' . $token);

        abort_unless($meta, 404);

        $updateExisting = $request->boolean('update_existing');
        $plan           = $this->planFor($token);
        $summary        = ClientImportPlanner::summary($plan, $updateExisting);

        $import = DB::transaction(function () use ($plan, $updateExisting, $summary, $meta) {
            $import = ClientImport::create([
                'employee_id'     => auth('employee')->id(),
                'file_name'       => $meta['name'],
                'created_count'   => $summary['create'],
                'updated_count'   => $summary['update'],
                'skipped_count'   => $summary['error'],
                'update_existing' => $updateExisting,
            ]);

            foreach ($plan as $row) {
                $verdict = ClientImportPlanner::verdict($row, $updateExisting);

                if ($verdict === 'create') {
                    $client = Client::create($row['attributes']);

                    $import->rows()->create([
                        'client_id' => $client->id,
                        'action'    => ClientImportRow::ACTION_CREATED,
                    ]);

                    continue;
                }

                if ($verdict !== 'update') {
                    continue;
                }

                $client = Client::find($row['client_id']);

                if (!$client) {
                    continue;
                }

                // Снимок только тех полей, которые сейчас перезапишем: откат
                // должен вернуть их, а не затереть всё остальное в карточке.
                $before = collect($row['attributes'])
                    ->keys()
                    ->mapWithKeys(fn (string $field) => [$field => $client->getAttribute($field)])
                    ->all();

                $client->update($row['attributes']);

                $import->rows()->create([
                    'client_id' => $client->id,
                    'action'    => ClientImportRow::ACTION_UPDATED,
                    'before'    => $before,
                ]);
            }

            return $import;
        });

        $this->forget($request, $token);

        return redirect()->route('clients.index')->with('import_result', [
            'id'      => $import->id,
            'created' => $import->created_count,
            'updated' => $import->updated_count,
            'skipped' => $import->skipped_count,
        ]);
    }

    /** Строки, которые не пройдут, — отдельным файлом: исправить и залить снова. */
    public function errors(Request $request, string $token): StreamedResponse
    {
        abort_unless($request->session()->has('client_import.' . $token), 404);

        $updateExisting = $request->boolean('update_existing');
        $plan = $this->planFor($token);

        $rows = [];

        foreach ($plan as $row) {
            if (ClientImportPlanner::verdict($row, $updateExisting) !== 'error') {
                continue;
            }

            $rows[] = [
                $row['line'],
                $row['name'],
                $row['inn'],
                ClientImportPlanner::reason($row, $updateExisting),
            ];
        }

        return $this->csv('clients-import-errors.csv', $rows, ['Строка', 'Название', 'ИНН', 'Причина']);
    }

    /** Импорт закончен — временный файл и токен больше не нужны. */
    private function forget(Request $request, string $token): void
    {
        Storage::disk('local')->delete(self::UPLOAD_DIR . '/' . $token . '.csv');

        $request->session()->forget('client_import.' . $token);
    }

    /** Перечитать сохранённый файл и заново построить план. */
    private function planFor(string $token): array
    {
        $path = Storage::disk('local')->path(self::UPLOAD_DIR . '/' . $token . '.csv');

        abort_unless(is_file($path), 404);

        $parsed = (new ClientCsvParser)->parse($path);

        return (new ClientImportPlanner)->plan($parsed['rows']);
    }

    /** Выгрузка клиентов — тех же, что сейчас видны в списке. */
    public function export(Request $request): StreamedResponse
    {
        // Тот же запрос, что и на странице клиентов: человек выгружает то, что
        // видит — с поиском и фильтрами. Иначе «нашёл десять, скачал тысячу».
        $clients = Client::with([
            'organizationForm',
            'activityType',
            'taxSystem',
            'tariff',
            'responsibleEmployee',
        ])
            ->filter($request->only(Client::FILTER_KEYS))
            ->orderBy('id')
            ->get();

        return $this->csv(
            'clients-' . now()->format('Y-m-d') . '.csv',
            $clients->map(fn (Client $client) => ClientCsvSchema::row($client)),
        );
    }

    /** Файл с шапкой и примерами — с него начинают заполнение. */
    public function template(): StreamedResponse
    {
        // Свои заголовки: в шаблоне колонок меньше, чем в выгрузке (см. ClientCsvTemplate).
        return $this->csv('clients-template.csv', ClientCsvTemplate::rows(), ClientCsvTemplate::headers());
    }

    private function csv(string $filename, iterable $rows, ?array $headers = null): StreamedResponse
    {
        $headers ??= ClientCsvSchema::headers();

        return response()->streamDownload(
            function () use ($rows, $headers) {
                $handle = fopen('php://output', 'w');

                ClientCsvWriter::stream($handle, $headers, $rows);

                fclose($handle);
            },
            $filename,
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}
