<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Пункт стандарта чек-листа. */
class AuditChecklistTemplateItem extends Model
{
    use BelongsToTenant;

    protected $fillable = ['template_id', 'section', 'account', 'point', 'how', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];

    public function template(): BelongsTo
    {
        return $this->belongsTo(AuditChecklistTemplate::class, 'template_id');
    }
}
