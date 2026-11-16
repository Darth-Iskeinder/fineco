-- Здоровье фирм за неделю. Только чтение, ничего не меняет.
-- База PostgreSQL (бой). Запуск с рабочей машины:
--
--   ssh -p 8071 client@77.235.20.172 \
--     "PGPASSWORD=password psql -h 127.0.0.1 -U fineco_user -d fineco_db" < docs/prod-health.sql
--
-- Неделя = последние 7 суток от момента запуска, «до того» = 7 суток перед ними.
-- Образец и удалённые фирмы в отчёт не идут.
--
-- Чего здесь нет и почему:
--   * входов в систему. Время входа нигде не пишется, сессии живут два часа.
--     Вместо этого блок 2 считает тех, кто что-то делал с задачами.
--   * просроченных и не начатых задач. Строка в buh_task_logs появляется, когда
--     задачу взяли в работу, а список «что должно быть» считается из сметы в коде.

\pset border 2
\pset null '-'

-- Обычные задачи и поручения одним списком.
CREATE TEMP VIEW health_tasks AS
SELECT tenant_id, employee_id, status, started_at, resumed_at, completed_at,
       review_started_at, reviewed_at, reviewed_by, rework_count, updated_at,
       due_date, force_closed, 'regular' AS kind
FROM buh_task_logs
UNION ALL
SELECT tenant_id, employee_id, status, started_at, resumed_at, completed_at,
       review_started_at, reviewed_at, reviewed_by, rework_count, updated_at,
       NULL::date, false, 'adhoc'
FROM buh_adhoc_tasks;

CREATE TEMP VIEW health_firms AS
SELECT id, name, status FROM tenants
WHERE deleted_at IS NULL AND is_template = false;

-- Временные представления созданы, дальше сессия только читает.
SET default_transaction_read_only = on;


\echo
\echo '=== 1. Фирмы: размер ==='
SELECT f.id, f.name, f.status,
       (SELECT count(*) FROM employees e
         WHERE e.tenant_id = f.id AND e.deleted_at IS NULL AND e.status = 'active') AS "сотрудников",
       (SELECT count(*) FROM clients c
         WHERE c.tenant_id = f.id AND c.deleted_at IS NULL AND c.is_active)         AS "клиентов в работе",
       (SELECT count(*) FROM clients c
         WHERE c.tenant_id = f.id AND c.deleted_at IS NULL AND c.is_active
           AND c.responsible_employee_id IS NULL)                                  AS "без ответственного"
FROM health_firms f
ORDER BY f.id;


\echo
\echo '=== 2. Кто работал с задачами (вместо входов) ==='
-- Работал = брал, закрывал или проверял задачу за период.
WITH acts AS (
    SELECT tenant_id, employee_id AS who, updated_at AS at FROM health_tasks
    UNION ALL
    SELECT tenant_id, reviewed_by, reviewed_at FROM health_tasks WHERE reviewed_by IS NOT NULL
)
SELECT f.id, f.name,
       count(DISTINCT a.who) FILTER (WHERE a.at >= now() - interval '7 days')        AS "работали за неделю",
       count(DISTINCT a.who) FILTER (WHERE a.at >= now() - interval '14 days'
                                       AND a.at <  now() - interval '7 days')        AS "неделей раньше",
       (SELECT count(*) FROM employees e
         WHERE e.tenant_id = f.id AND e.deleted_at IS NULL AND e.status = 'active')  AS "всего активных",
       to_char(max(a.at), 'DD.MM HH24:MI')                                           AS "последнее действие",
       date_part('day', now() - max(a.at))::int                                      AS "дней тишины"
FROM health_firms f
LEFT JOIN acts a ON a.tenant_id = f.id
GROUP BY f.id, f.name
ORDER BY f.id;


\echo
\echo '=== 3. Задачи: закрывают ли ==='
SELECT f.id, f.name,
       count(*) FILTER (WHERE t.status = 'completed'
                          AND t.completed_at >= now() - interval '7 days')            AS "закрыто за неделю",
       count(*) FILTER (WHERE t.status = 'completed'
                          AND t.completed_at >= now() - interval '14 days'
                          AND t.completed_at <  now() - interval '7 days')            AS "неделей раньше",
       count(*) FILTER (WHERE t.status = 'completed' AND t.kind = 'adhoc'
                          AND t.completed_at >= now() - interval '7 days')            AS "из них поручений",
       count(*) FILTER (WHERE t.status = 'completed' AND t.force_closed
                          AND t.completed_at >= now() - interval '7 days')            AS "принудительно",
       count(*) FILTER (WHERE t.status = 'completed' AND t.due_date IS NOT NULL
                          AND t.completed_at::date > t.due_date
                          AND t.completed_at >= now() - interval '7 days')            AS "позже срока",
       count(*) FILTER (WHERE t.status IN ('running', 'paused', 'pending'))           AS "сейчас в работе",
       count(*) FILTER (WHERE t.status = 'rework')                                    AS "на доработке"
FROM health_firms f
LEFT JOIN health_tasks t ON t.tenant_id = f.id
GROUP BY f.id, f.name
ORDER BY f.id;


\echo
\echo '=== 4. Проверка: идёт ли ==='
-- Срок проверки в приложении три дня, отсчёт от review_started_at.
-- «Возвращено» видит только то, что до сих пор на доработке.
SELECT f.id, f.name,
       count(*) FILTER (WHERE t.status = 'review')                                    AS "ждёт проверки",
       count(*) FILTER (WHERE t.status = 'review'
                          AND t.review_started_at < now() - interval '3 days')        AS "ждёт дольше 3 дней",
       (max(date_part('day', now() - t.review_started_at))
           FILTER (WHERE t.status = 'review'))::int                                   AS "самая старая, дней",
       count(*) FILTER (WHERE t.status = 'completed' AND t.reviewed_by IS NOT NULL
                          AND t.reviewed_at >= now() - interval '7 days')             AS "принято за неделю",
       count(*) FILTER (WHERE t.status = 'rework'
                          AND t.reviewed_at >= now() - interval '7 days')             AS "возвращено за неделю",
       count(DISTINCT t.reviewed_by)
           FILTER (WHERE t.reviewed_at >= now() - interval '7 days')                  AS "проверяющих"
FROM health_firms f
LEFT JOIN health_tasks t ON t.tenant_id = f.id
GROUP BY f.id, f.name
ORDER BY f.id;


\echo
\echo '=== 5. Залипшие таймеры и задачи на ушедших ==='
SELECT f.id, f.name,
       count(*) FILTER (WHERE t.status = 'running'
                          AND coalesce(t.resumed_at, t.started_at) < now() - interval '24 hours')
                                                                                      AS "таймер идёт дольше суток",
       count(*) FILTER (WHERE t.status IN ('running', 'paused', 'pending', 'rework', 'review')
                          AND (e.id IS NULL OR e.deleted_at IS NOT NULL OR e.status <> 'active'))
                                                                                      AS "открыто на неактивном"
FROM health_firms f
LEFT JOIN health_tasks t ON t.tenant_id = f.id
LEFT JOIN employees e    ON e.id = t.employee_id
GROUP BY f.id, f.name
ORDER BY f.id;


\echo
\echo '=== 6. Автоаудит: ночные прогоны ==='
-- Пустая строка у фирмы с включённым ночным прогоном значит, что крон не отработал.
SELECT f.id, f.name,
       coalesce(f2.settings::jsonb ->> 'auto_audit_nightly', '-')                     AS "ночной флаг",
       count(DISTINCT r.created_at::date)                                             AS "ночей из 7",
       to_char(max(r.created_at), 'DD.MM HH24:MI')                                    AS "последний прогон",
       sum(r.checks)                                                                  AS "проверок",
       sum(r.alarms)                                                                  AS "тревог",
       round(max(r.seconds))                                                          AS "самый долгий, сек"
FROM health_firms f
JOIN tenants f2 ON f2.id = f.id
LEFT JOIN auto_audit_watch_runs r
       ON r.tenant_id = f.id AND r.trigger = 'night'
      AND r.created_at >= now() - interval '7 days'
GROUP BY f.id, f.name, f2.settings::jsonb ->> 'auto_audit_nightly'
ORDER BY f.id;


\echo
\echo '=== 7. Автоаудит: что нашёл ==='
-- Действующие результаты (не заменённые более свежим прогоном).
SELECT f.id, f.name,
       count(*) FILTER (WHERE a.outcome = 'matched')                                  AS "сошлось",
       count(*) FILTER (WHERE a.outcome = 'mismatch')                                 AS "расхождение",
       count(*) FILTER (WHERE a.outcome = 'unverified')                               AS "не удалось проверить",
       count(*) FILTER (WHERE a.outcome = 'missing_document')                         AS "нет документа",
       count(*) FILTER (WHERE a.outcome IN ('wrong_document', 'wrong_period', 'scan', 'unreadable'))
                                                                                      AS "не тот файл",
       (SELECT count(*) FROM auto_audit_findings n
         WHERE n.tenant_id = f.id AND n.closed_at IS NULL)                            AS "находок открыто",
       (SELECT count(*) FROM auto_audit_findings n
         WHERE n.tenant_id = f.id AND n.opened_at >= now() - interval '7 days')       AS "новых за неделю",
       (SELECT count(*) FROM auto_audit_findings n
         WHERE n.tenant_id = f.id AND n.closed_at >= now() - interval '7 days')       AS "закрыто за неделю"
FROM health_firms f
LEFT JOIN auto_audit_results a ON a.tenant_id = f.id AND a.superseded_at IS NULL
GROUP BY f.id, f.name
ORDER BY f.id;


\echo
\echo '=== 8. Сбои за неделю ==='
-- «Повторов всего» накопительный счётчик за всё время жизни сбоя, не за неделю.
SELECT coalesce(f.id::text, '-') AS id, coalesce(f.name, 'без фирмы') AS name,
       count(*)                                                                       AS "разных сбоев",
       count(*) FILTER (WHERE r.first_seen_at >= now() - interval '7 days')           AS "новых",
       count(*) FILTER (WHERE r.resolved_at IS NULL)                                  AS "не разобрано",
       count(*) FILTER (WHERE r.kind = 'server')                                      AS "серверных",
       count(*) FILTER (WHERE r.kind = 'browser')                                     AS "в браузере",
       sum(r."count")                                                                 AS "повторов всего"
FROM error_reports r
LEFT JOIN health_firms f ON f.id = r.tenant_id
WHERE r.last_seen_at >= now() - interval '7 days'
GROUP BY f.id, f.name
ORDER BY f.id NULLS LAST;

\echo
\echo '--- упавшие задания очереди за неделю (общие, не по фирмам) ---'
SELECT count(*) AS "упало", to_char(max(failed_at), 'DD.MM HH24:MI') AS "последнее"
FROM failed_jobs
WHERE failed_at >= now() - interval '7 days';


\echo
\echo '=== 9. Расчёты с клиентами ==='
SELECT f.id, f.name,
       coalesce(f2.settings::jsonb ->> 'settlements', '-')                            AS "флаг",
       (SELECT count(*) FROM client_invoices i
         WHERE i.tenant_id = f.id AND i.issued_on >= current_date - 7)                AS "выставлено за неделю",
       (SELECT to_char(max(i.issued_on), 'DD.MM.YYYY') FROM client_invoices i
         WHERE i.tenant_id = f.id)                                                    AS "последнее начисление",
       (SELECT count(*) FROM client_payments p
         WHERE p.tenant_id = f.id AND p.created_at >= now() - interval '7 days')      AS "оплат внесено",
       (SELECT to_char(max(p.created_at), 'DD.MM.YYYY') FROM client_payments p
         WHERE p.tenant_id = f.id)                                                    AS "последняя оплата"
FROM health_firms f
JOIN tenants f2 ON f2.id = f.id
ORDER BY f.id;
