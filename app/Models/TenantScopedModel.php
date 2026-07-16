<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

abstract class TenantScopedModel extends Model
{
    use BelongsToTenant, HasFactory, HasUlids;

    protected $guarded = ['id'];
}
