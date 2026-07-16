<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;

abstract class BranchScopedModel extends TenantScopedModel
{
    use BelongsToBranch;
}
