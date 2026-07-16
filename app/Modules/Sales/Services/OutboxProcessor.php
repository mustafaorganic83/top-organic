<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Models\DomainOutboxEvent;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Drains pending DomainOutboxEvent rows for eventual publishing. Claims a
 * bounded slice under a row lock so concurrent workers never double-publish,
 * acknowledges on success, and applies capped exponential backoff on failure
 * — moving poison rows to the dead-letter state once max attempts is reached.
 */
final class OutboxProcessor
{
    /**
     * Claims and publishes a slice of pending events. The publisher closure
     * receives each event and either returns normally (ack) or throws (fail).
     * Returns per-outcome counts for observability.
     *
     * @param  (Closure(DomainOutboxEvent): void)|null  $publisher
     * @return array{claimed: int, published: int, failed: int, dead_lettered: int}
     */
    public function process(?string $tenantId = null, ?int $limit = null, ?Closure $publisher = null): array
    {
        $events = $this->claim($tenantId, $limit);
        $published = 0;
        $failed = 0;
        $deadLettered = 0;
        foreach ($events as $event) {
            try {
                if ($publisher !== null) {
                    $publisher($event);
                }
                $this->acknowledge($event);
                $published++;
            } catch (Throwable $throwable) {
                $this->fail($event) ? $deadLettered++ : null;
                $failed++;
            }
        }

        return ['claimed' => $events->count(), 'published' => $published,
            'failed' => $failed, 'dead_lettered' => $deadLettered];
    }

    /**
     * Atomically claims due pending events into the claimed state so no other
     * worker picks them up.
     *
     * @return Collection<int, DomainOutboxEvent>
     */
    public function claim(?string $tenantId, ?int $limit): Collection
    {
        $limit = max(1, $limit ?? (int) config('sales.sync.outbox.claim_limit', 100));

        return DB::transaction(function () use ($tenantId, $limit): Collection {
            $staleBefore = now()->subSeconds((int) config('sales.sync.outbox.claim_timeout_seconds', 300));
            $query = DomainOutboxEvent::withoutGlobalScopes()
                ->where(function ($query) use ($staleBefore): void {
                    $query->where(function ($pending): void {
                        $pending->where('state', 'pending')
                            ->where(fn ($due) => $due->whereNull('available_at')->orWhere('available_at', '<=', now()));
                    })->orWhere(function ($claimed) use ($staleBefore): void {
                        $claimed->where('state', 'claimed')->where('updated_at', '<=', $staleBefore);
                    });
                })
                ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
                ->orderBy('available_at')->orderBy('id')->limit($limit)->lockForUpdate();
            $events = $query->get();
            if ($events->isNotEmpty()) {
                DomainOutboxEvent::withoutGlobalScopes()->whereIn('id', $events->pluck('id'))
                    ->update(['state' => 'claimed', 'updated_at' => now()]);
                $events->each->refresh();
            }

            return $events;
        }, 3);
    }

    private function acknowledge(DomainOutboxEvent $event): void
    {
        $event->update(['state' => 'published', 'published_at' => now(),
            'lock_version' => $event->lock_version + 1]);
    }

    /**
     * Records a failed attempt with capped exponential backoff, or dead-letters
     * the row once max attempts is exhausted. Returns true when dead-lettered.
     */
    public function fail(DomainOutboxEvent $event): bool
    {
        $attempts = $event->attempt_count + 1;
        $max = (int) config('sales.sync.outbox.max_attempts', 8);
        if ($attempts >= $max) {
            $event->update(['state' => 'dead_lettered', 'attempt_count' => $attempts,
                'failed_at' => now(), 'lock_version' => $event->lock_version + 1]);

            return true;
        }
        $event->update(['state' => 'pending', 'attempt_count' => $attempts,
            'available_at' => now()->addSeconds($this->backoffSeconds($attempts)),
            'failed_at' => now(), 'lock_version' => $event->lock_version + 1]);

        return false;
    }

    private function backoffSeconds(int $attempts): int
    {
        $base = (int) config('sales.sync.outbox.retry_seconds', 30);
        $cap = (int) config('sales.sync.outbox.retry_backoff_cap_seconds', 3600);

        return (int) min($cap, $base * (2 ** ($attempts - 1)));
    }
}
