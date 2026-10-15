<?php

namespace Tests\Feature;

use App\Models\BuhAdhocTask;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Module;
use App\Models\Periodicity;
use App\Models\Role;
use App\Services\AdhocAssignments;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Форма «Добавить задачу» создаёт пачку: несколько сотрудников, у каждого свои компании.
 * Итог должен быть таким, будто каждую задачу завели руками по одной.
 */
class AdhocBatchTest extends TestCase
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

    /** Компания автора (он ответственный), в команде которой состоит исполнитель. */
    private function sharedClient(): Client
    {
        $client = $this->client($this->author->id);
        $client->employees()->attach($this->doer->id);

        return $client;
    }

    private function client(?int $responsibleId): Client
    {
        $name = 'ТОО ' . uniqid();

        return Client::create([
            'name' => $name, 'inn' => strtoupper(substr(md5($name), 0, 12)),
            'responsible_employee_id' => $responsibleId, 'is_active' => true,
        ]);
    }

    private function postBatch(array $assignments, array $extra = [])
    {
        return $this->actingAs($this->author, 'employee')
            ->postJson(route('buhtasks.adhoc.store-batch'), array_merge([
                'assignments' => $assignments,
                'name'        => 'Сверка с банком',
                'due_date'    => now()->addDays(3)->toDateString(),
            ], $extra));
    }

    private function createdCount(): int
    {
        return BuhAdhocTask::where('created_by', $this->author->id)->count();
    }

    public function test_each_employee_and_company_pair_becomes_its_own_task(): void
    {
        $a = $this->sharedClient();
        $b = $this->sharedClient();

        $response = $this->postBatch([
            ['employee_id' => $this->doer->id, 'client_ids' => [$a->id, $b->id]],
            ['employee_id' => $this->author->id],
        ])->assertOk();

        $response->assertJsonPath('created', 3);
        $response->assertJsonCount(1, 'tasks');    // своя — в список на экране
        $response->assertJsonCount(2, 'assigned'); // поручения — во вкладку «Я поручил»

        $tasks = BuhAdhocTask::where('created_by', $this->author->id)->get();

        $this->assertEqualsCanonicalizing(
            [$a->id, $b->id],
            $tasks->where('employee_id', $this->doer->id)->pluck('client_id')->all(),
        );
        // Без отмеченных компаний — одна задача без компании.
        $this->assertNull($tasks->firstWhere('employee_id', $this->author->id)->client_id);
        // Поручение другому всегда идёт автору на проверку.
        $this->assertTrue($tasks->where('employee_id', $this->doer->id)->every(fn ($t) => $t->requires_review));
    }

    public function test_company_not_linked_to_the_employee_is_refused_and_nothing_is_created(): void
    {
        $linked   = $this->sharedClient();
        $notTheirs = $this->client($this->author->id); // автора, но исполнитель к ней не прикреплён

        $this->postBatch([
            ['employee_id' => $this->doer->id, 'client_ids' => [$linked->id, $notTheirs->id]],
        ])->assertStatus(422);

        $this->assertSame(0, $this->createdCount());
    }

    public function test_company_the_author_does_not_see_is_refused(): void
    {
        // Исполнитель её ведёт, но автор к ней отношения не имеет.
        $foreign = $this->client($this->doer->id);

        $this->postBatch([
            ['employee_id' => $this->doer->id, 'client_ids' => [$foreign->id]],
        ])->assertStatus(422);

        $this->assertSame(0, $this->createdCount());
    }

    public function test_too_many_tasks_at_once_are_refused(): void
    {
        $this->postBatch([
            ['employee_id' => $this->doer->id, 'client_ids' => range(1, AdhocAssignments::MAX_TASKS + 1)],
        ])->assertStatus(422);

        $this->assertSame(0, $this->createdCount());
    }

    public function test_attached_file_is_copied_into_every_task(): void
    {
        Storage::fake('local');

        $this->postBatch(
            [['employee_id' => $this->doer->id, 'client_ids' => [$this->sharedClient()->id, $this->sharedClient()->id]]],
            ['file' => UploadedFile::fake()->create('выписка.pdf', 10)],
        )->assertOk();

        $paths = BuhAdhocTask::where('created_by', $this->author->id)->with('documents')->get()
            ->map(fn ($t) => $t->documents->sole()->path);

        $this->assertCount(2, $paths->unique());
        $paths->each(fn ($path) => Storage::disk('local')->assertExists($path));
    }

    public function test_single_task_endpoint_follows_the_same_company_rule(): void
    {
        $notTheirs = $this->client($this->author->id);

        $this->actingAs($this->author, 'employee')
            ->postJson(route('buhtasks.adhoc.store'), [
                'employee_id' => $this->doer->id,
                'client_id'   => $notTheirs->id,
                'name'        => 'Разовая',
                'due_date'    => now()->toDateString(),
            ])
            ->assertStatus(422);

        $this->assertSame(0, $this->createdCount());
    }

    public function test_form_gets_companies_of_each_employee(): void
    {
        $shared = $this->sharedClient();
        $own    = $this->client($this->author->id);

        $map = $this->actingAs($this->author, 'employee')
            ->get(route('buhtasks.index'))
            ->assertOk()
            ->viewData('employeeClients');

        $this->assertEqualsCanonicalizing([$shared->id], $map[$this->doer->id]);
        $this->assertEqualsCanonicalizing([$shared->id, $own->id], $map[$this->author->id]);
    }
}
