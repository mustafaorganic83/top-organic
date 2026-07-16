<?php

declare(strict_types=1);

namespace App\Modules\Tables\Http\Requests;

use App\Models\User;
use App\Modules\Tables\Data\ReservationContext;
use App\Modules\Tables\Exceptions\ReservationException;
use App\Support\Context\AppContext;
use Illuminate\Foundation\Http\FormRequest;

abstract class ReservationRequest extends FormRequest
{
    final public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    protected function scopeRules(): array
    {
        return array_fill_keys(
            ['tenant_id', 'branch_id', 'device_id', 'user_id', 'created_by', 'seated_by', 'actor_id'],
            ['prohibited'],
        );
    }

    public function reservationContext(): ReservationContext
    {
        $app = app(AppContext::class);
        $user = $this->user('api');
        if (! $user instanceof User || $app->tenantId() === null || $app->branchId() === null) {
            throw new ReservationException(ReservationException::SCOPE_VIOLATION, 403,
                'A trusted tenant and branch context is required.');
        }

        return new ReservationContext($app->tenantId(), $app->branchId(), (int) $user->getKey(), $app->deviceId());
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 25);
    }

    /** @return array<int, mixed> */
    protected function version(bool $required = true): array
    {
        return [$required ? 'required' : 'sometimes', 'integer', 'min:0'];
    }
}
