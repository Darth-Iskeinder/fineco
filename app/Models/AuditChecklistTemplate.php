<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Стандарт чек-листа: набор контрольных точек, который копируется в новый аудит. */
class AuditChecklistTemplate extends Model
{
    protected $fillable = ['name', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function items(): HasMany
    {
        return $this->hasMany(AuditChecklistTemplateItem::class, 'template_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /** Аудиты, созданные по этому стандарту (нужно, чтобы не удалить используемый шаблон). */
    public function audits(): HasMany
    {
        return $this->hasMany(Audit::class, 'template_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
