<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;use Illuminate\Database\Eloquent\Model;
class Sphere extends Model
{
    use BelongsToTenant;

    protected $fillable = ['name'];
}
