<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Arr;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\CMS\Models\Content;
use Modules\CMS\Models\Translations\ContentTranslation;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\ERP\Models\Company;
use Modules\ERP\Models\Item;
use Modules\Shop\Enums\ProductKind;
use Modules\Shop\Filament\Resources\Products\Pages\CreateProduct;
use Modules\Shop\Filament\Resources\Products\Pages\EditProduct;
use Modules\Shop\Filament\Resources\Products\Pages\ListProducts;
use Modules\Shop\Filament\Resources\Products\ProductResource;
use Modules\Shop\Filament\ShopPlugin;
use Modules\Shop\Models\Product;
use Modules\Shop\Models\ProductVariant;
use Modules\Shop\Models\VariantItem;

beforeEach(function (): void {
    setupShopEntities();

    if (! class_exists(App\Models\User::class)) {
        class_alias(User::class, App\Models\User::class);
    }

    /** @var App\Models\User $admin */
    $admin = App\Models\User::query()->create(User::factory()->raw([
        'email' => 'shop-product-admin-' . uniqid() . '@example.com',
        'password' => 'Aa1!FilamentAdminPass',
    ]));

    $superadmin_role = Role::query()->firstOrCreate(['name' => config('permission.roles.superadmin'), 'guard_name' => 'web']);
    $admin->roles()->attach($superadmin_role);
    $admin->load('roles');

    $this->actingAs($admin);
});

it('registers the product resource under the shop catalog slug with its pages', function (): void {
    expect(ProductResource::getModel())->toBe(Product::class)
        ->and(ProductResource::getSlug())->toBe('shop/products')
        ->and(ProductResource::getPages())->toHaveKeys(['index', 'create', 'edit'])
        ->and(ProductResource::getPages()['index']->getPage())->toBe(ListProducts::class)
        ->and(ProductResource::getPages()['create']->getPage())->toBe(CreateProduct::class)
        ->and(ProductResource::getPages()['edit']->getPage())->toBe(EditProduct::class);
});

it('is discovered by the admin panel through the shop plugin', function (): void {
    expect(Filament::getPanel('admin')->getResources())->toContain(ProductResource::class)
        ->and(Filament::getPanel('admin')->getPlugin('shop'))->toBeInstanceOf(ShopPlugin::class);
});

it('renders the product list', function (): void {
    Livewire::test(ListProducts::class)->assertSuccessful();
});

it('starts the wizard from a physical product published now, with one default variant', function (): void {
    $wizard = Livewire::test(CreateProduct::class)
        ->assertFormSet([
            'kind' => ProductKind::Physical->value,
            'is_published_in_shop' => false,
            'featured' => false,
            'components' => '{}',
        ])
        ->assertFormFieldHidden('order_column');

    expect($wizard->get('data.valid_from'))->not->toBeNull()
        ->and(array_values($wizard->get('data.variants')))->toHaveCount(1)
        ->and(array_values($wizard->get('data.variants'))[0]['is_default'])->toBeTrue();
});

it('creates a product through the wizard', function (): void {
    $company = Company::factory()->create();
    $item = Item::factory()->create(['company_id' => $company->getKey()]);
    $state = product_wizard_state($company, $item);

    Livewire::test(CreateProduct::class)
        ->assertWizardCurrentStep(1)
        ->fillForm(Arr::only($state, ['company_id', 'kind', 'is_published_in_shop', 'featured']))
        ->goToWizardStep(2)
        ->assertHasNoFormErrors()
        ->assertWizardCurrentStep(2)
        ->fillForm(Arr::only($state, ['title', 'slug', 'components', 'valid_from', 'valid_to']))
        ->goToWizardStep(3)
        ->assertHasNoFormErrors()
        ->assertWizardCurrentStep(3)
        ->fillForm(['variants' => [['is_default' => true, 'attributes' => ['colour' => 'white']]]])
        ->goToWizardStep(4)
        ->assertHasNoFormErrors()
        ->assertWizardCurrentStep(4)
        ->fillForm(Arr::only($state, ['variants']))
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::query()->with(['variants.items'])->sole();
    $content = Content::withExtended()->findOrFail($product->content_id);

    expect($product->company_id)->toBe($company->getKey())
        ->and($product->kind)->toBe(ProductKind::Physical)
        ->and($product->is_published_in_shop)->toBeTrue()
        ->and($product->featured)->toBeFalse()
        ->and($content->extended_type)->toBe(Product::CONTENT_ALIAS)
        ->and($product->title)->toBe('Moon lamp')
        ->and($product->slug)->toBe('moon-lamp')
        ->and(ContentTranslation::query()->where('content_id', $content->getKey())->sole()->components)
        ->toBe(['content' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'A lamp']]]]])
        ->and($product->variants)->toHaveCount(1);

    /** @var ProductVariant $variant */
    $variant = $product->variants->sole();

    expect($variant->is_default)->toBeTrue()
        ->and($variant->attributes)->toBe(['colour' => 'white'])
        ->and($variant->items)->toHaveCount(1)
        ->and($variant->items->sole()->item_id)->toBe($item->getKey())
        ->and($variant->items->sole()->role)->toBe(VariantItem::ROLE_MAIN)
        ->and((float) $variant->items->sole()->quantity)->toBe(2.0);
});

it('creates a digital product whose variant has no composition', function (): void {
    $company = Company::factory()->create();
    $item = Item::factory()->create(['company_id' => $company->getKey()]);
    $state = product_wizard_state($company, $item, [
        'kind' => ProductKind::Digital->value,
        'variants' => [['is_default' => true]],
    ]);

    Livewire::test(CreateProduct::class)
        ->fillForm(Arr::only($state, ['company_id', 'kind', 'title', 'slug', 'components', 'valid_from', 'variants']))
        ->goToWizardStep(4)
        ->assertHasNoFormErrors()
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::query()->with('variants.items')->sole();

    expect($product->kind)->toBe(ProductKind::Digital)
        ->and($product->variants)->toHaveCount(1)
        ->and($product->variants->sole()->is_default)->toBeTrue()
        ->and($product->variants->sole()->items)->toHaveCount(0);
});

it('holds the wizard on the content step until the content has a title', function (): void {
    $company = Company::factory()->create();
    $item = Item::factory()->create(['company_id' => $company->getKey()]);

    Livewire::test(CreateProduct::class)
        ->fillForm(Arr::only(product_wizard_state($company, $item, ['title' => '']), ['title', 'slug', 'components']))
        ->goToWizardStep(3)
        ->assertHasFormErrors(['title' => 'required'])
        ->assertWizardCurrentStep(2);

    expect(Product::query()->count())->toBe(0);
});

it('refuses variants that do not have exactly one default', function (array $variants): void {
    Livewire::test(CreateProduct::class)
        ->fillForm(['variants' => $variants])
        ->goToWizardStep(4)
        ->assertHasFormErrors(['variants'])
        ->assertWizardCurrentStep(3);
})->with([
    'no default' => [[['is_default' => false]]],
    'two defaults' => [[['is_default' => true], ['is_default' => true]]],
]);

it('does not create a product whose content components are not an object', function (): void {
    $company = Company::factory()->create();
    $item = Item::factory()->create(['company_id' => $company->getKey()]);

    Livewire::test(CreateProduct::class)
        ->fillForm(product_wizard_state($company, $item, ['components' => '5']))
        ->call('create')
        ->assertHasErrors(['data.components']);

    expect(Product::query()->count())->toBe(0)
        ->and(Content::withExtended()->count())->toBe(0);
});

it('offers only the items of the company of the product', function (): void {
    $company = Company::factory()->create();
    $other_company = Company::factory()->create();
    Item::factory()->create(['company_id' => $company->getKey(), 'name' => 'Walnut shade']);
    Item::factory()->create(['company_id' => $other_company->getKey(), 'name' => 'Oak base']);

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'company_id' => $company->getKey(),
            'variants' => [['is_default' => true, 'items' => [['item_id' => null, 'quantity' => '1', 'role' => VariantItem::ROLE_MAIN]]]],
        ])
        ->assertSee('Walnut shade')
        ->assertDontSee('Oak base');
});

/**
 * The wizard opened on the content every product needs, with the variants as the form builds them: keyed by
 * the uuids the repeaters generate, as a browser sends them (`fillForm` would replace them with list indexes).
 */
function product_wizard_opened_on_default_variants(Company $company): Testable
{
    return Livewire::test(CreateProduct::class)->fillForm([
        'company_id' => $company->getKey(),
        'title' => 'Moon lamp',
        'slug' => 'moon-lamp',
        'components' => '{}',
    ]);
}

it('creates only the default variant the form shows when it is left untouched', function (): void {
    product_wizard_opened_on_default_variants(Company::factory()->create())
        ->call('create')
        ->assertHasNoFormErrors();

    $variants = Product::query()->sole()->variants;

    expect($variants)->toHaveCount(1)
        ->and($variants->sole()->is_default)->toBeTrue();
});

it('creates one variant per repeater item after variants are added and deleted', function (): void {
    $wizard = product_wizard_opened_on_default_variants(Company::factory()->create());
    $first = array_key_first($wizard->get('data.variants'));

    $wizard->callAction(TestAction::make('add')->schemaComponent('variants', schema: 'form'));

    $second = array_keys($wizard->get('data.variants'))[1];

    $wizard->set("data.variants.{$second}.attributes", ['colour' => 'black'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Product::query()->sole()->variants()->count())->toBe(2);

    // The same walk, this time giving the default to the added variant and deleting the first one.
    $wizard = product_wizard_opened_on_default_variants(Company::factory()->create());
    $first = array_key_first($wizard->get('data.variants'));
    $wizard->set('data.slug', 'moon-lamp-black')
        ->callAction(TestAction::make('add')->schemaComponent('variants', schema: 'form'));
    $second = array_keys($wizard->get('data.variants'))[1];

    $wizard->set("data.variants.{$second}.is_default", true)
        ->set("data.variants.{$second}.attributes", ['colour' => 'black'])
        ->set("data.variants.{$first}.is_default", false)
        ->callAction(TestAction::make('delete')->schemaComponent('variants', schema: 'form')->arguments(['item' => $first]))
        ->call('create')
        ->assertHasNoFormErrors();

    $variant = Product::query()->latest('id')->first()->variants->sole();

    expect($variant->is_default)->toBeTrue()
        ->and($variant->attributes)->toBe(['colour' => 'black']);
});

it('composes a variant through the item added in the composition step', function (): void {
    $company = Company::factory()->create();
    $item = Item::factory()->create(['company_id' => $company->getKey()]);
    $wizard = product_wizard_opened_on_default_variants($company);
    $variant_key = array_key_first($wizard->get('data.variants'));

    $wizard->callAction(TestAction::make('add')->schemaComponent("composition.{$variant_key}.items", schema: 'form'));

    $item_key = array_key_first($wizard->get("data.variants.{$variant_key}.items"));

    $wizard->set("data.variants.{$variant_key}.items.{$item_key}.item_id", $item->getKey())
        ->set("data.variants.{$variant_key}.items.{$item_key}.quantity", '3')
        ->call('create')
        ->assertHasNoFormErrors();

    $variants = Product::query()->sole()->variants()->with('items')->get();

    expect($variants)->toHaveCount(1)
        ->and($variants->sole()->items)->toHaveCount(1)
        ->and($variants->sole()->items->sole()->item_id)->toBe($item->getKey())
        ->and((float) $variants->sole()->items->sole()->quantity)->toBe(3.0);
});

it('refuses to create a product whose variants are not exactly one default', function (array $variants): void {
    $company = Company::factory()->create();
    $item = Item::factory()->create(['company_id' => $company->getKey()]);

    Livewire::test(CreateProduct::class)
        ->fillForm(product_wizard_state($company, $item, ['variants' => $variants]))
        ->call('create')
        ->assertHasErrors(['data.variants']);

    expect(Product::query()->count())->toBe(0)
        ->and(Content::withExtended()->count())->toBe(0);
})->with([
    'no variant' => [[]],
    'no default' => [[['is_default' => false]]],
    'two defaults' => [[['is_default' => true], ['is_default' => true]]],
]);

it('lets two variants share an item but not one variant list it twice', function (): void {
    $company = Company::factory()->create();
    $item = Item::factory()->create(['company_id' => $company->getKey()]);
    $row = ['item_id' => $item->getKey(), 'quantity' => '1', 'role' => VariantItem::ROLE_MAIN];

    Livewire::test(CreateProduct::class)
        ->fillForm(product_wizard_state($company, $item, ['variants' => [
            ['is_default' => true, 'items' => [$row]],
            ['is_default' => false, 'items' => [$row]],
        ]]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(VariantItem::query()->count())->toBe(2);

    Livewire::test(CreateProduct::class)
        ->fillForm(product_wizard_state($company, $item, ['slug' => 'moon-lamp-twice', 'variants' => [
            ['is_default' => true, 'items' => [$row, [...$row, 'role' => VariantItem::ROLE_COMPONENT]]],
        ]]))
        ->call('create')
        ->assertHasFormErrors(['variants.0.items.1.item_id']);

    expect(Product::query()->count())->toBe(1);
});

it('keeps free-form keys that are numbers', function (): void {
    $company = Company::factory()->create();
    $item = Item::factory()->create(['company_id' => $company->getKey()]);

    Livewire::test(CreateProduct::class)
        ->fillForm(product_wizard_state($company, $item, [
            'metadata' => ['2024' => 'launch'],
            'variants' => [['is_default' => true, 'attributes' => ['10' => 'ten']]],
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::query()->with('variants')->sole();

    expect($product->metadata)->toBe([2024 => 'launch'])
        ->and($product->variants->sole()->attributes)->toBe([10 => 'ten']);
});
