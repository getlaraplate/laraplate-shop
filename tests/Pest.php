<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Core\Models\Permission;
use Modules\Core\Models\User;
use Modules\Core\Services\DynamicContentsService;
use Modules\Core\Support\PermissionName;
use Modules\ERP\Models\Company;
use Modules\ERP\Models\Item;
use Modules\Shop\Actions\Products\CreateProductAction;
use Modules\Shop\Casts\EntityType;
use Modules\Shop\Database\Seeders\ShopDatabaseSeeder;
use Modules\Shop\Enums\ProductKind;
use Modules\Shop\Models\Entity;
use Modules\Shop\Models\Pivot\Presettable;
use Modules\Shop\Models\Product;
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

/**
 * A product stored through the write action: one default variant composed of one item, plus, when asked, a second
 * variant with no composition.
 */
function shop_stored_product(Company $company, Item $item, string $title = 'Moon lamp', bool $with_second_variant = false): Product
{
    $variants = [
        ['is_default' => true, 'attributes' => ['colour' => 'white'], 'items' => [
            ['item_id' => $item->getKey(), 'quantity' => '2', 'role' => VariantItem::ROLE_MAIN],
        ]],
    ];

    if ($with_second_variant) {
        $variants[] = ['is_default' => false, 'attributes' => ['colour' => 'black'], 'items' => []];
    }

    return resolve(CreateProductAction::class)->handle([
        'company_id' => $company->getKey(),
        'kind' => ProductKind::Physical->value,
        'is_published_in_shop' => true,
        'content' => [
            'title' => $title,
            'slug' => Str::slug($title),
            'components' => ['content' => ['blocks' => []]],
            'valid_from' => now()->subDay()->toDateTimeString(),
        ],
        'variants' => $variants,
    ]);
}

/**
 * A user holding exactly the given product permissions (the CRUD operations of the products table: `select`,
 * `insert`, `update`, ...), and no role. The permissions are on the guard of the admin panel: the guard of the
 * request is the one both the policy and the model layer look a permission up on.
 *
 * @param  list<string>  $operations
 */
function shop_product_user(array $operations): User
{
    $user = User::factory()->create();

    foreach ($operations as $operation) {
        $user->givePermissionTo(Permission::findOrCreate(PermissionName::forClass(Product::class, $operation), Filament::getPanel('admin')->getAuthGuard()));
    }

    return $user;
}
