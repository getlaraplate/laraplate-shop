<?php

declare(strict_types=1);

namespace Modules\Shop\Actions\Products;

use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Modules\Shop\Models\Product;
use Modules\Shop\Models\ProductVariant;

/**
 * Brings the variants of a {@see Product}, and the composition rows of each, in line with a payload.
 *
 * The payload is the desired end state: a variant entry with an `id` updates that variant, one without
 * creates a new variant, and a live variant that is not listed is removed. Inside a variant the
 * composition rows are matched by `item_id` (an item appears at most once among a variant's live rows):
 * a listed item is updated or added, an unlisted one is removed. A variant entry without an `items` key
 * leaves its composition as it is.
 *
 * The values themselves are never judged here: the single default variant and the closed `role` set are
 * enforced by the models, and a rejected write aborts the caller's transaction.
 *
 * @phpstan-type ItemEntry array{item_id?: int|string|null, quantity?: int|float|string, role?: string}
 * @phpstan-type VariantEntry array{id?: int|string|null, is_default?: bool, attributes?: array<string, mixed>|null, items?: list<ItemEntry>}
 */
final class SyncProductVariantsAction
{
    /**
     * @param  list<VariantEntry>  $variants
     *
     * @throws ValidationException
     */
    public function handle(Product $product, array $variants): void
    {
        $existing = $product->variants()->with('items')->get()->keyBy('id');

        // Resolve every entry to the variant it updates (null for a new one) before anything is written.
        $targets = [];
        $kept_ids = [];

        foreach ($variants as $index => $entry) {
            $id = $entry['id'] ?? null;
            $target = $id === null ? null : $existing->get($id);

            if ($id !== null && ! $target instanceof ProductVariant) {
                throw ValidationException::withMessages([
                    "variants.{$index}.id" => ['The variant does not belong to this product.'],
                ]);
            }

            $targets[] = $target;

            if ($target instanceof ProductVariant) {
                $kept_ids[] = $target->getKey();
            }
        }

        // The removals go first, so the rest of the sync sees the final set of variants.
        foreach ($existing as $variant) {
            if (! in_array($variant->getKey(), $kept_ids, true)) {
                $variant->delete();
            }
        }

        foreach ($variants as $position => $entry) {
            $attributes = Arr::only($entry, ['is_default', 'attributes']);
            $variant = $targets[$position] ?? null;

            if ($variant instanceof ProductVariant) {
                $variant->fill($attributes)->save();
            } else {
                $variant = $product->variants()->create($attributes);
            }

            if (array_key_exists('items', $entry)) {
                $this->syncItems($variant, $entry['items']);
            }
        }
    }

    /**
     * @param  list<ItemEntry>  $items
     */
    private function syncItems(ProductVariant $variant, array $items): void
    {
        $variant->loadMissing('items');
        $existing = $variant->items->keyBy('item_id');
        $kept_item_ids = [];

        foreach ($items as $entry) {
            $item_id = $entry['item_id'] ?? null;
            $row = $item_id === null ? null : $existing->get($item_id);

            if ($row === null) {
                $variant->items()->create(Arr::only($entry, ['item_id', 'quantity', 'role']));

                continue;
            }

            $row->fill(Arr::only($entry, ['quantity', 'role']))->save();
            $kept_item_ids[] = $row->item_id;
        }

        foreach ($existing as $row) {
            if (! in_array($row->item_id, $kept_item_ids, true)) {
                $row->delete();
            }
        }
    }
}
