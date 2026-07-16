<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Requests;

use App\Models\User;
use App\Modules\Accounting\Data\AccountingContext;
use App\Modules\Accounting\Exceptions\AccountingException;
use App\Support\Context\AppContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base request for all Accounting endpoints. Resolves the trusted
 * AccountingContext from the JWT-injected AppContext singleton — the same
 * pattern used by the Kitchen and Menu modules.
 */
class AccountingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function accountingContext(): AccountingContext
    {
        $app = app(AppContext::class);
        $user = $this->user('api');

        if (! $user instanceof User || $app->tenantId() === null) {
            throw AccountingException::invalid('A trusted tenant context is required.');
        }

        return new AccountingContext(
            tenantId: (string) $app->tenantId(),
            branchId: $app->branchId(),
            userId: (string) $user->getKey(),
            deviceId: $app->deviceId(),
        );
    }
}
