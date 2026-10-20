<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Modules\CMS\Models\Content;
use Modules\Core\Helpers\LocaleContext;
use Modules\ERP\Models\Company;
use Modules\ERP\Models\Item;
use Modules\Shop\Actions\Products\CreateProductAction;
use Modules\Shop\Actions\Products\UpdateProductAction;
use Modules\Shop\Casts\EntityType;
use Modules\Shop\Enums\ProductKind;
use Modules\Shop\Models\Entity;
use Modules\Shop\Models\Product;
use Modules\Shop\Models\ProductVariant;
use Modules\Shop\Models\VariantItem;

beforeEach(function (): void {
    setupShopEntities();
});

/**
 * A complete create payload: product columns, content fields and one default variant composed of one
 * item. Any key can be replaced by the caller.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function product_write_payload(array $overrides = []): array
{
    return array_merge([
        'company_id' => Company::factory()->create()->getKey(),
        'kind' => ProductKind::Physical->value,
        'is_published_in_shop' => false,
        'featured' => false,
        'release_date' => null,
        'metadata' => [],
        'content' => [
            'title' => 'Moon lamp',
            'slug' => 'moon-lamp',
        ],
        'variants' => [
            [
                'is_default' => true,
                'attributes' => ['colour' => 'white'],
                'items' => [
                    ['item_id' => Item::factory()->create()->getKey(), 'quantity' => '1', 'role' => VariantItem::ROLE_MAIN],
                ],
            ],
        ],
    ], $overrides);
}

it('creates one content on the product entity and default preset', function (): void {
    $product = resolve(CreateProductAction::class)->handle(product_write_payload());

    $content = Content::withExtended()->findOrFail($product->content_id);

    expect($product->exists)->toBeTrue()
        ->and($product->content)->toBeInstanceOf(Content::class)
        ->and($product->content->is($content))->toBeTrue()
        ->and($content->extended_type)->toBe(Product::CONTENT_ALIAS)
        ->and($content->presettable_id)->toBe(shopProductPresettable()->id)
        ->and($content->entity_id)->toBe(Entity::query()->where('type', EntityType::Products)->sole()->id)
        ->and(Content::withExtended()->withTrashed()->count())->toBe(1)
        ->and(Product::query()->count())->toBe(1);
});

it('persists the content title through the create action', function (): void {
    $product = resolve(CreateProductAction::class)->handle(product_write_payload([
        'content' => ['title' => 'Moon lamp', 'slug' => 'moon-lamp'],
    ]));

    $fresh = Product::query()->findOrFail($product->getKey());

    expect($fresh->title)->toBe('Moon lamp')
        ->and($fresh->slug)->toBe('moon-lamp')
        ->and($fresh->content->title)->toBe('Moon lamp')
        ->and($fresh->content->translations()->where('locale', LocaleContext::get())->value('title'))->toBe('Moon lamp');
});

it('derives the slug from the title when none is given', function (): void {
    $product = resolve(CreateProductAction::class)->handle(product_write_payload([
        'content' => ['title' => 'Moon Lamp XL'],
    ]));

    expect(Product::query()->findOrFail($product->getKey())->slug)->toBe('moon-lamp-xl');
});

it('a create with two default variants ends with one default', function (): void {
    $item = Item::factory()->create();
    $variant = static fn (string $colour): array => [
        'is_default' => true,
        'attributes' => ['colour' => $colour],
        'items' => [['item_id' => $item->getKey(), 'quantity' => '1', 'role' => VariantItem::ROLE_MAIN]],
    ];

    $product = resolve(CreateProductAction::class)->handle(product_write_payload([
        'variants' => [$variant('white'), $variant('black')],
    ]));

    expect($product->variants()->count())->toBe(2)
        ->and($product->variants()->where('is_default', true)->count())->toBe(1);
});

it('rejects an invalid composition role and a missing item', function (): void {
    $action = resolve(CreateProductAction::class);
    $item = Item::factory()->create();
    $trashed_item = Item::factory()->create();
    $trashed_item->delete();

    $payload_with = static fn (array $row): array => product_write_payload([
        'variants' => [['is_default' => true, 'attributes' => [], 'items' => [$row]]],
    ]);

    $invalid_rows = [
        'role' => ['item_id' => $item->getKey(), 'quantity' => '1', 'role' => 'accessory'],
        'unknown item' => ['item_id' => 987654, 'quantity' => '1', 'role' => VariantItem::ROLE_MAIN],
        'trashed item' => ['item_id' => $trashed_item->getKey(), 'quantity' => '1', 'role' => VariantItem::ROLE_MAIN],
    ];

    foreach ($invalid_rows as $row) {
        expect(fn () => $action->handle($payload_with($row)))->toThrow(ValidationException::class);
    }

    // The whole aggregate is rolled back: neither the product, its content nor its variants survive.
    expect(Product::query()->count())->toBe(0)
        ->and(Content::withExtended()->withTrashed()->count())->toBe(0)
        ->and(ProductVariant::query()->count())->toBe(0)
        ->and(VariantItem::query()->count())->toBe(0);
});

it('rejects a create without a content title', function (): void {
    expect(fn () => resolve(CreateProductAction::class)->handle(product_write_payload(['content' => []])))
        ->toThrow(ValidationException::class);

    expect(Product::query()->count())->toBe(0)
        ->and(Content::withExtended()->withTrashed()->count())->toBe(0);
});

it('updates columns, content and composition', function (): void {
    $kept = Item::factory()->create();
    $dropped = Item::factory()->create();
    $added = Item::factory()->create();

    $product = resolve(CreateProductAction::class)->handle(product_write_payload([
        'featured' => false,
        'variants' => [
            [
                'is_default' => true,
                'attributes' => ['colour' => 'white'],
                'items' => [
                    ['item_id' => $kept->getKey(), 'quantity' => '1', 'role' => VariantItem::ROLE_COMPONENT],
                    ['item_id' => $dropped->getKey(), 'quantity' => '2', 'role' => VariantItem::ROLE_COMPONENT],
                ],
            ],
            ['is_default' => false, 'attributes' => ['colour' => 'black'], 'items' => []],
        ],
    ]));

    $variants = $product->variants()->orderBy('id')->get();
    $kept_variant = $variants->first();
    $removed_variant = $variants->last();

    $updated = resolve(UpdateProductAction::class)->handle(Product::query()->findOrFail($product->getKey()), [
        'featured' => true,
        'is_published_in_shop' => true,
        'content' => ['title' => 'Moon lamp v2'],
        'variants' => [
            [
                'id' => $kept_variant->getKey(),
                'is_default' => true,
                'attributes' => ['colour' => 'off-white'],
                'items' => [
                    ['item_id' => $kept->getKey(), 'quantity' => '3', 'role' => VariantItem::ROLE_COMPONENT],
                    ['item_id' => $added->getKey(), 'quantity' => '1.5', 'role' => VariantItem::ROLE_MAIN],
                ],
            ],
        ],
    ]);

    $fresh = Product::query()->findOrFail($product->getKey());
    $rows = $fresh->variants()->firstOrFail()->items()->orderBy('item_id')->get();

    expect($updated->featured)->toBeTrue()
        ->and($fresh->featured)->toBeTrue()
        ->and($fresh->is_published_in_shop)->toBeTrue()
        ->and($fresh->title)->toBe('Moon lamp v2')
        // The slug was not part of the update: it stays as created.
        ->and($fresh->slug)->toBe('moon-lamp')
        ->and($fresh->content->title)->toBe('Moon lamp v2')
        ->and(Content::withExtended()->withTrashed()->count())->toBe(1)
        ->and($fresh->variants()->count())->toBe(1)
        ->and(ProductVariant::query()->find($removed_variant->getKey()))->toBeNull()
        ->and($fresh->variants()->firstOrFail()->is($kept_variant))->toBeTrue()
        ->and($fresh->variants()->firstOrFail()->attributes)->toBe(['colour' => 'off-white'])
        ->and($rows->pluck('item_id')->all())->toBe([$kept->getKey(), $added->getKey()])
        ->and($rows->firstWhere('item_id', $kept->getKey())->quantity)->toBe('3.0000')
        ->and($rows->firstWhere('item_id', $added->getKey())->role)->toBe(VariantItem::ROLE_MAIN)
        ->and(VariantItem::query()->where('item_id', $dropped->getKey())->count())->toBe(0);
});

it('refuses to update a variant that belongs to another product', function (): void {
    $product = resolve(CreateProductAction::class)->handle(product_write_payload());
    $other = resolve(CreateProductAction::class)->handle(product_write_payload([
        'content' => ['title' => 'Sun lamp', 'slug' => 'sun-lamp'],
    ]));

    $foreign_variant = $other->variants()->firstOrFail();

    expect(fn () => resolve(UpdateProductAction::class)->handle(
        Product::query()->findOrFail($product->getKey()),
        ['variants' => [['id' => $foreign_variant->getKey(), 'is_default' => true, 'attributes' => [], 'items' => []]]],
    ))->toThrow(ValidationException::class);

    expect($other->variants()->count())->toBe(1);
});
