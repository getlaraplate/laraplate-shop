<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Once;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Support\PermissionName;
use Modules\ERP\Models\Item;
use Modules\Shop\Enums\ProductKind;
use Modules\Shop\Models\Product;
use Modules\Shop\Models\ProductVariant;
use Modules\Shop\Models\VariantItem;

/*
 * The items of a variant are parts of a part: a variant item only exists inside its variant, which only exists inside
 * its product. Loaded from the variant they inherit the variant's visibility, so hydrating one accepts the select of
 * any model along that chain, the intermediate variant included, and not only the product at its top.
 */

beforeEach(function (): void {
    setupShopEntities();
    Artisan::call('permission:refresh', ['--quiet' => true]);

    // The seeding above hydrated models before their permissions were registered, and the existence of a
    // permission is memoized for the process: forget those answers so the checks below see the registered names.
    Once::flush();
});

/**
 * @param  list<class-string<Illuminate\Database\Eloquent\Model>>  $model_classes
 */
function variantitems_user(array $model_classes): User
{
    $role = Role::factory()->create(['name' => 'variantitems_' . uniqid(), 'guard_name' => 'web']);

    foreach ($model_classes as $model_class) {
        $role->givePermissionTo(Permission::query()->where(['name' => PermissionName::forClass($model_class, 'select'), 'guard_name' => 'web'])->firstOrFail());
    }

    $user = User::query()->findOrFail(Modules\Core\Models\User::factory()->create()->getKey());
    $user->assignRole($role);

    return $user->fresh();
}

function variantitems_variant(): ProductVariant
{
    $variant = ProductVariant::factory()->for(Product::factory()->create(['kind' => ProductKind::Physical]))->create();
    VariantItem::factory()->main()->for($variant, 'variant')->for(Item::factory()->create(), 'item')->create(['quantity' => 2]);

    return $variant;
}

it('loads the items of a variant for a role holding only the variant permission', function (): void {
    $variant = variantitems_variant();
    $user = variantitems_user([ProductVariant::class]);

    $response = $this->actingAs($user)->getJson('/app/crud/detail/shop/product_variant?' . http_build_query(['id' => $variant->id, 'relations' => ['items']]));

    $response->assertOk();
    expect(collect($response->json('data.items'))->pluck('role')->all())->toBe([VariantItem::ROLE_MAIN]);
});

it('does not hydrate a variant item for a caller who may read nothing along its chain', function (): void {
    $item = variantitems_variant()->items()->firstOrFail();
    Auth::login(variantitems_user([Item::class]));

    expect(fn (): ?VariantItem => VariantItem::query()->find($item->getKey()))->toThrow(new AuthorizationException(sprintf(
        'User cannot select %s nor its owners %s, %s',
        new VariantItem()->getTable(),
        new ProductVariant()->getTable(),
        new Product()->getTable(),
    )));
});
