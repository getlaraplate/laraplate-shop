<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Services\DynamicContentsService;
use Modules\ERP\Models\Company;
use Modules\ERP\Models\Item;
use Modules\Shop\Casts\EntityType;
use Modules\Shop\Database\Seeders\ShopDatabaseSeeder;
use Modules\Shop\Enums\ProductKind;
use Modules\Shop\Models\Entity;
use Modules\Shop\Models\Pivot\Presettable;
use Modules\Shop\Models\VariantItem;
use Modules\Shop\Tests\TestCase;

pest()->extend(TestCase::class)
    ->in(__DIR__ . '/Unit', __DIR__ . '/Feature');

pest()->use(RefreshDatabase::class)
    ->in(__DIR__ . '/Feature');

/*
|--------------------------------------------------------------------------
| Hooks
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    DynamicContentsService::reset();
})->in(__DIR__ . '/Feature');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * Seeds the product Entity, its default Preset and the frozen Presettable version, so that a
 * product `Content` can be created against them. Runs the module seeder itself, which is
 * idempotent, so the tests and a real install cannot drift apart.
 */
function setupShopEntities(): void
{
    DynamicContentsService::reset();

    resolve(ShopDatabaseSeeder::class)->run();
}

/**
 * The active version of the product entity's default preset: the presettable a product `Content`
 * is created against. Scoped to the product entity because presettables are not partitioned by
 * module.
 */
function shopProductPresettable(): Presettable
{
    return Presettable::query()
        ->where('entity_id', Entity::query()->where('type', EntityType::Products)->sole()->id)
        ->sole();
}

/**
 * The state of a complete product wizard: the four steps of a physical product with one default variant
 * composed of one item. Any key can be replaced by the caller.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function product_wizard_state(Company $company, Item $item, array $overrides = []): array
{
    return array_merge([
        'company_id' => $company->getKey(),
        'kind' => ProductKind::Physical->value,
        'is_published_in_shop' => true,
        'featured' => false,
        'title' => 'Moon lamp',
        'slug' => 'moon-lamp',
        'components' => json_encode(['content' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'A lamp']]]]]),
        'valid_from' => now()->subDay()->toDateTimeString(),
        'valid_to' => null,
        'variants' => [
            [
                'is_default' => true,
                'attributes' => ['colour' => 'white'],
                'items' => [
                    ['item_id' => $item->getKey(), 'quantity' => '2', 'role' => VariantItem::ROLE_MAIN],
                ],
            ],
        ],
    ], $overrides);
}
