<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests;

use App\Models\User;
use App\Modules\Sales\Data\SalesContext;
use App\Modules\Sales\Exceptions\SalesException;
use App\Support\Context\AppContext;
use Illuminate\Foundation\Http\FormRequest;

abstract class SalesRequest extends FormRequest
{
    final public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    protected function scopeRules(): array
    {
        return array_fill_keys(['tenant_id', 'branch_id', 'device_id', 'user_id', 'actor_id', 'approved_by'], ['prohibited']);
    }

    /** @return array<int, mixed> */
    protected function operation(bool $required = true): array
    {
        return [$required ? 'required' : 'sometimes', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/'];
    }

    /** @return array<int, mixed> */
    protected function amount(bool $required = true, int $minimum = 0): array
    {
        return [$required ? 'required' : 'sometimes', 'integer', "min:{$minimum}", 'max:9223372036854775807'];
    }

    /** @return array<int, mixed> */
    protected function quantity(bool $required = true): array
    {
        return [$required ? 'required' : 'sometimes', 'string', 'regex:/\A(?:[1-9][0-9]*|0\.[0-9]{0,5}[1-9]|[1-9][0-9]*\.[0-9]{1,6})\z/'];
    }

    public function salesContext(): SalesContext
    {
        $app = app(AppContext::class);
        $user = $this->user('api');
        if (! $user instanceof User || $app->tenantId() === null || $app->branchId() === null) {
            throw new SalesException(SalesException::SCOPE_VIOLATION, 403, 'A trusted tenant and branch context is required.');
        }

        return new SalesContext($app->tenantId(), $app->branchId(), (int) $user->getKey(), $app->deviceId());
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', config('sales.pagination.default', 25));
    }
}
