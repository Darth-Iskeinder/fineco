<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;use Illuminate\Database\Eloquent\Model;
class ServiceGroup extends Model
{
    use BelongsToTenant;

    protected $fillable = ['name'];
}
