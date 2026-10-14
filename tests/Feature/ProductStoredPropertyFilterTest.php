<?php

declare(strict_types=1);

use App\Models\User;
use Modules\Core\Models\Role;
use Modules\Shop\Models\Product;
use Modules\Shop\Models\ProductVariant;

beforeEach(function (): void {
    app()->setLocale('en');
    setupShopEntities();

    $role = Role::factory()->create(['name' => config('permission.roles.superadmin'), 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);
    $this->actingAs($user);
});

/**
 * @return list<int>
 */
function listed_ids(string $entity, array $filter): array
{
    $response = test()->postJson(route('core.crud.list', ['module' => 'shop', 'entity' => $entity]), [
        'pagination' => 100,
        'page' => 1,
        'filters' => ['operator' => 'and', 'filters' => [$filter]],
    ]);
    $response->assertOk();

    return collect($response->json('data'))->pluck('id')->sort()->values()->all();
}

function product_titled(string $title): Product
{
    $product = Product::factory()->create();
    $product->content->setTranslation('en', ['title' => $title, 'slug' => str($title)->slug()->toString(), 'components' => []]);
    $product->content->save();

    return $product;
}

it('filters products by the title of their extended content', function (): void {
    $moon = product_titled('Moon lamp');
    product_titled('Sea chair');

    expect(listed_ids('products', ['property' => 'content.title', 'operator' => 'like', 'value' => '%Moon%']))->toBe([$moon->id]);
});

it('filters variants by the title of their product', function (): void {
    $moon = product_titled('Moon lamp');
    $variant = ProductVariant::factory()->create(['product_id' => $moon->id]);
    ProductVariant::factory()->create(['product_id' => product_titled('Sea chair')->id]);

    expect(listed_ids('product_variants', ['property' => 'product.content.title', 'operator' => 'like', 'value' => '%Moon%']))->toBe([$variant->id]);
});
