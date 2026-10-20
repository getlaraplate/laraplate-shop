<?php

declare(strict_types=1);

namespace Modules\Shop\Actions\Products;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Shop\Models\Product;

/**
 * Creates a whole product aggregate in one transaction: the {@see Product}, its backing CMS content and
 * its variants with their composition.
 *
 * The content is built by {@see Product::newBackingContent()} (the Shop product entity and its default
 * preset), filled with the content fields of the payload and staged on the product, so the content-extension
 * seam persists and links it on the product's first save. Content keys are never mass-assigned onto the
 * product. The translatable fields (`title`, `slug`, `components`) are written in the current locale and
 * flushed by the content's own save.
 *
 * @phpstan-import-type VariantEntry from SyncProductVariantsAction
 */
final class CreateProductAction
{
    /**
     * The product's own writable columns (`company_id` follows the current company unless given).
     *
     * @var list<string>
     */
    public const array COLUMNS = ['kind', 'is_published_in_shop', 'featured', 'release_date', 'metadata'];

    /**
     * The content-owned fields a payload may carry.
     *
     * @var list<string>
     */
    public const array CONTENT_FIELDS = ['title', 'slug', 'components', 'valid_from', 'valid_to'];

    public function __construct(private readonly SyncProductVariantsAction $variants) {}

    /**
     * @param  array{company_id?: int|string|null, kind?: string, is_published_in_shop?: bool, featured?: bool, release_date?: string|null, metadata?: array<string, mixed>|null, content?: array<string, mixed>, variants?: list<VariantEntry>}  $data
     *
     * @throws ValidationException
     */
    public function handle(array $data): Product
    {
        $fields = Arr::only($data['content'] ?? [], self::CONTENT_FIELDS);
        $title = $fields['title'] ?? null;

        // A product whose content has no title in the current locale cannot be read back through the locale scope.
        if (! is_string($title) || mb_trim($title) === '') {
            throw ValidationException::withMessages([
                'content.title' => ['A product needs a title.'],
            ]);
        }

        $fields['slug'] ??= Str::slug($title);

        $product = new Product();

        return $product->getConnection()->transaction(function () use ($product, $data, $fields): Product {
            $content = Product::newBackingContent();

            foreach ($fields as $key => $value) {
                $content->setAttribute($key, $value);
            }

            $product->fill(Arr::only($data, [...self::COLUMNS, 'company_id']));
            $product->setTempContent($content);
            $product->save();

            $this->variants->handle($product, $data['variants'] ?? []);

            return $product;
        });
    }
}
