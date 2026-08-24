<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Periodicity extends Model
{
    protected $fillable = ['name', 'kind'];

    /** Тип периодичности => подпись. Управляет поведением полей «Месяц»/«День» в форме БП. */
    public const KINDS = [
        'weekly'     => 'Еженедельно',
        'monthly'    => 'Ежемесячно',
        'quarterly'  => 'Ежеквартально',
        'yearly'     => 'Ежегодно',
        // Без дат: месяц и день не выбираются, вместо них срок в днях (см. Service::KIND_ON_REQUEST)
        'on_request' => 'По запросу',
    ];
}
