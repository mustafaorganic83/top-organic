<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests;

use App\Modules\Sales\Data\SyncOperation;
use Closure;

class SyncPushRequest extends SalesRequest
{
    public function rules(): array
    {
        $limit = (int) config('sales.sync.push_batch_limit', 200);

        return [...$this->scopeRules(),
            'client_batch_id' => ['required', 'ulid'],
            'schema_version' => ['sometimes', 'integer', 'min:1'],
            'operations' => ['required', 'array', 'min:1', "max:{$limit}"],
            'operations.*.client_operation_id' => $this->operation(),
            'operations.*.entity_type' => ['required', 'string', 'max:64'],
            'operations.*.entity_id' => ['required', 'ulid'],
            'operations.*.command' => ['required', 'string', 'max:64', 'regex:/\A[a-z][a-z0-9_.]{1,63}\z/'],
            'operations.*.device_sequence' => ['required', 'integer', 'min:1', 'max:9223372036854775807'],
            'operations.*.logical_clock' => ['sometimes', 'integer', 'min:0', 'max:9223372036854775807'],
            'operations.*.payload' => ['sometimes', 'array', $this->safePayload(...)],
        ];
    }

    /** @return array<int, SyncOperation> */
    public function operations(): array
    {
        return array_map(SyncOperation::fromArray(...), $this->validated('operations'));
    }

    private function safePayload(string $attribute, mixed $value, Closure $fail): void
    {
        $forbidden = ['tenant_id', 'branch_id', 'device_id', 'actor_id', 'user_id', 'approved_by',
            'pan', 'card_number', 'cvv', 'cvc', 'password', 'token', 'provider_reference', 'provider_snapshot'];
        $walk = function (mixed $node) use (&$walk, $forbidden, $fail): void {
            if (is_float($node)) {
                $fail('Offline payloads must not contain floating-point values.');

                return;
            }
            if (! is_array($node)) {
                return;
            }
            foreach ($node as $key => $child) {
                if (is_string($key) && in_array(strtolower($key), $forbidden, true)) {
                    $fail("The {$key} field is prohibited in offline payloads.");
                }
                $walk($child);
            }
        };
        $walk($value);
    }
}
