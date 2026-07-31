<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;use Illuminate\Database\Eloquent\Model;
class Billing extends Model
{
    use BelongsToTenant;

    protected $fillable = ['name', 'code'];

    /** Коды режимов тарификации (переключатель логики цены). */
    public const CODE_INCLUDED    = 'included';     // входит в абонентку → 0
    public const CODE_BY_QUANTITY = 'by_quantity';  // ставка × кол-во
    public const CODE_ADDON       = 'addon';        // доп.услуга, ставка × кол-во
    public const CODE_NONE        = 'none';         // не тарифицируется → 0

    /** Коды, при которых цена БП = 0. */
    public const FREE_CODES = [self::CODE_INCLUDED, self::CODE_NONE];

    /** Коды, при которых цена берётся из ставки (rate × qty). */
    public const PAID_CODES = [self::CODE_BY_QUANTITY, self::CODE_ADDON];

    /** Кэш name => code, чтобы не дёргать справочник на каждый БП. */
    protected static array $codeByNameCache = [];

    /** Код режима по названию биллинга (как оно хранится в services.billing). */
    public static function codeForName(?string $name): ?string
    {
        if (!$name) {
            return null;
        }
        if (!array_key_exists($name, static::$codeByNameCache)) {
            static::$codeByNameCache[$name] = static::query()->where('name', $name)->value('code');
        }
        return static::$codeByNameCache[$name];
    }
}
