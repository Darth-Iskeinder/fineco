<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rate extends Model
{
    /**
     * Канонические единицы измерения (подсказки в форме).
     * Метка для отображения — на расчёт «цена × кол-во» не влияет.
     * Список — подсказки, поле допускает и произвольное значение.
     */
    public const UNITS = [
        'за сотрудника',
        'за единицу',
        'за документ',
        'за час',
        'за раз',
        'за месяц',
    ];

    protected $fillable = ['name', 'unit', 'price', 'conditions'];

    protected $casts = [
        'price' => 'decimal:2',
    ];
}
