<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Http\Requests;

use App\Models\User;
use App\Modules\Kitchen\Data\KitchenContext;
use App\Modules\Kitchen\Exceptions\KitchenException;
use App\Support\Context\AppContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base request for the Kitchen module. Authorization is delegated to the route
 * permission middleware; here we only build the trusted context and forbid any
 * scope fields from leaking in via the payload.
 */
abstract class KitchenRequest extends FormRequest
{
    final public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    protected function scopeRules(): array
    {
        return array_fill_keys(
            ['tenant_id', 'branch_id', 'device_id', 'user_id', 'actor_id'],
            ['prohibited'],
        );
    }

    /** @return array<int, mixed> */
    protected function operation(bool $required = true): array
    {
        return [$required ? 'required' : 'sometimes', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/'];
    }

    public function kitchenContext(): KitchenContext
    {
        $app = app(AppContext::class);
        $user = $this->user('api');
        if (! $user instanceof User || $app->tenantId() === null || $app->branchId() === null) {
            throw new KitchenException(KitchenException::SCOPE_VIOLATION, 403,
                'A trusted tenant and branch context is required.');
        }

        return new KitchenContext($app->tenantId(), $app->branchId(), (int) $user->getKey(), $app->deviceId());
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', config('sales.pagination.default', 25));
    }
}
