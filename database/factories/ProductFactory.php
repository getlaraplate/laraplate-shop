<?php

declare(strict_types=1);

namespace Modules\Shop\Database\Factories;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\CMS\Models\Content;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Overrides\Factory;
use Modules\ERP\Models\Company;
use Modules\Shop\Enums\ProductKind;
use Modules\Shop\Models\Product;
use Override;

final class ProductFactory extends Factory
{
    /**
     * @var class-string<Product>
     */
    #[Override]
    protected $model = Product::class;

    /**
     * The product's own columns. The backing {@see Content} is staged in {@see self::afterFactoryMaking()}
     * and persisted by the seam's `save()` bridge. Requires the Shop product entity and its default
     * preset to be seeded (call `setupShopEntities()` / run `ShopDatabaseSeeder` first).
     *
     * @return array<string, mixed>
     */
    #[Override]
    protected function definitionsArray(): array
    {
        return [
            'company_id' => Company::factory(),
            'kind' => fake()->randomElement(ProductKind::cases()),
            'is_published_in_shop' => fake()->boolean(),
            'featured' => fake()->boolean(20),
            'release_date' => fake()->boolean() ? fake()->dateTimeBetween('-1 year', '+1 month') : null,
            'metadata' => [],
        ];
    }

    /**
     * Stage the backing content against the seeded product entity and default preset, unless the
     * caller already supplied a `content_id` (e.g. to link an existing content on purpose).
     */
    #[Override]
    protected function afterFactoryMaking(Model $model): void
    {
        if (! $model instanceof Product) {
            return;
        }

        if ($model->content_id !== null || $model->relationLoaded('content')) {
            return;
        }

        $model->setTempContent(Product::newBackingContent());
    }

    /**
     * Give the backing content a default-locale translation (title/slug) so it resolves through the
     * CMS locale scope exactly like any real content.
     */
    #[Override]
    protected function afterFactoryCreating(Model $model): void
    {
        if (! $model instanceof Product) {
            return;
        }

        $content = $model->getRelation('content');

        if (! $content instanceof Content) {
            return;
        }

        $locale = LocaleContext::get();

        if ($content->hasTranslation($locale)) {
            return;
        }

        $title = fake()->unique()->sentence(3);

        $content->setTranslation($locale, [
            'title' => $title,
            'slug' => Str::slug($title) . '-' . Str::lower(Str::random(8)),
        ]);
    }
}
