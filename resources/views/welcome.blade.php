<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Kubik: рабочая система бухгалтерской фирмы. Клиенты, сметы, задачи бухгалтеров, акты и аудит качества в одном месте.">
    <title>Kubik: система для бухгалтерских фирм</title>
    <style>
        /* Витрина системы: светлая страница с фиолетовыми градиентными полями
           сверху и снизу. Стили инлайном: лендинг не должен зависеть от сборки
           Vite и работает даже на свежем сервере, где npm run build ещё не запускали. */
        :root {
            --paper:       #f7f5fb;
            --card:        #ffffff;
            --ink:         #191325;
            --muted:       #6b6383;
            --rule:        #e6e1f2;
            --rule-soft:   #efecf8;

            --violet:      #5b2bb5;
            --violet-deep: #2a1358;
            --violet-soft: #f1ebff;
            --violet-line: #d9cff5;

            --grad:        linear-gradient(132deg, #1c0f36 0%, #35186f 42%, #6534c4 100%);
            --grad-line:   linear-gradient(90deg, rgba(101, 52, 196, 0) 0%, #6534c4 35%, #a678f2 65%, rgba(166, 120, 242, 0) 100%);

            --gold:        #8a6414;
            --gold-soft:   #f7f0dd;

            --on-dark:      #f4f1fb;
            --on-dark-soft: #c3b6e4;

            --shadow-sm:  0 1px 2px rgba(25, 19, 37, .04), 0 6px 18px -14px rgba(42, 19, 88, .45);
            --shadow-md:  0 2px 6px rgba(25, 19, 37, .05), 0 22px 45px -32px rgba(42, 19, 88, .55);

            --display: Georgia, "Iowan Old Style", "Times New Roman", serif;
            --body: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            --mono: ui-monospace, "SF Mono", "JetBrains Mono", "Cascadia Mono", Menlo, Consolas, monospace;
        }

        *, *::before, *::after { box-sizing: border-box; }

        html { color-scheme: light; }

        /* колонка во всю высоту: на высоком экране последнее градиентное поле
           растягивается само, и под ним не остаётся светлой полосы */
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background: var(--paper);
            color: var(--ink);
            font-family: var(--body);
            font-size: 17px;
            line-height: 1.62;
            -webkit-font-smoothing: antialiased;
        }

        a { color: var(--violet); }
        :focus-visible { outline: 2px solid #a678f2; outline-offset: 3px; border-radius: 3px; }

        .wrap {
            max-width: 940px;
            margin: 0 auto;
            padding: 0 28px;
            position: relative;
            z-index: 1;
        }

        /* ── градиентные поля: обложка сверху, финал снизу ── */
        .dark {
            position: relative;
            background: var(--grad);
            color: var(--on-dark);
            overflow: hidden;
        }

        /* мягкая подсветка поверх градиента: без неё заливка выглядит плоской */
        .dark::before {
            content: "";
            position: absolute;
            inset: 0;
            background:
                radial-gradient(58% 70% at 78% 8%, rgba(167, 124, 245, .45) 0%, rgba(167, 124, 245, 0) 62%),
                radial-gradient(46% 60% at 8% 92%, rgba(63, 32, 133, .55) 0%, rgba(63, 32, 133, 0) 70%);
            pointer-events: none;
        }

        .dark::after {
            content: "";
            position: absolute;
            left: 0; right: 0; bottom: 0;
            height: 1px;
            background: var(--grad-line);
            opacity: .9;
        }

        /* ── шапка ─────────────────────────────────────────── */
        .topbar .wrap {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding-top: 24px;
            padding-bottom: 24px;
            border-bottom: 1px solid rgba(244, 241, 251, .14);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 11px;
            font-family: var(--display);
            font-size: 23px;
            letter-spacing: .005em;
        }

        .brand svg { display: block; }

        .tagline {
            font-family: var(--mono);
            font-size: 11.5px;
            letter-spacing: .16em;
            text-transform: uppercase;
            color: var(--on-dark-soft);
        }

        /* ── обложка ───────────────────────────────────────── */
        .hero .wrap {
            display: flex;
            flex-direction: column;
            gap: 24px;
            padding-top: 96px;
            padding-bottom: 104px;
        }

        .eyebrow {
            font-family: var(--mono);
            font-size: 11.5px;
            letter-spacing: .18em;
            text-transform: uppercase;
            color: var(--on-dark-soft);
        }

        h1 {
            font-family: var(--display);
            font-size: clamp(38px, 6.6vw, 62px);
            line-height: 1.05;
            letter-spacing: -.02em;
            font-weight: 400;
            text-wrap: balance;
            margin: 0;
            max-width: 18ch;
        }

        h1 em {
            font-style: italic;
            background: linear-gradient(100deg, #d9c6ff 0%, #f6efff 55%, #cbb1ff 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .hero p {
            margin: 0;
            max-width: 60ch;
            font-size: 19px;
            color: #d5cbee;
        }

        .facts {
            display: flex;
            flex-wrap: wrap;
            gap: 9px 10px;
            padding-top: 8px;
        }

        .fact {
            font-family: var(--mono);
            font-size: 12.5px;
            color: var(--on-dark);
            background: rgba(255, 255, 255, .07);
            border: 1px solid rgba(255, 255, 255, .18);
            border-radius: 999px;
            padding: 6px 14px;
        }

        /* ── светлые разделы ───────────────────────────────── */
        section.band { padding: 76px 0; }

        section.band + section.band { padding-top: 0; }

        section.band .wrap { display: flex; flex-direction: column; gap: 34px; }

        .band-head { display: flex; flex-direction: column; gap: 10px; }

        .band-head .kicker {
            font-family: var(--mono);
            font-size: 11.5px;
            letter-spacing: .18em;
            text-transform: uppercase;
            color: var(--violet);
        }

        h2 {
            font-family: var(--display);
            font-weight: 400;
            font-size: clamp(28px, 3.4vw, 36px);
            line-height: 1.15;
            letter-spacing: -.02em;
            margin: 0;
            text-wrap: balance;
        }

        .band-head p { margin: 0; color: var(--muted); max-width: 60ch; }

        /* ── карточки разделов ─────────────────────────────── */
        .modules { display: flex; flex-direction: column; gap: 14px; }

        .module {
            display: grid;
            grid-template-columns: 200px 1fr;
            gap: 12px 32px;
            align-items: start;
            background: var(--card);
            border: 1px solid var(--rule);
            border-radius: 14px;
            padding: 26px 30px;
            box-shadow: var(--shadow-sm);
            transition: box-shadow .2s ease, border-color .2s ease, transform .2s ease;
        }

        .module:hover {
            border-color: var(--violet-line);
            box-shadow: var(--shadow-md);
            transform: translateY(-1px);
        }

        .module-name {
            font-family: var(--display);
            font-size: 22px;
            line-height: 1.25;
            font-weight: 400;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .module-tag {
            font-family: var(--mono);
            font-size: 11px;
            color: var(--violet);
            background: var(--violet-soft);
            align-self: start;
            padding: 3px 9px;
            border-radius: 5px;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .module-body { display: flex; flex-direction: column; gap: 14px; }
        .module-body p { margin: 0; }

        .inside {
            display: flex;
            flex-wrap: wrap;
            gap: 7px 8px;
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .inside li {
            font-family: var(--mono);
            font-size: 12px;
            color: var(--muted);
            border: 1px solid var(--rule);
            border-radius: 5px;
            padding: 4px 9px;
            background: #fbfaff;
        }

        /* ── планы: пунктир = этого пока нет ───────────────── */
        .plan-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(258px, 1fr));
            gap: 16px;
        }

        .plan-card {
            background: linear-gradient(180deg, #fdfcff 0%, #f8f5ff 100%);
            border: 1px dashed var(--violet-line);
            border-radius: 14px;
            padding: 24px 26px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .pill {
            align-self: start;
            font-family: var(--mono);
            font-size: 10.5px;
            letter-spacing: .14em;
            text-transform: uppercase;
            color: var(--gold);
            background: var(--gold-soft);
            border-radius: 999px;
            padding: 4px 11px;
        }

        .plan-card h3 {
            font-family: var(--display);
            font-weight: 400;
            font-size: 21px;
            letter-spacing: -.01em;
            margin: 0;
        }

        .plan-card p { margin: 0; color: var(--muted); font-size: 15.5px; }

        .ideas { display: flex; flex-direction: column; }

        .idea {
            display: grid;
            grid-template-columns: 220px 1fr;
            gap: 4px 26px;
            padding: 16px 0;
            border-top: 1px dashed var(--rule);
            align-items: baseline;
        }

        .ideas .idea:last-child { border-bottom: 1px dashed var(--rule); }

        .idea b { font-weight: 600; font-size: 16px; }
        .idea span { color: var(--muted); font-size: 15.5px; }

        /* ── финальное поле ────────────────────────────────── */
        .closing { flex: 1 0 auto; }

        .closing .wrap {
            display: flex;
            flex-direction: column;
            gap: 18px;
            padding-top: 84px;
            padding-bottom: 64px;
        }

        .closing h2 { max-width: 25ch; }
        .closing p { margin: 0; color: #d5cbee; max-width: 62ch; }

        .contact {
            font-family: var(--mono);
            font-size: 15px;
            color: var(--on-dark) !important;
            border-left: 2px solid #a678f2;
            padding-left: 14px;
            margin-top: 10px !important;
        }

        .closing footer {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            margin-top: 46px;
            padding-top: 22px;
            border-top: 1px solid rgba(244, 241, 251, .14);
            font-size: 13.5px;
            color: var(--on-dark-soft);
        }

        @media (prefers-reduced-motion: reduce) {
            .module { transition: none; }
            .module:hover { transform: none; }
        }

        @media (max-width: 700px) {
            body { font-size: 16px; }
            .wrap { padding: 0 20px; }
            .hero .wrap { padding-top: 60px; padding-bottom: 68px; }
            section.band { padding: 52px 0; }
            .closing .wrap { padding-top: 60px; padding-bottom: 48px; }
            .module, .idea { grid-template-columns: 1fr; }
            .module { padding: 22px; gap: 14px; }
            .tagline { display: none; }
        }
    </style>
</head>
<body>

    <div class="dark">
        <header class="topbar">
            <div class="wrap">
                <div class="brand">
                    <svg width="23" height="25" viewBox="0 0 22 24" fill="none" aria-hidden="true">
                        <path d="M11 1.5 20.5 6.75v10.5L11 22.5 1.5 17.25V6.75L11 1.5Z" stroke="#c9b2f7" stroke-width="1.3" stroke-linejoin="round"/>
                        <path d="M1.5 6.75 11 12l9.5-5.25M11 12v10.5" stroke="#c9b2f7" stroke-width="1.3" stroke-linejoin="round"/>
                    </svg>
                    <span>Kubik</span>
                </div>
                <div class="tagline">для бухгалтерских фирм</div>
            </div>
        </header>

        <div class="hero">
            <div class="wrap">
                <div class="eyebrow">рабочая система бухгалтерской фирмы</div>
                <h1>Вся работа фирмы: от сметы клиента до <em>акта</em> и аудита</h1>
                <p>Kubik собирает в одном месте то, что обычно живёт в таблицах, чатах и голове главбуха: кто чей клиент, какие работы ему положены по налоговому режиму, что сделано в этом месяце, на какую сумму и с каким качеством. Ниже собрано то, что уже работает, и то, что мы делаем дальше.</p>
                <div class="facts">
                    <span class="fact">отдельный аккаунт на каждую фирму</span>
                    <span class="fact">роли: админ · руководитель · главбух · бухгалтер</span>
                    <span class="fact">доступ к разделам пофамильно</span>
                </div>
            </div>
        </div>
    </div>

    <section class="band">
        <div class="wrap">
            <div class="band-head">
                <div class="kicker">разделы системы</div>
                <h2>Что уже работает</h2>
                <p>Каждому сотруднику видно только то, к чему он допущен: набор разделов включается персонально.</p>
            </div>

            <div class="modules">

                <article class="module">
                    <h3 class="module-name">Клиенты<span class="module-tag">база компаний</span></h3>
                    <div class="module-body">
                        <p>Карточка обслуживаемой компании: реквизиты, налоговый режим, тариф, ответственный бухгалтер, документы и история всех закрытых по ней задач. Базу можно завести не руками, а загрузить файлом, с предпросмотром и разбором ошибок ещё до записи.</p>
                        <ul class="inside">
                            <li>импорт и экспорт списка</li>
                            <li>журнал загрузок</li>
                            <li>документы клиента</li>
                            <li>история работ</li>
                        </ul>
                    </div>
                </article>

                <article class="module">
                    <h3 class="module-name">БухСмета<span class="module-tag">объём и деньги</span></h3>
                    <div class="module-body">
                        <p>Смета клиента собирается сама: система подтягивает бизнес-процессы под его налоговый режим и считает цену по тарифу и ставкам. Из её строк рождаются задачи бухгалтеру, а в конце месяца печатается АВР по фактически закрытым работам.</p>
                        <ul class="inside">
                            <li>подбор БП под режим</li>
                            <li>расчёт стоимости</li>
                            <li>факт месяца по клиенту</li>
                            <li>АВР на печать</li>
                        </ul>
                    </div>
                </article>

                <article class="module">
                    <h3 class="module-name">БухЗадачник<span class="module-tag">работа дня</span></h3>
                    <div class="module-body">
                        <p>Рабочий экран бухгалтера: плановые задачи месяца разворачиваются из смет с рассчитанными сроками сдачи, а рядом внеплановые поручения, таймер работы, чек-листы и файлы. Закрытая задача уходит главбуху на проверку, и он принимает её или возвращает на доработку с комментарием.</p>
                        <ul class="inside">
                            <li>план из смет</li>
                            <li>таймер: старт · пауза · готово</li>
                            <li>проверка главбухом</li>
                            <li>напоминания о сроках</li>
                            <li>внеплановые поручения</li>
                        </ul>
                    </div>
                </article>

                <article class="module">
                    <h3 class="module-name">Аудит<span class="module-tag">качество</span></h3>
                    <div class="module-body">
                        <p>Независимая проверка уже закрытой работы по клиенту за период: аудитор ставит вердикты по бизнес-процессам и идёт по чек-листу, собранному из шаблона. Найденное попадает в реестр замечаний с уровнем критичности, уходит исполнителю и держится на контроле до устранения.</p>
                        <ul class="inside">
                            <li>вердикты по БП</li>
                            <li>чек-листы из шаблонов</li>
                            <li>реестр замечаний</li>
                            <li>критично · существенно · незначительно</li>
                        </ul>
                    </div>
                </article>

                <article class="module">
                    <h3 class="module-name">Руководитель<span class="module-tag">сводка</span></h3>
                    <div class="module-body">
                        <p>Картина по всем сотрудникам и всем обслуживаемым компаниям за выбранный месяц: сколько задач в плане, сколько закрыто, где просрочка и кто чем занят. Только чтение, чтобы смотреть на фирму целиком, не влезая в чужую работу.</p>
                        <ul class="inside">
                            <li>план и факт по месяцам</li>
                            <li>просрочки</li>
                            <li>загрузка сотрудников</li>
                        </ul>
                    </div>
                </article>

                <article class="module">
                    <h3 class="module-name">Сотрудники<span class="module-tag">люди и доступы</span></h3>
                    <div class="module-body">
                        <p>Штат фирмы с ролями и персональными доступами: кому какие разделы открыты, за какими клиентами он закреплён и что у него в работе. Уволенный сотрудник отключается, а его клиенты и история никуда не деваются.</p>
                        <ul class="inside">
                            <li>роли и права</li>
                            <li>закрепление клиентов</li>
                            <li>нагрузка сотрудника</li>
                        </ul>
                    </div>
                </article>

                <article class="module">
                    <h3 class="module-name">Настройки<span class="module-tag">справочники</span></h3>
                    <div class="module-body">
                        <p>Справочники, по которым живёт вся система: бизнес-процессы с периодичностью и сроками сдачи, тарифы, ставки, группы и сферы, виды деятельности, биллинг, коды налоговых органов.</p>
                        <ul class="inside">
                            <li>каталог бизнес-процессов</li>
                            <li>тарифы и ставки</li>
                            <li>налоговые режимы</li>
                        </ul>
                    </div>
                </article>

                <article class="module">
                    <h3 class="module-name">Документы<span class="module-tag">хранилище</span></h3>
                    <div class="module-body">
                        <p>Файлы клиентов и задач лежат в закрытом хранилище: скачать их можно только через систему и только тому, кому открыт соответствующий раздел. Таблицы и сканы открываются прямо в браузере, отдельной вкладкой: удобно разложить рядом несколько файлов и сверить цифры.</p>
                        <ul class="inside">
                            <li>приватное хранилище</li>
                            <li>просмотр таблиц в браузере</li>
                            <li>просмотр сканов и фото</li>
                        </ul>
                    </div>
                </article>

            </div>
        </div>
    </section>

    <section class="band">
        <div class="wrap">
            <div class="band-head">
                <div class="kicker">дорожная карта</div>
                <h2>Что делаем дальше</h2>
                <p>Пунктир означает, что этого в системе пока нет. Три ближайших направления, а ниже то, что обсуждаем с бухгалтерскими фирмами.</p>
            </div>

            <div class="plan-grid">

                <article class="plan-card">
                    <span class="pill">в планах</span>
                    <h3>Авто-аудит</h3>
                    <p>Система сама прогоняет закрытые задачи по правилам (сроки, комплектность документов, суммы, пропуски в периодах) и отдаёт аудитору готовый список подозрительных мест. Ручная проверка остаётся, но начинается не с нуля, а с того, на что действительно стоит смотреть.</p>
                </article>

                <article class="plan-card">
                    <span class="pill">в планах</span>
                    <h3>Отправка актов клиентам</h3>
                    <p>АВР за месяц формируется и уходит клиенту на почту сам, по расписанию закрытия периода. С журналом отправок и статусом: кому ушло, кто открыл, кто подтвердил.</p>
                </article>

                <article class="plan-card">
                    <span class="pill">в планах</span>
                    <h3>Общая база знаний</h3>
                    <p>Методички, шаблоны и инструкции «как это делается у нас», привязанные к конкретным бизнес-процессам: инструкция открывается прямо из задачи. Новый бухгалтер работает по стандарту фирмы с первого дня, а знание не уходит вместе с человеком.</p>
                </article>

            </div>

            <div class="ideas">
                <div class="idea"><b>Личный кабинет клиента</b><span>Клиент сам видит статус работ, забирает акты и загружает документы, вместо переписки в мессенджерах.</span></div>
                <div class="idea"><b>Уведомления в мессенджер</b><span>Сроки, возвраты на доработку и новые поручения приходят туда, где сотрудник и так сидит.</span></div>
                <div class="idea"><b>Счета и оплаты</b><span>Выставление счёта по акту и контроль задолженности по каждому клиенту.</span></div>
                <div class="idea"><b>Рентабельность клиента</b><span>Сравнение суммы сметы с фактически потраченным временем: видно, кто приносит деньги, а кто съедает часы.</span></div>
            </div>
        </div>
    </section>

    <section class="dark closing">
        <div class="wrap">
            <h2>Приглашаем в тестовую группу</h2>
            <div class="facts">
                <span class="fact">2 месяца бесплатно</span>
                <span class="fact">работа на своих клиентах</span>
                <span class="fact">развёрнутый отзыв взамен</span>
            </div>
            <p>Мы открываем систему первым бухгалтерским фирмам. Два месяца работы бесплатно, на своих настоящих клиентах: аккаунт заводится со стартовым набором справочников и полностью изолирован от других фирм, остаётся загрузить свою базу.</p>
            <p>Взамен просим развёрнутый отзыв: что мешает в работе, чего не хватает, что стоит переделать. Сейчас этап запуска, поэтому такие замечания попадают в разработку почти сразу, и у первых фирм есть реальная возможность повлиять на то, каким продукт станет дальше.</p>
            <p class="contact">Свяжитесь с тем, кто дал вам эту ссылку.</p>

            <footer>
                <span>Kubik · система для бухгалтерских фирм</span>
                <span>{{ date('Y') }}</span>
            </footer>
        </div>
    </section>

</body>
</html>
