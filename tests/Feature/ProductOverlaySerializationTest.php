<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Role;
use Modules\ERP\Models\Item;
use Modules\Shop\Models\Product;
use Modules\Shop\Models\ProductVariant;
use Modules\Shop\Models\VariantItem;

beforeEach(function (): void {
    app()->setLocale('en');
    setupShopEntities();
});

function product_with_title(string $title, string $locale = 'en'): Product
{
    $product = Product::factory()->create();

    // Moved to its own id range, so the id of a product never equals the one of its content by chance.
    DB::table($product->getTable())->where('id', $product->id)->update(['id' => $product->id + 900]);
    $product = Product::query()->findOrFail($product->id + 900);
    $product->content->setTranslation($locale, ['title' => $title, 'slug' => str($title)->slug()->toString(), 'components' => []]);
    $product->content->save();

    return $product->fresh();
}

it('serializes the title and slug of the merged content at the root and keeps the nested content', function (): void {
    $product = product_with_title('Moon lamp');
    $array = $product->toArray();

    expect($array)->toHaveKeys(['title', 'slug', 'content'])
        ->and($array['title'])->toBe('Moon lamp')
        ->and($array['slug'])->toBe('moon-lamp')
        ->and($array['content']['title'])->toBe('Moon lamp');
});

it('never replaces a native key of the product with one of the content', function (): void {
    $product = product_with_title('Moon lamp');
    $array = $product->toArray();

    expect($product->id)->not->toBe($product->content_id)
        ->and($array['id'])->toBe($product->id)
        ->and($array['content_id'])->toBe($product->content_id)
        ->and($array['company_id'])->toBe($product->company_id)
        ->and($array['content'])->toBeArray();
});

it('does not overlay a key the content hides', function (): void {
    $product = product_with_title('Moon lamp');
    $product->content->makeHidden('statistics');

    expect($product->toArray())->not->toHaveKey('statistics');
});

it('adds nothing and runs no query when the content is not loaded', function (): void {
    product_with_title('Moon lamp');
    $product = Product::query()->without('content')->firstOrFail();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $array = $product->toArray();

    expect($array)->not->toHaveKey('title')
        ->and($queries)->toBe(0);
});

it('carries the overlay in the CRUD list and detail answers, in the locale of the request', function (): void {
    $product = product_with_title('Moon lamp');
    $product->content->setTranslation('it', ['title' => 'Lampada lunare', 'slug' => 'lampada-lunare', 'components' => []]);
    $product->content->save();

    $role = Role::factory()->create(['name' => config('permission.roles.superadmin'), 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);
    $this->actingAs($user);

    $list = $this->postJson(route('core.crud.list', ['module' => 'shop', 'entity' => 'products']), ['pagination' => 10, 'page' => 1]);
    $detail = $this->getJson(route('core.crud.detail', ['module' => 'shop', 'entity' => 'products']) . '?id=' . $product->id);

    expect($list->json('data.0.title'))->toBe('Moon lamp')
        ->and($detail->json('data.title'))->toBe('Moon lamp');

    $user->forceFill(['lang' => 'it'])->save();
    $italian = $this->postJson(route('core.crud.list', ['module' => 'shop', 'entity' => 'products']), ['pagination' => 10, 'page' => 1]);

    expect($italian->json('data.0.title'))->toBe('Lampada lunare');
});

it('carries the overlay on the product relation of a variant', function (): void {
    $product = product_with_title('Moon lamp');
    ProductVariant::factory()->create(['product_id' => $product->id]);

    $role = Role::factory()->create(['name' => config('permission.roles.superadmin'), 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);
    $this->actingAs($user);

    $response = $this->postJson(route('core.crud.list', ['module' => 'shop', 'entity' => 'product_variants']), [
        'pagination' => 10,
        'page' => 1,
        'relations' => ['product'],
    ]);

    expect($response->json('data.0.product.title'))->toBe('Moon lamp');
});

it('labels the composition rows of a variant with their ERP item', function (): void {
    $product = product_with_title('Moon lamp');
    $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
    $item = Item::factory()->create(['name' => 'Bulb', 'sku' => 'BLB-1']);
    VariantItem::factory()->main()->for($variant, 'variant')->for($item, 'item')->create();

    $role = Role::factory()->create(['name' => config('permission.roles.superadmin'), 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);
    $this->actingAs($user);

    $response = $this->getJson(route('core.crud.detail', ['module' => 'shop', 'entity' => 'product_variants'])
        . '?id=' . $variant->id . '&relations[]=product&relations[]=items.item');

    $response->assertOk();
    expect($response->json('data.product.title'))->toBe('Moon lamp')
        ->and($response->json('data.items.0.item.name'))->toBe('Bulb')
        ->and($response->json('data.items.0.item.sku'))->toBe('BLB-1');
});
