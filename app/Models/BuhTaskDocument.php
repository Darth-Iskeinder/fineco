<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BuhTaskDocument extends Model
{
    protected $fillable = ['path', 'name'];

    /** Фронту нужна только ссылка; внутренний путь на диске наружу не отдаём. */
    protected $appends = ['url'];

    protected $hidden = ['path'];

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Файл отдаёт контроллер с проверкой доступа, прямой ссылки на диск больше нет. */
    public function getUrlAttribute(): string
    {
        return route('documents.task', $this);
    }
}
