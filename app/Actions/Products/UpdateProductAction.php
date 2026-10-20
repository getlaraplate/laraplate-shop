<?php

declare(strict_types=1);

namespace Modules\Shop\Actions\Products;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Shop\Models\Product;

/**
 * Updates a {@see Product} aggregate in one transaction: its own columns, the content fields merged onto
 * it and, when the payload carries a `variants` key, its variants and their composition (see
 * {@see SyncProductVariantsAction}).
 *
 * Content fields are written through the product root, which is what makes the product's saving hook flush
 * the merged content, translatable fields included. Only the keys present in the payload are touched; the
 * tenant (`company_id`) never changes.
 *
 * @phpstan-import-type VariantEntry from SyncProductVariantsAction
 */
final class UpdateProductAction
{
    public function __construct(private readonly SyncProductVariantsAction $variants) {}

    /**
     * @param  array{kind?: string, is_published_in_shop?: bool, featured?: bool, release_date?: string|null, metadata?: array<string, mixed>|null, content?: array<string, mixed>, variants?: list<VariantEntry>}  $data
     *
     * @throws ValidationException
     */
    public function handle(Product $product, array $data): Product
    {
        return $product->getConnection()->transaction(function () use ($product, $data): Product {
            $fields = Arr::only($data['content'] ?? [], CreateProductAction::CONTENT_FIELDS);

            // A first translation in this locale needs a slug, and the title is all there is to derive it from.
            if (isset($fields['title']) && is_string($fields['title']) && ! array_key_exists('slug', $fields) && $product->content?->hasTranslation() === false) {
                $fields['slug'] = Str::slug($fields['title']);
            }

            $product->fill(Arr::only($data, CreateProductAction::COLUMNS));

            foreach ($fields as $key => $value) {
                $product->setAttribute($key, $value);
            }

            $product->save();

            if (array_key_exists('variants', $data)) {
                $this->variants->handle($product, $data['variants']);
            }

            return $product->refresh();
        });
    }
}
