<?php

declare(strict_types=1);

namespace App\Modules\Menu\Services;

use App\Models\MediaAsset;
use App\Modules\Menu\Data\MenuContext;
use App\Modules\Menu\Exceptions\MenuException;
use App\Modules\Menu\Services\Concerns\GuardsMenuWrites;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Manages the polymorphic media gallery (images & videos) for menu entities.
 * Enforces a single primary asset per entity and keeps a stable sort order so
 * clients render galleries deterministically.
 */
final class MediaService
{
    use GuardsMenuWrites;

    private const ENTITIES = ['product', 'product_variant', 'category'];

    private const KINDS = ['image', 'video'];

    /** @return Collection<int, MediaAsset> */
    public function list(MenuContext $context, string $entityType, string $entityId): Collection
    {
        $this->assertEntity($entityType);

        return MediaAsset::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('entity_type', $entityType)->where('entity_id', $entityId)
            ->orderBy('sort_order')->orderBy('created_at')->get();
    }

    /** @param array<string, mixed> $data */
    public function create(MenuContext $context, array $data): MediaAsset
    {
        $this->assertEntity((string) $data['entity_type']);
        if (! in_array($data['kind'] ?? 'image', self::KINDS, true)) {
            throw MenuException::invalid('The media kind must be image or video.');
        }

        return DB::transaction(function () use ($context, $data): MediaAsset {
            $primary = (bool) ($data['is_primary'] ?? false);
            if ($primary) {
                $this->clearPrimary($context, (string) $data['entity_type'], (string) $data['entity_id']);
            }

            return MediaAsset::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'entity_type' => $data['entity_type'],
                'entity_id' => $data['entity_id'],
                'kind' => $data['kind'] ?? 'image',
                'url' => $data['url'],
                'thumbnail_url' => $data['thumbnail_url'] ?? null,
                'alt_text' => $data['alt_text'] ?? null,
                'is_primary' => $primary,
                'sort_order' => $data['sort_order'] ?? 0,
                'metadata' => $data['metadata'] ?? null,
                'status' => $data['status'] ?? 'active',
                'lock_version' => 0,
            ]);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function update(MenuContext $context, string $id, int $version, array $data): MediaAsset
    {
        return DB::transaction(function () use ($context, $id, $version, $data): MediaAsset {
            $asset = $this->find($context, $id);
            $this->assertVersion($asset->lock_version, $version);
            if (($data['is_primary'] ?? false) === true) {
                $this->clearPrimary($context, $asset->entity_type, $asset->entity_id);
            }
            $asset->fill(array_intersect_key($data, array_flip([
                'url', 'thumbnail_url', 'alt_text', 'is_primary', 'sort_order', 'metadata', 'status',
            ])));
            $asset->lock_version++;
            $asset->save();

            return $asset->refresh();
        }, 3);
    }

    public function delete(MenuContext $context, string $id): void
    {
        $this->find($context, $id)->delete();
    }

    private function find(MenuContext $context, string $id): MediaAsset
    {
        return MediaAsset::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->whereKey($id)->lockForUpdate()->first()
            ?? throw MenuException::notFound('The media asset was not found.');
    }

    private function clearPrimary(MenuContext $context, string $entityType, string $entityId): void
    {
        MediaAsset::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('entity_type', $entityType)->where('entity_id', $entityId)
            ->where('is_primary', true)->update(['is_primary' => false]);
    }

    private function assertEntity(string $entityType): void
    {
        if (! in_array($entityType, self::ENTITIES, true)) {
            throw MenuException::invalid('The media entity type is not supported.', ['entity_type' => $entityType]);
        }
    }
}
