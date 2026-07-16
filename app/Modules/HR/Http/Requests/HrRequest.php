<?php

declare(strict_types=1);

namespace App\Modules\HR\Http\Requests;

use App\Models\User;
use App\Modules\HR\Data\HrContext;
use App\Modules\HR\Exceptions\HrException;
use App\Support\Context\AppContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base request for the HR module. Authorization is delegated to the route
 * permission middleware; here we only build the trusted context and forbid
 * scope fields from leaking in via the payload. Read-only endpoints use this
 * base request directly, so it is concrete and validates nothing.
 */
class HrRequest extends FormRequest
{
    final public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    /** @return array<string, array<int, mixed>> */
    protected function scopeRules(): array
    {
        return array_fill_keys(
            ['tenant_id', 'branch_id', 'device_id', 'user_id', 'actor_id',
                'approved_by', 'reviewer_id', 'created_by'],
            ['prohibited'],
        );
    }

    /** @return array<int, mixed> */
    protected function version(bool $required = true): array
    {
        return [$required ? 'required' : 'sometimes', 'integer', 'min:0'];
    }

    public function hrContext(): HrContext
    {
        $app = app(AppContext::class);
        $user = $this->user('api');
        if (! $user instanceof User || $app->tenantId() === null || $app->branchId() === null) {
            throw new HrException(HrException::SCOPE_VIOLATION, 403,
                'A trusted tenant and branch context is required.');
        }

        return new HrContext($app->tenantId(), $app->branchId(), (int) $user->getKey(), $app->deviceId());
    }
}
