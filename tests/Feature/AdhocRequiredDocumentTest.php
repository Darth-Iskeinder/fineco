<?php

namespace Tests\Feature;

use App\Models\BuhAdhocTask;
use App\Models\Employee;
use App\Models\Module;
use App\Models\Periodicity;
use App\Models\Role;
use App\Models\Service;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Обязательный документ у внеплановой задачи — как у БП «С документом».
 *
 * Раньше документ у внеплановых был необязателен всегда, и задачу из каталога по БП
 * «С документом» закрывали без файла. Файл автора при создании — материал, а не результат:
 * в зачёт не идёт. Снять требование может только автор.
 */
class AdhocRequiredDocumentTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $author;
    private Employee $doer;

    protected function connectionsToTransact(): array
    {
        return ['mysql'];
    }

    protected function setUpTraits()
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'erp_fineco',
            'database.connections.mysql.url' => null,
        ]);
        \Illuminate\Support\Facades\DB::purge('mysql');

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Periodicity::firstOrCreate(['name' => 'Ежемесячно'], ['kind' => 'monthly']);
        $module = Module::firstOrCreate(
            ['name' => 'buhtasks'],
            ['display_name' => 'БухЗадачник', 'is_active' => true],
        );
        $role = Role::firstOrCreate(['name' => Role::ACCOUNTANT], ['display_name' => 'Бухгалтер']);

        $make = fn (string $prefix) => Employee::create([
            'full_name' => 'Тест ' . $prefix, 'position' => $prefix,
            'email' => $prefix . '_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);

        $this->author = $make('author');
        $this->doer   = $make('doer');

        foreach ([$this->author, $this->doer] as $e) {
            $e->modules()->attach($module->id);
        }
    }

    private function create(array $fields, ?Employee $to = null): BuhAdhocTask
    {
        $this->actingAs($this->author, 'employee')
            ->post(route('buhtasks.adhoc.store-batch'), array_merge([
                'assignments' => [['employee_id' => ($to ?? $this->doer)->id]],
                'name'        => 'Справка в банк',
                'due_date'    => now()->addDays(3)->toDateString(),
            ], $fields))
            ->assertOk();

        return BuhAdhocTask::latest('id')->first();
    }

    private function complete(BuhAdhocTask $task, Employee $as)
    {
        return $this->actingAs($as, 'employee')->postJson(route('buhtasks.adhoc.complete', $task->id));
    }

    private function catalogService(bool $requiresDocument): Service
    {
        return Service::create([
            'name' => 'Тест услуга ' . uniqid(), 'periodicity' => 'Ежемесячно',
            'start_day' => [5], 'is_active' => true, 'requires_document' => $requiresDocument,
        ]);
    }

    public function test_task_cannot_be_completed_without_the_required_document(): void
    {
        $task = $this->create(['requires_document' => '1']);

        $this->complete($task, $this->doer)
            ->assertStatus(422)
            ->assertJsonPath('requires_document', true);

        $this->assertSame('pending', $task->fresh()->status);
    }

    public function test_doer_file_satisfies_the_requirement(): void
    {
        $task = $this->create(['requires_document' => '1']);

        $this->actingAs($this->doer, 'employee')
            ->post(route('buhtasks.adhoc.document', $task->id), ['file' => UploadedFile::fake()->create('справка.pdf', 10)])
            ->assertOk();

        $this->complete($task, $this->doer)->assertOk();
        $this->assertSame('review', $task->fresh()->status); // поручение — автору на проверку
    }

    public function test_author_file_from_the_form_does_not_count(): void
    {
        $task = $this->create([
            'requires_document' => '1',
            'file'              => UploadedFile::fake()->create('образец.pdf', 10),
        ]);

        $this->assertTrue($task->documents()->sole()->from_author);
        $this->complete($task, $this->doer)->assertStatus(422);
    }

    public function test_catalog_rule_is_the_default_but_can_be_switched_off(): void
    {
        $service = $this->catalogService(true);

        // Поле не прислали — правило берётся из каталога.
        $this->assertTrue($this->create(['service_id' => $service->id])->requires_document);
        // Автор выключил тумблер — задача без требования.
        $this->assertFalse($this->create(['service_id' => $service->id, 'requires_document' => '0'])->requires_document);
    }

    public function test_task_without_the_rule_closes_as_before(): void
    {
        $task = $this->create([], $this->author);

        $this->assertFalse($task->requires_document);
        $this->complete($task, $this->author)->assertOk();
    }

    public function test_author_can_waive_the_requirement_and_the_doer_cannot(): void
    {
        $task = $this->create(['requires_document' => '1']);

        $this->actingAs($this->doer, 'employee')
            ->postJson(route('buhtasks.adhoc.waive-document', $task->id))
            ->assertForbidden();
        $this->assertTrue($task->fresh()->requires_document);

        $this->actingAs($this->author, 'employee')
            ->postJson(route('buhtasks.adhoc.waive-document', $task->id))
            ->assertOk();

        $this->complete($task, $this->doer)->assertOk();
    }

    public function test_requirement_cannot_be_waived_once_the_task_is_handed_in(): void
    {
        $task = $this->create(['requires_document' => '1']);
        $task->update(['status' => 'review', 'review_started_at' => now()]);

        $this->actingAs($this->author, 'employee')
            ->postJson(route('buhtasks.adhoc.waive-document', $task->id))
            ->assertStatus(422);

        $this->assertTrue($task->fresh()->requires_document);
    }
}
