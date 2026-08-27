<?php

namespace App\Http\Controllers;

use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Документация по системе: как что работает, своими словами.
 *
 * Пишется для себя — чтобы через месяц не поднимать код ради вопроса «а как
 * считается цена в смете». Поэтому здесь только бизнес-логика: ни имён таблиц,
 * ни полей, ни маршрутов. Текст лежит в repo рядом с кодом (`resources/docs`),
 * и правка поведения должна ехать одним коммитом с правкой страницы — иначе
 * документация начнёт врать, а это хуже, чем её отсутствие.
 *
 * Страница открыта без входа, как витрина: в базу не ходит, ссылок внутрь
 * системы не даёт, показывать гостю ей нечего, кроме собственного текста.
 * От поисковиков закрыта — это рабочая справка, а не реклама.
 */
class DocumentationController extends Controller
{
    /**
     * Разделы документации по порядку. Добавить раздел — положить файл
     * в resources/docs/kubik и дописать сюда строку.
     *
     * ready = false: раздел ещё не написан. Показываем в оглавлении серым,
     * чтобы было видно, чего не хватает, и не выглядело, будто про это забыли.
     */
    private const SECTIONS = [
        'smeta' => [
            'title'   => 'БухСмета',
            'summary' => 'Как собирается смета клиента, из чего складывается цена и когда по ней пойдут задачи.',
            'ready'   => true,
        ],
        'buhtasks' => [
            'title'   => 'БухЗадачник',
            'summary' => 'Откуда берутся задачи месяца, что такое просрочка и как задача проходит проверку.',
            'ready'   => false,
        ],
        'catalog' => [
            'title'   => 'Каталог бизнес-процессов',
            'summary' => 'Что такое БП, что означает каждое поле карточки и на что оно влияет. Архивация вместо удаления.',
            'ready'   => true,
        ],
        'clients' => [
            'title'   => 'Клиенты',
            'summary' => 'Карточка клиента, признаки, филиалы, начало и конец обслуживания.',
            'ready'   => false,
        ],
        'employees' => [
            'title'   => 'Сотрудники',
            'summary' => 'Роли главбуха и бухгалтера: кто ведёт клиента, кто выполняет работу, кто её принимает. Доступ к модулям.',
            'ready'   => true,
        ],
    ];

    /** Оглавление. */
    public function index()
    {
        return view('docs.index', [
            'sections' => self::SECTIONS,
        ]);
    }

    /** Раздел. Slug берём только из списка выше — чужой путь сюда не пролезет. */
    public function show(string $section)
    {
        $meta = self::SECTIONS[$section] ?? null;

        if (!$meta || !$meta['ready']) {
            throw new NotFoundHttpException('Такого раздела документации нет');
        }

        $path = resource_path("docs/kubik/{$section}.md");

        if (!is_file($path)) {
            throw new NotFoundHttpException('Текст раздела не найден');
        }

        return view('docs.section', [
            'sections' => self::SECTIONS,
            'current'  => $section,
            'meta'     => $meta,
            'body'     => Str::markdown(file_get_contents($path)),
        ]);
    }
}
