<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Http\Requests;

use App\Models\User;
use App\Modules\Procurement\Data\ProcurementContext;
use App\Modules\Procurement\Exceptions\ProcurementException;
use App\Support\Context\AppContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base request for the Procurement module. Authorization is delegated to the
 * route permission middleware; here we only build the trusted context and
 * forbid scope fields from leaking in via the payload. Read-only endpoints
 * use this base request directly, so it is concrete and validates nothing.
 */
class ProcurementRequest extends FormRequest
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
                'requested_by', 'approved_by', 'received_by', 'inspected_by', 'created_by'],
            ['prohibited'],
        );
    }

    /** @return array<int, mixed> */
    protected function version(bool $required = true): array
    {
        return [$required ? 'required' : 'sometimes', 'integer', 'min:0'];
    }

    public function procurementContext(): ProcurementContext
    {
        $app = app(AppContext::class);
        $user = $this->user('api');
        if (! $user instanceof User || $app->tenantId() === null || $app->branchId() === null) {
            throw new ProcurementException(ProcurementException::SCOPE_VIOLATION, 403,
                'A trusted tenant and branch context is required.');
        }

        return new ProcurementContext($app->tenantId(), $app->branchId(), (int) $user->getKey(), $app->deviceId());
    }
}
