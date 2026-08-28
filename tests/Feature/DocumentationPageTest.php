<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Документация на /documentation/kubik.
 *
 * Открыта без входа — как витрина: в базу не ходит, ссылок внутрь системы не даёт.
 * Проверяем именно это: что страница отдаётся гостю, что чужой раздел не выдумывается
 * и что поисковикам она закрыта.
 */
class DocumentationPageTest extends TestCase
{
    use DatabaseTransactions;

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
        DB::purge('mysql');

        return parent::setUpTraits();
    }

    public function test_index_is_open_without_login(): void
    {
        $page = $this->get('/documentation/kubik')->assertOk();

        $page->assertSee('Справка по Kubik', false);
        $page->assertSee('БухСмета', false);
        $page->assertSee('noindex', false);
    }

    public function test_smeta_section_is_readable_without_login(): void
    {
        $page = $this->get('/documentation/kubik/smeta')->assertOk();

        // Ключевые правила на месте — если раздел перепишут вхолостую, тест это заметит.
        $page->assertSee('Откуда берутся строки', false);
        $page->assertSee('Как считается цена', false);
        $page->assertSee('Когда по строке появятся задачи', false);
    }

    /** Ненаписанный и несуществующий разделы одинаково 404, а не пустая страница. */
    public function test_unknown_and_unwritten_sections_are_not_found(): void
    {
        $this->get('/documentation/kubik/buhtasks')->assertNotFound();
        $this->get('/documentation/kubik/nothing-here')->assertNotFound();
    }

    /** Slug идёт только из списка разделов — чужой путь через адрес не подставить. */
    public function test_path_traversal_is_refused(): void
    {
        $this->get('/documentation/kubik/' . urlencode('../../.env'))->assertNotFound();
    }
}
