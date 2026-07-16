<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Models\User;
use App\Modules\Inventory\Data\InventoryContext;
use App\Modules\Inventory\Exceptions\InventoryException;
use App\Support\Context\AppContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base request for the Inventory module. Authorization is delegated to the
 * route permission middleware; here we only build the trusted context and
 * forbid scope fields from leaking in via the payload. Read-only endpoints use
 * this base request directly, so it is concrete and validates nothing.
 */
class InventoryRequest extends FormRequest
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
                'dispatched_by', 'received_by', 'posted_by', 'counted_by', 'requested_by', 'approved_by'],
            ['prohibited'],
        );
    }

    /** @return array<int, mixed> */
    protected function version(bool $required = true): array
    {
        return [$required ? 'required' : 'sometimes', 'integer', 'min:0'];
    }

    /** @return array<int, mixed> */
    protected function operation(bool $required = true): array
    {
        return [$required ? 'required' : 'sometimes', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/'];
    }

    public function inventoryContext(): InventoryContext
    {
        $app = app(AppContext::class);
        $user = $this->user('api');
        if (! $user instanceof User || $app->tenantId() === null || $app->branchId() === null) {
            throw new InventoryException(InventoryException::SCOPE_VIOLATION, 403,
                'A trusted tenant and branch context is required.');
        }

        return new InventoryContext($app->tenantId(), $app->branchId(), (int) $user->getKey(), $app->deviceId());
    }
}
