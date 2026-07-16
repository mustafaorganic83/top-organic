<?php

declare(strict_types=1);

namespace App\Modules\Menu\Http\Requests;

use App\Models\User;
use App\Modules\Menu\Data\MenuContext;
use App\Modules\Menu\Exceptions\MenuException;
use App\Support\Context\AppContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base request for the Menu & Recipe module. Authorization is delegated to the
 * route permission middleware; here we only build the trusted context and
 * forbid any scope fields from leaking in via the payload.
 */
class MenuRequest extends FormRequest
{
    final public function authorize(): bool
    {
        return true;
    }

    /**
     * Read-only endpoints use this base request directly (only for the trusted
     * context), so it must be instantiable and validate nothing by default.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /** @return array<string, array<int, mixed>> */
    protected function scopeRules(): array
    {
        return array_fill_keys(
            ['tenant_id', 'branch_id', 'device_id', 'user_id', 'actor_id', 'published_by'],
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

    public function menuContext(): MenuContext
    {
        $app = app(AppContext::class);
        $user = $this->user('api');
        if (! $user instanceof User || $app->tenantId() === null || $app->branchId() === null) {
            throw new MenuException(MenuException::SCOPE_VIOLATION, 403,
                'A trusted tenant and branch context is required.');
        }

        return new MenuContext($app->tenantId(), $app->branchId(), (int) $user->getKey(), $app->deviceId());
    }
}
