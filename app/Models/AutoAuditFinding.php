<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Находка автоаудита: проблемная строка, по которой ждём ответа бухгалтера.
 *
 * Живёт по ключу строки (AutoAuditResult::key). Открывает и закрывает её только прогон:
 *   - появилась строка с проблемным итогом (AutoAuditResult::FINDING_OUTCOMES): открывает;
 *   - по ключу стало «Совпало», итог ушёл в статус, который бухгалтеру не показываем, или
 *     строки не стало вовсе: закрывает;
 *   - итог сменился с одного проблемного на другой: находка та же, переписка остаётся.
 *
 * Состояние не хранится, а выводится из переписки (state). Так «Принято» само перестаёт
 * действовать, когда строка результата сменилась: принимали другую.
 */
class AutoAuditFinding extends Model
{
    use BelongsToTenant;

    public const WAITING  = 'waiting';    // ответа нет
    public const ANSWERED = 'answered';   // бухгалтер ответил, решает руководитель
    public const REJECTED = 'rejected';   // руководитель не принял ответ, ждём новый
    public const ACCEPTED = 'accepted';   // руководитель принял, строка «Объяснено»

    protected $fillable = ['client_id', 'key', 'opened_at', 'closed_at', 'closed_outcome'];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AutoAuditFindingMessage::class, 'finding_id')->orderBy('id');
    }

    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('closed_at');
    }

    /**
     * Что с находкой сейчас.
     *
     * Решает последнее сообщение:
     *   - «Принято» и «Объяснил» действуют, только если относились к той же строке
     *     результата, что стоит на странице сейчас: итог сменился, значит принимали и
     *     объясняли другое, и вопрос снова ждёт бухгалтера;
     *   - «Исправил» действует до следующего прогона. Прогон сдвигает время обновления у
     *     каждой действующей строки. Если после этого находка всё ещё открыта, значит
     *     исправление не помогло, и она снова ждёт ответа (fixDidNotHelp).
     */
    public function state(AutoAuditResult $current): string
    {
        $last = $this->messages->last();

        return match ($last?->kind) {
            AutoAuditFindingMessage::ACCEPTED  => $last->result_id === $current->id ? self::ACCEPTED : self::WAITING,
            AutoAuditFindingMessage::REJECTED  => self::REJECTED,
            AutoAuditFindingMessage::EXPLAINED => $this->explanationOutdated($current) ? self::WAITING : self::ANSWERED,
            AutoAuditFindingMessage::FIXED     => $this->fixDidNotHelp($current) ? self::WAITING : self::ANSWERED,
            default => self::WAITING,
        };
    }

    /** Бухгалтер объяснил, а итог после этого сменился: объяснение было про другие цифры. */
    public function explanationOutdated(AutoAuditResult $current): bool
    {
        $last = $this->messages->last();

        return $last?->kind === AutoAuditFindingMessage::EXPLAINED && $last->result_id !== $current->id;
    }

    /** Бухгалтер заменил файл, прогон после этого прошёл, а находка всё ещё открыта. */
    public function fixDidNotHelp(AutoAuditResult $current): bool
    {
        $last = $this->messages->last();

        return $last?->kind === AutoAuditFindingMessage::FIXED
            && $current->updated_at
            && $current->updated_at->greaterThan($last->created_at);
    }

    /** Ждёт ответа бухгалтера: ответа не было, или руководитель его не принял. */
    public function awaitsAnswer(AutoAuditResult $current): bool
    {
        return in_array($this->state($current), [self::WAITING, self::REJECTED], true);
    }

    /** Сколько полных дней висит. */
    public function daysOpen(): int
    {
        return (int) $this->opened_at->toImmutable()->startOfDay()->diffInDays(now()->startOfDay());
    }
}
