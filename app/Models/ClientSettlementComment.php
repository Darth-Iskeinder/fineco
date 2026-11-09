<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Комментарий про деньги клиента в «Расчётах с клиентами». Один на клиента,
 * без истории: видно только, кто и когда менял последним.
 */
class ClientSettlementComment extends Model
{
    use BelongsToTenant;

    public const MAX_LENGTH = 1000;

    protected $fillable = ['client_id', 'body', 'updated_by'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'updated_by')->withTrashed();
    }

    /**
     * Записать комментарий. Пустой текст убирает его. Тот же текст ничего не меняет:
     * иначе подпись «кто менял» переезжала бы на того, кто просто нажал «Сохранить».
     */
    public static function saveFor(Client $client, string $body, ?Employee $by): ?self
    {
        $comment = self::query()->where('client_id', $client->id)->first();

        if ($body === '') {
            $comment?->delete();

            return null;
        }

        if ($comment && $comment->body === $body) {
            return $comment;
        }

        $comment ??= new self(['client_id' => $client->id]);
        $comment->fill(['body' => $body, 'updated_by' => $by?->id])->save();

        return $comment->load('author:id,full_name');
    }

    /** «Гульнара Ибраева, 09.11.2026» */
    public function signature(): string
    {
        $date = $this->updated_at?->copy()->setTimezone(config('app.display_timezone'))->format('d.m.Y');

        return trim(($this->author?->full_name ?? '') . ($date ? ', ' . $date : ''), ', ');
    }
}
