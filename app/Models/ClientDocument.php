<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientDocument extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'client_id',
        'name',
        'original_name',
        'path',
        'mime_type',
        'size',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    /** Фронту нужна только ссылка; внутренний путь на диске наружу не отдаём. */
    protected $appends = ['url'];

    protected $hidden = ['path'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** Файл отдаёт контроллер с проверкой доступа, прямой ссылки на диск больше нет. */
    public function getUrlAttribute(): string
    {
        return route('documents.client', $this);
    }
}
