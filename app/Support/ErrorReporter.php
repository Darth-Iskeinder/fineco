<?php

namespace App\Support;

use App\Models\ErrorReport;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Складывает сбои в журнал (таблица error_reports), чтобы о них узнавали мы,
 * а не клиент по телефону.
 *
 * Главное правило этого класса: он никогда не мешает. Любая ошибка внутри него
 * гасится молча — журнал не может быть причиной падения того, что он записывает.
 * Отдельно защищаемся от лавины: если запись сорвалась (упала база — а именно
 * тогда исключения и сыплются пачками), на остаток запроса выключаемся совсем,
 * иначе каждое следующее исключение тянуло бы за собой ещё один мёртвый запрос
 * в ту же базу.
 */
class ErrorReporter
{
    /** Длины полей — ровно те же, что в миграции: обрезаем здесь, а не ловим ошибку от базы. */
    private const MAX_MESSAGE = 1000;
    private const MAX_SOURCE  = 500;
    private const MAX_URL     = 500;
    private const MAX_CONTEXT = 5000;

    /** Запись сорвалась — до конца запроса больше не пробуем. */
    private static bool $off = false;

    /**
     * Серверное исключение. Шум сюда не пускаем: 404, 403, 419, отказ входа и
     * непрошедшая валидация — это нормальная жизнь приложения, а не поломка.
     * В файловый лог они по-прежнему попадают, журнал же должен оставаться
     * списком того, что действительно надо чинить.
     */
    public static function server(Throwable $e, ?Request $request = null): void
    {
        if (!self::isWorthReporting($e)) {
            return;
        }

        self::record(
            kind:    ErrorReport::KIND_SERVER,
            message: $e::class . ': ' . $e->getMessage(),
            source:  $e->getFile() . ':' . $e->getLine(),
            url:     $request?->fullUrl(),
            status:  $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500,
            context: $e->getTraceAsString(),
        );
    }

    /** Сбой в браузере: перехваченное исключение JS или ответ, который фронт не смог разобрать. */
    public static function browser(string $message, ?string $source, ?string $url, ?int $status, ?string $context): void
    {
        self::record(
            kind:    ErrorReport::KIND_BROWSER,
            message: $message,
            source:  $source,
            url:     $url,
            status:  $status,
            context: $context,
        );
    }

    private static function isWorthReporting(Throwable $e): bool
    {
        if ($e instanceof ValidationException
            || $e instanceof AuthenticationException
            || $e instanceof AuthorizationException
            || $e instanceof ModelNotFoundException) {
            return false;
        }

        // 4xx — ошибка обращения, а не системы. 5xx оставляем: их и ловим.
        if ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500) {
            return false;
        }

        return true;
    }

    private static function record(
        string $kind,
        string $message,
        ?string $source,
        ?string $url,
        ?int $status,
        ?string $context,
    ): void {
        if (self::$off) {
            return;
        }

        $fingerprint = null;

        try {
            $tenantId = TenantContext::has() ? TenantContext::id() : null;
            $message  = self::cut($message, self::MAX_MESSAGE);
            $source   = self::cut($source, self::MAX_SOURCE);
            $url      = self::cut($url, self::MAX_URL);

            // Отпечаток без изменчивых частей (времени, идентификаторов в адресе):
            // «та же ошибка» должна лечь в ту же строку и увеличить счётчик.
            $fingerprint = sha1(implode('|', [$tenantId, $kind, $message, $source, $status]));

            $now = now();

            // Сначала пробуем засчитать повтор — это самый частый случай.
            // resolved_at сбрасываем: раз ошибка вернулась, разобранной она не была.
            $updated = ErrorReport::where('fingerprint', $fingerprint)->update([
                'count'        => DB::raw('`count` + 1'),
                'last_seen_at' => $now,
                'resolved_at'  => null,
                'updated_at'   => $now,
            ]);

            if ($updated > 0) {
                return;
            }

            ErrorReport::create([
                'tenant_id'     => $tenantId,
                'employee_id'   => self::employeeId(),
                'kind'          => $kind,
                'fingerprint'   => $fingerprint,
                'message'       => $message,
                'source'        => $source,
                'url'           => $url,
                'status'        => $status,
                'context'       => self::cut($context, self::MAX_CONTEXT),
                'count'         => 1,
                'first_seen_at' => $now,
                'last_seen_at'  => $now,
            ]);
        } catch (Throwable $ignored) {
            // Два запроса сломались одновременно и оба вставляют первую строку —
            // второй упирается в уникальный ключ. Это не беда: досчитываем повтор
            // и работаем дальше. А вот если и это не прошло (нет базы, нет
            // таблицы) — журналу сейчас не время, выключаемся до конца запроса.
            if ($fingerprint === null) {
                self::$off = true;

                return;
            }

            try {
                ErrorReport::where('fingerprint', $fingerprint)->update([
                    'count'        => DB::raw('`count` + 1'),
                    'last_seen_at' => now(),
                ]);
            } catch (Throwable $fatal) {
                self::$off = true;
            }
        }
    }

    /** Кто наткнулся. Может не быть вовсе: крон, гость, падение до авторизации. */
    private static function employeeId(): ?int
    {
        try {
            return auth('employee')->id();
        } catch (Throwable $ignored) {
            return null;
        }
    }

    private static function cut(?string $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, $limit);
    }
}
