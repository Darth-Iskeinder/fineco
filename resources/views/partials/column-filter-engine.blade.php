{{-- Общая механика фильтров-воронок в шапке колонок.

     Один код на каталог БП и БухЗадачник: мультивыбор значений, счётчики с учётом
     остальных фильтров, поиск внутри меню, «Выбрать все», чипы отобранного.

     Компонент подключает её так:

         function myPage() {
             return withColumnFilters(FACETS, {
                 get facetSource() { return this.rows; },   // по чему считать значения
                 matchesBase(row) { ... },                  // отбор ДО фасетов
                 afterFilterChange() { ... },               // хвост любого изменения
                 ...
             });
         }

     Свойства компонента перекрывают движок, поэтому facetEntries/facetLabel можно
     переопределить под свои данные (режимы налогообложения, компании, статусы).

     Собираем через defineProperties, а не через спред: спред вычислил бы геттеры
     компонента прямо в момент сборки, когда данных ещё нет. --}}
<script>
function withColumnFilters(facets, component) {
    return Object.defineProperties(columnFilterEngine(facets), Object.getOwnPropertyDescriptors(component));
}

function columnFilterEngine(facets) {
    return {
        facets,

        // Выбранные значения по каждому фасету (нормализованные ключи).
        filters: Object.fromEntries(facets.map(f => [f.key, []])),

        // Открытая воронка и её положение. Меню рисуется через teleport в body:
        // таблица прокручивается внутри своего блока и обрезала бы выпадающий список.
        openFilter: null,
        filterMenu: { top: 0, left: 0 },
        filterSearch: '',
        filterAnchor: null,
        filterFrame: null,

        // Готовые пункты открытого меню (значение, подпись, счётчик) и полное их число
        // до отбора строкой поиска — по нему решаем, показывать ли саму строку поиска.
        menuOptions: [],
        menuTotal: 0,

        facetByKey(key) {
            return this.facets.find(f => f.key === key);
        },

        /**
         * Значения строки по фасету: пара «ключ для сравнения, подпись для человека».
         *
         * Регистр не различаем: в справочниках живут и «Ежемесячно», и «ежемесячно»,
         * и человек, выбравший одно, ждёт увидеть оба. Пустое значение — тоже значение
         * («не задано»), по нему как раз и ищут незаполненные карточки.
         *
         * Компонент переопределяет метод, если у него есть свои виды колонок.
         */
        facetEntries(row, key) {
            const facet = this.facetByKey(key);

            if (facet.type === 'bool') {
                return [{ value: row[key] ? 'yes' : 'no', label: row[key] ? facet.yes : facet.no }];
            }

            const raw = (row[key] ?? '').toString().trim();

            return [{ value: raw.toLowerCase(), label: raw === '' ? 'Не задано' : raw }];
        },

        facetValues(row, key) {
            return this.facetEntries(row, key).map(e => e.value);
        },

        /**
         * Подпись значения для меню и чипов.
         *
         * Ключ нормализован (нижний регистр), поэтому подпись ищем в самих данных:
         * иначе в чипе оказалось бы «налоги и отчетность» вместо того, как поле
         * написано в справочнике.
         */
        facetLabel(key, value) {
            for (const row of this.facetSource) {
                const hit = this.facetEntries(row, key).find(e => e.value === value);
                if (hit) return hit.label;
            }

            return value === '' ? 'Не задано' : value;
        },

        /** Проходит ли строка через выбранные значения фасета (внутри фасета — «любое из»). */
        matchesFacet(row, key) {
            const chosen = this.filters[key];
            if (!chosen.length) return true;

            return this.facetValues(row, key).some(v => chosen.includes(v));
        },

        /** Между разными фасетами условия складываются: выбрали компанию и статус — нужны оба. */
        matchesFacets(row) {
            return this.facets.every(f => this.matchesFacet(row, f.key));
        },

        /**
         * Значения для меню одной воронки со счётчиками.
         *
         * Счётчик считается по списку, отобранному ВСЕМИ фильтрами, кроме этого же:
         * так число показывает, сколько строк добавится при выборе, а не сколько их
         * осталось после уже сделанного выбора.
         *
         * Зовётся только из refreshMenuOptions(), то есть на открытие меню и на смену
         * отбора. В разметке используется уже готовый menuOptions.
         */
        facetOptions(key) {
            const base = this.facetSource.filter(row =>
                this.matchesBase(row) && this.facets.every(f => f.key === key || this.matchesFacet(row, f.key))
            );

            const counts = new Map();  // нормализованное значение → { label, count }

            base.forEach(row => {
                this.facetEntries(row, key).forEach(({ value, label }) => {
                    const cell = counts.get(value) || { label, count: 0 };
                    cell.count++;
                    counts.set(value, cell);
                });
            });

            // Выбранное показываем всегда, даже если под остальными фильтрами его уже нет:
            // иначе снять свой же фильтр было бы нечем.
            this.filters[key].forEach(v => {
                if (!counts.has(v)) counts.set(v, { label: this.facetLabel(key, v), count: 0 });
            });

            const rows = [...counts.entries()].map(([value, cell]) => ({ value, label: cell.label, count: cell.count }));
            const order = this.facetByKey(key).order;

            // Порядок значений задаёт сам фасет, если он у данных осмысленный
            // (стадии работы читаются как «не начата → в работе → закрыта», а не по алфавиту).
            if (order) {
                return rows.sort((a, b) => order.indexOf(a.value) - order.indexOf(b.value));
            }

            // «Не задано» всегда внизу, остальное по алфавиту.
            return rows.sort((a, b) => (a.value === '' ? 1 : b.value === '' ? -1 : a.label.localeCompare(b.label, 'ru')));
        },

        /** Пересобрать список открытого меню: значения, счётчики и отбор по строке поиска. */
        refreshMenuOptions() {
            if (!this.openFilter) {
                this.menuOptions = [];
                this.menuTotal = 0;

                return;
            }

            const all = this.facetOptions(this.openFilter);
            const q = this.filterSearch.trim().toLowerCase();

            this.menuTotal = all.length;
            this.menuOptions = q ? all.filter(o => o.label.toLowerCase().includes(q)) : all;
        },

        /** Отметить или снять всё, что сейчас в списке. С поиском отмечает найденное. */
        get menuAllChecked() {
            return this.menuOptions.length > 0
                && this.menuOptions.every(o => this.isFacetChecked(this.openFilter, o.value));
        },

        toggleMenuAll() {
            const key = this.openFilter;
            const values = this.menuOptions.map(o => o.value);

            this.filters[key] = this.menuAllChecked
                ? this.filters[key].filter(v => !values.includes(v))
                : [...new Set([...this.filters[key], ...values])];

            this.afterFilterChange();
        },

        /** Общий хвост любого изменения отбора. Компонент дополняет его своим. */
        afterFilterChange() {
            this.refreshMenuOptions();
        },

        isFacetChecked(key, value) {
            return this.filters[key].includes(value);
        },

        toggleFacetValue(key, value) {
            this.filters[key] = this.isFacetChecked(key, value)
                ? this.filters[key].filter(v => v !== value)
                : [...this.filters[key], value];
            this.afterFilterChange();
        },

        clearFacet(key) {
            this.filters[key] = [];
            this.afterFilterChange();
        },

        clearAllFacets() {
            this.facets.forEach(f => { this.filters[f.key] = []; });
            this.chipsExpanded = false;
        },

        get activeFilterCount() {
            return this.facets.reduce((n, f) => n + this.filters[f.key].length, 0);
        },

        // Сколько чипов показываем свёрнутыми. «Выбрать все» на списке из семи десятков
        // компаний иначе разворачивает под панелью стену значений выше самой таблицы.
        chipsLimit: 6,
        chipsExpanded: false,

        get visibleChips() {
            return this.chipsExpanded ? this.filterChips : this.filterChips.slice(0, this.chipsLimit);
        },

        get hiddenChipsCount() {
            return Math.max(0, this.filterChips.length - this.visibleChips.length);
        },

        /** Чипы под панелью: что именно сейчас отобрано, с крестиком у каждого значения. */
        get filterChips() {
            const chips = [];

            this.facets.forEach(f => {
                this.filters[f.key].forEach(value => {
                    chips.push({ key: f.key, value, title: f.title, label: this.facetLabel(f.key, value) });
                });
            });

            return chips;
        },

        /** Открыть меню воронки под её кнопкой. */
        toggleFilterMenu(key, event) {
            if (this.openFilter === key) return this.closeFilterMenu();

            this.filterAnchor = event.currentTarget;
            this.filterSearch = '';
            this.openFilter = key;
            this.refreshMenuOptions();
            this.positionFilterMenu();

            // Курсор сразу в поиске, если он есть: набирать можно, не целясь мышью.
            this.$nextTick(() => this.$refs.filterSearchInput?.focus());
        },

        /**
         * Держать меню у своей колонки.
         *
         * Раньше на прокрутку таблицы меню просто закрывалось, и это выглядело как
         * «выбрал значение — меню исчезло»: список становился короче, браузер сам
         * поправлял прокрутку, и событие закрывало открытое меню. Теперь двигаем его
         * следом, а закрываем, только когда сама колонка ушла с экрана.
         */
        positionFilterMenu() {
            if (!this.openFilter || !this.filterAnchor) return;

            const rect = this.filterAnchor.getBoundingClientRect();
            const width = 260;

            if (rect.bottom < 0 || rect.top > window.innerHeight || rect.right < 0 || rect.left > window.innerWidth) {
                return this.closeFilterMenu();
            }

            this.filterMenu = {
                top: rect.bottom + 4,
                left: Math.max(8, Math.min(rect.left, window.innerWidth - width - 8)),
            };
        },

        /** Прокрутка идёт пачками событий, поэтому пересчёт кадром, а не на каждое. */
        onScrollWhileFiltering() {
            if (!this.openFilter || this.filterFrame) return;

            this.filterFrame = requestAnimationFrame(() => {
                this.filterFrame = null;
                this.positionFilterMenu();
            });
        },

        closeFilterMenu() {
            this.openFilter = null;
            this.filterAnchor = null;
            this.filterSearch = '';
            this.menuOptions = [];
            this.menuTotal = 0;
        },

        // --- Отбор в адресе страницы ---
        // Ссылкой с фильтрами можно поделиться или сохранить в закладки, а возврат на
        // страницу (в том числе кнопкой «назад») не теряет то, что человек уже выбрал.

        /** Разложить выбранное по параметрам адреса. */
        facetsToParams(params) {
            // Значения кладём отдельными параметрами, а не через запятую: в названиях
            // сфер, групп и компаний запятые встречаются («ЗП, кадры и соцфонд»).
            this.facets.forEach(f => this.filters[f.key].forEach(v => params.append(f.key, v)));

            return params;
        },

        /** Прочитать выбранное из параметров адреса. */
        facetsFromParams(params) {
            this.facets.forEach(f => { this.filters[f.key] = params.getAll(f.key); });
        },
    };
}
</script>
