<?php

declare(strict_types=1);

use function Modules\ERP\Helpers\with_company;

use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
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

    // The policy of the resource asks the user of the guard of the panel, so the panel is the current one and the
    // user signs in on its guard.
    Filament::setCurrentPanel('admin');

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

    $this->actingAs($admin, Filament::getAuthGuard());
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

it('fills the tabbed form from the stored product, carrying the identity of each variant', function (): void {
    $company = Company::factory()->create();
    $item = Item::factory()->create(['company_id' => $company->getKey()]);
    $product = shop_stored_product($company, $item);
    $variant = $product->variants()->sole();

    $edit = Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->assertFormSet([
            'company_id' => $company->getKey(),
            'kind' => ProductKind::Physical->value,
            'is_published_in_shop' => true,
            'title' => 'Moon lamp',
            'slug' => 'moon-lamp',
        ])
        ->assertFormFieldDisabled('company_id');

    $form_variant = array_values($edit->get('data.variants'))[0];

    expect($form_variant['id'])->toBe($variant->getKey())
        ->and($form_variant['is_default'])->toBeTrue()
        ->and(array_values($form_variant['attributes']))->toBe([['key' => 'colour', 'value' => 'white']])
        ->and(array_values($form_variant['items']))->toHaveCount(1)
        ->and((int) array_values($form_variant['items'])[0]['item_id'])->toBe($item->getKey())
        ->and((float) array_values($form_variant['items'])[0]['quantity'])->toBe(2.0);
});

it('edits a product through the tabbed form', function (): void {
    $company = Company::factory()->create();
    $item = Item::factory()->create(['company_id' => $company->getKey()]);
    $product = shop_stored_product($company, $item);
    $variant = $product->variants()->sole();
    $valid_from = Product::query()->sole()->valid_from;

    $edit = Livewire::test(EditProduct::class, ['record' => $product->getKey()]);
    $variant_key = array_key_first($edit->get('data.variants'));
    $item_key = array_key_first($edit->get("data.variants.{$variant_key}.items"));

    $edit->fillForm([
        'kind' => ProductKind::Digital->value,
        'featured' => true,
        'release_date' => '2030-01-15',
        'title' => 'Sun lamp',
    ])
        ->set("data.variants.{$variant_key}.attributes", ['colour' => 'amber'])
        ->set("data.variants.{$variant_key}.items.{$item_key}.quantity", '5')
        ->call('save')
        ->assertHasNoFormErrors();

    $product = Product::query()->with('variants.items')->sole();

    expect($product->company_id)->toBe($company->getKey())
        ->and($product->kind)->toBe(ProductKind::Digital)
        ->and($product->featured)->toBeTrue()
        ->and($product->release_date?->toDateString())->toBe('2030-01-15')
        ->and($product->title)->toBe('Sun lamp')
        ->and($product->slug)->toBe('moon-lamp')
        ->and($product->valid_from)->toEqual($valid_from)
        ->and($product->variants)->toHaveCount(1)
        ->and($product->variants->sole()->getKey())->toBe($variant->getKey())
        ->and($product->variants->sole()->attributes)->toBe(['colour' => 'amber'])
        ->and($product->variants->sole()->items)->toHaveCount(1)
        ->and((float) $product->variants->sole()->items->sole()->quantity)->toBe(5.0);
});

it('clears a release date and the metadata that the form empties', function (): void {
    $company = Company::factory()->create();
    $item = Item::factory()->create(['company_id' => $company->getKey()]);
    $product = shop_stored_product($company, $item);
    $product->update(['release_date' => '2030-01-15', 'metadata' => ['origin' => 'IT']]);

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->assertFormSet(['release_date' => '2030-01-15', 'metadata' => ['origin' => 'IT']])
        ->fillForm(['release_date' => null, 'metadata' => []])
        ->call('save')
        ->assertHasNoFormErrors();

    $product = Product::query()->sole();

    expect($product->release_date)->toBeNull()
        ->and($product->metadata)->toBeNull();
});

it('keeps the variants identity across saves, whether they are old or added in the form', function (): void {
    $company = Company::factory()->create();
    $item = Item::factory()->create(['company_id' => $company->getKey()]);
    $product = shop_stored_product($company, $item);
    $first = $product->variants()->sole();

    $edit = Livewire::test(EditProduct::class, ['record' => $product->getKey()]);
    $edit->callAction(TestAction::make('add')->schemaComponent('variants', schema: 'form'));
    $added_key = array_keys($edit->get('data.variants'))[1];

    $edit->set("data.variants.{$added_key}.attributes", ['colour' => 'black'])
        ->call('save')
        ->assertHasNoFormErrors();

    $saved_ids = $product->variants()->orderBy('id')->pluck('id')->all();

    expect($saved_ids)->toHaveCount(2)
        ->and($saved_ids[0])->toBe($first->getKey());

    // A second save sends the added variant with the id the first save gave it: it is updated, not recreated.
    $edit->call('save')->assertHasNoFormErrors();

    expect($product->variants()->orderBy('id')->pluck('id')->all())->toBe($saved_ids);
});

it('removes the variant that the form drops', function (): void {
    $company = Company::factory()->create();
    $item = Item::factory()->create(['company_id' => $company->getKey()]);
    $product = shop_stored_product($company, $item, with_second_variant: true);
    $default = $product->variants()->where('is_default', true)->sole();
    $secondary = $product->variants()->where('is_default', false)->sole();

    $edit = Livewire::test(EditProduct::class, ['record' => $product->getKey()]);
    $secondary_key = collect($edit->get('data.variants'))->search(static fn (array $variant): bool => $variant['id'] === $secondary->getKey());

    $edit->callAction(TestAction::make('delete')->schemaComponent('variants', schema: 'form')->arguments(['item' => $secondary_key]))
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->variants()->pluck('id')->all())->toBe([$default->getKey()])
        ->and($product->variants()->withTrashed()->count())->toBe(2);
});

it('refuses to save a product whose variants are not exactly one default', function (): void {
    $company = Company::factory()->create();
    $item = Item::factory()->create(['company_id' => $company->getKey()]);
    $product = shop_stored_product($company, $item);

    $edit = Livewire::test(EditProduct::class, ['record' => $product->getKey()]);
    $variant_key = array_key_first($edit->get('data.variants'));

    $edit->set("data.variants.{$variant_key}.is_default", false)
        ->call('save')
        ->assertHasErrors(['data.variants']);

    expect($product->variants()->sole()->is_default)->toBeTrue();
});

it('lists the live products of the active company, titled from their content', function (): void {
    $company = Company::factory()->create();
    $other_company = Company::factory()->create();
    $item = Item::factory()->create(['company_id' => $company->getKey()]);
    $other_item = Item::factory()->create(['company_id' => $other_company->getKey()]);
    $live = shop_stored_product($company, $item, 'Moon lamp');
    $trashed = shop_stored_product($company, $item, 'Sun lamp');
    $foreign = shop_stored_product($other_company, $other_item, 'Star lamp');
    $trashed->delete();

    with_company($company->getKey(), function () use ($live, $trashed, $foreign): void {
        Livewire::test(ListProducts::class)
            ->loadTable()
            ->assertCanSeeTableRecords([$live])
            ->assertCanNotSeeTableRecords([$trashed, $foreign])
            // A product opens on its edit page: a view or an edit modal would fill the form from bare columns.
            ->assertTableActionDoesNotExist('view')
            ->assertTableActionHasUrl('edit', ProductResource::getUrl('edit', ['record' => $live]), $live)
            ->assertTableColumnStateSet('title', 'Moon lamp', $live)
            ->assertTableColumnFormattedStateSet('kind', 'Physical', $live)
            ->assertTableColumnStateSet('company.name', $live->company->name, $live);
    });
});

it('opens a product page only to the permissions that guard it', function (array $granted, string $page, bool $opens): void {
    $company = Company::factory()->create();
    $item = Item::factory()->create(['company_id' => $company->getKey()]);
    $product = shop_stored_product($company, $item);

    $this->actingAs(shop_product_user($granted), Filament::getAuthGuard());

    $open = match ($page) {
        'list' => static fn (): Testable => Livewire::test(ListProducts::class),
        'create' => static fn (): Testable => Livewire::test(CreateProduct::class),
        'edit' => static fn (): Testable => Livewire::test(EditProduct::class, ['record' => $product->getKey()]),
    };

    $opens ? $open()->assertSuccessful() : $open()->assertForbidden();
})->with([
    'list without permission' => [[], 'list', false],
    'list with select' => [['select'], 'list', true],
    'create with select alone' => [['select'], 'create', false],
    'create with select and insert' => [['select', 'insert'], 'create', true],
    'create with insert alone, reading gates the resource' => [['insert'], 'create', false],
    'edit with select alone' => [['select'], 'edit', false],
    'edit with select and update' => [['select', 'update'], 'edit', true],
    'edit with update alone, reading gates the resource' => [['update'], 'edit', false],
]);

it('maps each product ability to the permission that guards it', function (string $ability, string $operation): void {
    $company = Company::factory()->create();
    $item = Item::factory()->create(['company_id' => $company->getKey()]);
    $product = shop_stored_product($company, $item);
    $subject = in_array($ability, ['viewAny', 'create', 'deleteAny', 'restoreAny', 'forceDeleteAny'], true) ? Product::class : $product;

    $guarded = shop_product_user([$operation]);
    $other = shop_product_user(array_values(array_diff(['select', 'insert', 'update', 'delete', 'restore', 'forceDelete'], [$operation])));

    expect(Gate::forUser($guarded)->allows($ability, $subject))->toBeTrue()
        ->and(Gate::forUser($other)->allows($ability, $subject))->toBeFalse();
})->with([
    'viewAny' => ['viewAny', 'select'],
    'view' => ['view', 'select'],
    'create' => ['create', 'insert'],
    'update' => ['update', 'update'],
    'delete' => ['delete', 'delete'],
    'deleteAny' => ['deleteAny', 'delete'],
    'restore' => ['restore', 'restore'],
    'restoreAny' => ['restoreAny', 'restore'],
    'forceDelete' => ['forceDelete', 'forceDelete'],
    'forceDeleteAny' => ['forceDeleteAny', 'forceDelete'],
]);

it('offers the create action on the list only to who may insert', function (array $granted, bool $offered): void {
    $this->actingAs(shop_product_user($granted), Filament::getAuthGuard());

    $list = Livewire::test(ListProducts::class);

    $offered ? $list->assertActionVisible(CreateAction::class) : $list->assertActionHidden(CreateAction::class);
})->with([
    'select alone' => [['select'], false],
    'select and insert' => [['select', 'insert'], true],
]);

it('opens and saves a product whose composition holds a discontinued item', function (): void {
    $company = Company::factory()->create();
    $item = Item::factory()->create(['company_id' => $company->getKey()]);
    $product = shop_stored_product($company, $item);
    $item->delete();

    $edit = Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->assertSee('(discontinued)');
    $variant_key = array_key_first($edit->get('data.variants'));
    $item_key = array_key_first($edit->get("data.variants.{$variant_key}.items"));

    $edit->fillForm(['title' => 'Sun lamp'])
        ->set("data.variants.{$variant_key}.items.{$item_key}.quantity", '7')
        ->call('save')
        ->assertHasNoFormErrors();

    $row = VariantItem::query()->sole();

    expect(Product::query()->sole()->title)->toBe('Sun lamp')
        ->and($row->item_id)->toBe($item->getKey())
        ->and((float) $row->quantity)->toBe(7.0);
});

it('links the table edit to the page, with no action of its own to forge', function (): void {
    $company = Company::factory()->create();
    $item = Item::factory()->create(['company_id' => $company->getKey()]);
    $product = shop_stored_product($company, $item);

    $list = Livewire::test(ListProducts::class)->loadTable();

    $action = $list->instance()->getTable()->getAction('edit');

    // Core's edit action would run `$record->update($data)` on the bare columns, outside the write action.
    expect($action)->not->toBeInstanceOf(EditAction::class)
        ->and($action?->getActionFunction())->toBeNull();

    $list->callTableAction('edit', $product, ['kind' => ProductKind::Digital->value, 'featured' => true]);

    $stored = Product::query()->sole();

    expect($stored->kind)->toBe(ProductKind::Physical)
        ->and($stored->featured)->toBeFalse();
});

it('shows why a save is refused, instead of failing silently', function (): void {
    $company = Company::factory()->create();
    $item = Item::factory()->create(['company_id' => $company->getKey()]);
    $product = shop_stored_product($company, $item);

    $edit = Livewire::test(EditProduct::class, ['record' => $product->getKey()]);

    // Another admin removed the variant after this form was opened: the form still names it.
    $product->variants()->sole()->delete();

    $edit->fillForm(['title' => 'Sun lamp'])
        ->call('save')
        ->assertHasErrors(['data.variants.0.id'])
        ->assertNotified(__('shop.validation.saveRefused'));

    expect(Product::query()->sole()->title)->toBe('Moon lamp');
});

it('saves a variant edit only for a user who may also write the variant tables', function (bool $may_write_parts): void {
    $company = Company::factory()->create();
    $item = Item::factory()->create(['company_id' => $company->getKey()]);
    $product = shop_stored_product($company, $item);
    $part_permissions = shop_register_part_permissions();
    $user = shop_product_user(['select', 'update']);

    // Reading the parts is granted to both, so the form opens for both: only the write tells them apart.
    $user->givePermissionTo($part_permissions['select']);

    if ($may_write_parts) {
        $user->givePermissionTo($part_permissions['update']);
    }

    $this->actingAs($user, Filament::getAuthGuard());

    $edit = Livewire::test(EditProduct::class, ['record' => $product->getKey()]);
    $variant_key = array_key_first($edit->get('data.variants'));

    $edit->fillForm(['featured' => true])
        ->set("data.variants.{$variant_key}.attributes", ['colour' => 'amber']);

    if ($may_write_parts) {
        $edit->call('save')->assertHasNoFormErrors();
    } else {
        // The part is refused by the data layer, which rolls the whole save back, the product columns included.
        $edit->call('save')->assertForbidden();
    }

    $stored = Product::query()->with('variants')->sole();

    expect($stored->featured)->toBe($may_write_parts)
        ->and($stored->variants->sole()->attributes)->toBe($may_write_parts ? ['colour' => 'amber'] : ['colour' => 'white']);
})->with([
    'with the update permissions of the parts' => [true],
    'without them' => [false],
]);

it('lets the variants be reordered only while creating, the one time the order is stored', function (): void {
    Livewire::test(CreateProduct::class)
        ->assertFormComponentExists('variants', static fn (Repeater $variants): bool => $variants->isReorderable());

    $product = shop_stored_product(Company::factory()->create(), Item::factory()->create());

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->assertFormComponentExists('variants', static fn (Repeater $variants): bool => ! $variants->isReorderable());
});
