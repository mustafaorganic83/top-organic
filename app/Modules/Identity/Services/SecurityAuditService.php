<?php

namespace App\Modules\Identity\Services;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Modules\Identity\Contracts\SecurityEventRepository;
use Illuminate\Support\Facades\DB;
use JsonException;

class SecurityAuditService
{
    public function __construct(private readonly SecurityEventRepository $events) {}

    /** @param array<string, mixed> $context */
    public function record(string $tenantId, ?string $branchId, string $category, string $action, array $context = []): AuditLog
    {
        return DB::transaction(function () use ($tenantId, $branchId, $category, $action, $context): AuditLog {
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $scope = $tenantId.':'.($branchId ?? 'tenant');
            $previous = $this->events->latestAudit($scope);
            $attributes = array_merge($context, [
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'sequence' => ($previous?->sequence ?? 0) + 1,
                'scope_key' => $scope,
                'category' => $category,
                'action' => $action,
                'previous_hash' => $previous?->entry_hash,
                'occurred_at' => $context['occurred_at'] ?? now(),
            ]);
            $attributes['entry_hash'] = hash('sha256', ($previous?->entry_hash ?? '').$this->canonicalJson($attributes));

            return $this->events->createAudit($attributes);
        }, 3);
    }

    /** @param array<string, mixed> $value */
    private function canonicalJson(array $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (is_array($item)) {
                if (! array_is_list($item)) {
                    ksort($item);
                }

                return array_map($normalize, $item);
            }

            return $item instanceof \DateTimeInterface ? $item->format(DATE_ATOM) : $item;
        };

        try {
            return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Audit payload could not be encoded.', previous: $exception);
        }
    }
}
