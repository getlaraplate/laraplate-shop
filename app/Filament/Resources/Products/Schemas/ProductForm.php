<?php

declare(strict_types=1);

namespace Modules\Shop\Filament\Resources\Products\Schemas;

use function Modules\ERP\Helpers\current_company_id;

use Closure;
use DateTimeInterface;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Modules\CMS\Filament\Resources\Contents\Schemas\ContentForm;
use Modules\ERP\Models\Company;
use Modules\ERP\Models\Item;
use Modules\Shop\Actions\Products\CreateProductAction;
use Modules\Shop\Actions\Products\UpdateProductAction;
use Modules\Shop\Enums\ProductKind;
use Modules\Shop\Models\Product;
use Modules\Shop\Models\VariantItem;

/**
 * What the product forms share, whichever way a product is reached: the four concerns of a product (its own
 * columns, its CMS content, its variants, the ERP items that compose each variant) as component lists, the
 * edit form that lays them out as tabs, the loader that fills a form from a stored product and the reshaping
 * of the form state into the payload of the write actions. {@see ProductWizard} lays the same four lists out
 * as the steps that create a product.
 *
 * The content components are the fields of the CMS content form, reused as they are.
 *
 * The variants live in one piece of form state, `variants`, that two repeaters edit: the first shows the
 * variant itself (its identity, default flag, attributes), the second shows its composition. Sharing the state
 * path keeps the composition keyed on the variants by construction, so there is nothing to synchronise
 * between the two and the payloads read the list as it is. The identity is a hidden `id`: it is what lets
 * {@see UpdateProductAction} update a variant in place instead of removing it and creating another.
 *
 * @phpstan-import-type VariantEntry from \Modules\Shop\Actions\Products\SyncProductVariantsAction
 */
final class ProductForm
{
    /**
     * The most options a select offers before the user has to search.
     */
    private const int OPTION_LIMIT = 50;

    /**
     * The edit form: the four concerns of a product as tabs, each one reachable directly.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('product')
                ->tabs([
                    Tab::make(__('shop.tabs.product'))
                        ->schema(self::productComponents())
                        ->columns(2),
                    Tab::make(__('shop.tabs.content'))
                        ->schema(self::contentComponents())
                        ->columns(2),
                    Tab::make(__('shop.tabs.variants'))
                        ->schema(self::variantsComponents()),
                    Tab::make(__('shop.tabs.composition'))
                        ->schema(self::compositionComponents()),
                ])
                ->columnSpanFull(),
        ]);
    }

    /**
     * @return list<Component>
     */
    public static function productComponents(): array
    {
        return [
            Select::make('company_id')
                ->options(static fn (): array => Company::query()->orderBy('name')->get()->pluck('name', 'id')->all())
                ->default(static fn (): ?int => current_company_id())
                ->searchable()
                // The tenant of a product never changes: the update ignores it, so the form does not offer it.
                ->disabledOn('edit')
                ->required(),
            Select::make('kind')
                ->options(static fn (): array => collect(ProductKind::cases())
                    ->mapWithKeys(static fn (ProductKind $kind): array => [$kind->value => __("shop.kinds.{$kind->value}")])
                    ->all())
                ->default(ProductKind::Physical->value)
                ->required(),
            Toggle::make('is_published_in_shop')
                ->default(false),
            Toggle::make('featured')
                ->default(false),
            DatePicker::make('release_date'),
            KeyValue::make('metadata')
                ->columnSpanFull(),
        ];
    }

    /**
     * The fields of the CMS content form. They are read off a schema of their own, so none of the
     * entity and preset selects CMS adds for a content applies: a product's content is always created on
     * the Shop product entity and its default preset.
     *
     * @return list<Component>
     */
    public static function contentComponents(): array
    {
        $components = array_values(array_filter(
            ContentForm::configure(Schema::make())->getComponents(withActions: false, withHidden: true),
            static fn (mixed $component): bool => $component instanceof Component,
        ));

        foreach ($components as $component) {
            // The action publishes a product's content from now unless told otherwise, so the form shows it.
            if ($component instanceof DateTimePicker && $component->getName() === 'valid_from') {
                $component->default(static fn (): string => now()->toDateTimeString());
            }

            // The components are required: an empty object leaves every one of them to the preset's default.
            if ($component instanceof TextInput && $component->getName() === 'components') {
                $component->default('{}');
            }
        }

        return $components;
    }

    /**
     * @return list<Component>
     */
    public static function variantsComponents(): array
    {
        return [
            Repeater::make('variants')
                ->schema([
                    // Empty for a variant added in the form, which is how the action tells it from a stored one.
                    Hidden::make('id'),
                    Toggle::make('is_default')
                        ->default(false),
                    KeyValue::make('attributes'),
                ])
                ->default([['is_default' => true]])
                ->minItems(1)
                // The composition repeater below edits the same state path and re-indexes it (uuid keys to a list)
                // when it dehydrates. If this one re-indexed too, the composition repeater's nested `items` paths
                // would no longer exist and `data_set` would create a phantom variant per item path. So only the
                // LAST repeater on the path may re-index: the composition repeater MUST stay after this one.
                ->mutateDehydratedStateUsing(static fn (?array $state): ?array => $state)
                ->itemLabel(static fn (array $state): string => self::variantLabel($state))
                ->rules([
                    static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                        $defaults = collect(Arr::wrap($value))
                            ->filter(static fn (mixed $variant): bool => is_array($variant) && ($variant['is_default'] ?? false) === true)
                            ->count();

                        if ($defaults !== 1) {
                            $fail(__('shop.validation.oneDefaultVariant'));
                        }
                    },
                ])
                ->columnSpanFull(),
        ];
    }

    /**
     * @return list<Component>
     */
    public static function compositionComponents(): array
    {
        return [
            Repeater::make('variants')
                // The same state as the variants repeater, a different component: it needs a key of its own.
                ->key('composition')
                ->schema([
                    Repeater::make('items')
                        ->schema([
                            // `../../../../company_id`: item, items repeater, variant, variants repeater, then the form.
                            Select::make('item_id')
                                ->options(static fn (Get $get): array => self::itemOptions($get('../../../../company_id')))
                                ->getSearchResultsUsing(static fn (string $search, Get $get): array => self::itemOptions($get('../../../../company_id'), $search))
                                ->getOptionLabelUsing(static fn (mixed $value): ?string => self::itemLabel($value))
                                ->searchable()
                                ->preload()
                                // Among the rows of one variant: an item composes a variant at most once.
                                ->distinct()
                                ->required(),
                            TextInput::make('quantity')
                                ->numeric()
                                ->default(1)
                                ->minValue(0.0001)
                                ->required(),
                            Select::make('role')
                                ->options(static fn (): array => [
                                    VariantItem::ROLE_MAIN => __('shop.roles.' . VariantItem::ROLE_MAIN),
                                    VariantItem::ROLE_COMPONENT => __('shop.roles.' . VariantItem::ROLE_COMPONENT),
                                ])
                                ->default(VariantItem::ROLE_MAIN)
                                ->required(),
                        ])
                        ->columns(3)
                        ->defaultItems(0)
                        ->columnSpanFull(),
                ])
                ->defaultItems(0)
                ->addable(false)
                ->deletable(false)
                ->reorderable(false)
                ->itemLabel(static fn (array $state): string => self::variantLabel($state))
                ->columnSpanFull(),
        ];
    }

    /**
     * The form state of a stored product: its own columns, the content fields merged onto it and its live
     * variants, each with its identity and its composition. The inverse of {@see self::updatePayload()}.
     *
     * @return array<string, mixed>
     */
    public static function stateOf(Product $product): array
    {
        $components = $product->components;
        $variants = [];

        foreach ($product->variants()->with('items')->orderBy('id')->get() as $variant) {
            $variants[] = [
                'id' => $variant->getKey(),
                'is_default' => $variant->is_default,
                'attributes' => $variant->attributes ?? [],
                'items' => $variant->items->map(static fn (VariantItem $item): array => [
                    'item_id' => $item->item_id,
                    'quantity' => self::quantityOf($item->quantity),
                    'role' => $item->role,
                ])->all(),
            ];
        }

        return [
            'company_id' => $product->company_id,
            'kind' => $product->kind?->value,
            'is_published_in_shop' => $product->is_published_in_shop,
            'featured' => $product->featured,
            'release_date' => self::formatDate($product->release_date, 'Y-m-d'),
            'metadata' => $product->metadata ?? [],
            'title' => $product->title,
            'slug' => $product->slug,
            // The components are edited as JSON text; an empty set is an empty object, not an empty list.
            'components' => is_array($components) && $components !== [] ? json_encode($components, JSON_THROW_ON_ERROR) : '{}',
            'order_column' => $product->order_column,
            'valid_from' => self::formatDate($product->valid_from, 'Y-m-d H:i:s'),
            'valid_to' => self::formatDate($product->valid_to, 'Y-m-d H:i:s'),
            'variants' => $variants,
        ];
    }

    /**
     * Reshapes the create state into the payload of {@see CreateProductAction}: the product columns, the
     * content fields under `content` and the variants with their composition rows under `variants`. A value
     * of an unexpected type is left out rather than coerced, so the models reject it as a missing one.
     *
     * @param  array<string, mixed>  $state
     *
     * @throws ValidationException
     *
     * @return array{company_id?: int|string|null, kind?: string, is_published_in_shop?: bool, featured?: bool, release_date?: string|null, metadata?: array<string, mixed>|null, content: array<string, mixed>, variants: list<VariantEntry>}
     */
    public static function createPayload(array $state): array
    {
        $payload = [
            'content' => self::contentPayload($state),
            'variants' => self::variantsPayload($state),
        ];

        $company_id = $state['company_id'] ?? null;
        $kind = $state['kind'] ?? null;
        $is_published_in_shop = $state['is_published_in_shop'] ?? null;
        $featured = $state['featured'] ?? null;
        $release_date = $state['release_date'] ?? null;
        $metadata = $state['metadata'] ?? null;

        if (is_int($company_id) || is_string($company_id)) {
            $payload['company_id'] = $company_id;
        }

        if (is_string($kind)) {
            $payload['kind'] = $kind;
        }

        if (is_bool($is_published_in_shop)) {
            $payload['is_published_in_shop'] = $is_published_in_shop;
        }

        if (is_bool($featured)) {
            $payload['featured'] = $featured;
        }

        if (is_string($release_date) && $release_date !== '') {
            $payload['release_date'] = $release_date;
        }

        if (is_array($metadata) && $metadata !== []) {
            $payload['metadata'] = self::stringKeyed($metadata);
        }

        return $payload;
    }

    /**
     * Reshapes the edit state into the payload of {@see UpdateProductAction}. Unlike a create, a date or a map
     * the form empties is an edit to carry (as `null`), and the variants keep their identity so the action
     * updates them in place. The tenant is not part of it: it never changes.
     *
     * @param  array<string, mixed>  $state
     *
     * @throws ValidationException
     *
     * @return array{kind?: string, is_published_in_shop?: bool, featured?: bool, release_date?: string|null, metadata?: array<string, mixed>|null, content: array<string, mixed>, variants: list<VariantEntry>}
     */
    public static function updatePayload(array $state): array
    {
        $payload = [
            'content' => self::contentPayload($state),
            'variants' => self::variantsPayload($state),
        ];

        $kind = $state['kind'] ?? null;
        $is_published_in_shop = $state['is_published_in_shop'] ?? null;
        $featured = $state['featured'] ?? null;
        $metadata = $state['metadata'] ?? null;

        if (is_string($kind)) {
            $payload['kind'] = $kind;
        }

        if (is_bool($is_published_in_shop)) {
            $payload['is_published_in_shop'] = $is_published_in_shop;
        }

        if (is_bool($featured)) {
            $payload['featured'] = $featured;
        }

        if (array_key_exists('release_date', $state)) {
            $payload['release_date'] = is_string($state['release_date']) && $state['release_date'] !== '' ? $state['release_date'] : null;
        }

        if (array_key_exists('metadata', $state)) {
            $payload['metadata'] = is_array($metadata) && $metadata !== [] ? self::stringKeyed($metadata) : null;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private static function variantLabel(array $state): string
    {
        $attributes = collect(Arr::wrap($state['attributes'] ?? []))
            ->map(static fn (mixed $value, string|int $key): string => "{$key}: " . (is_scalar($value) ? (string) $value : ''))
            ->implode(', ');

        $label = $attributes === '' ? __('shop.wizard.variant') : $attributes;

        return ($state['is_default'] ?? false) === true ? $label . ' (' . __('shop.wizard.default') . ')' : $label;
    }

    /**
     * @param  array<string, mixed>  $state
     *
     * @throws ValidationException
     *
     * @return array<string, mixed>
     */
    private static function contentPayload(array $state): array
    {
        $content = Arr::only($state, CreateProductAction::CONTENT_FIELDS);

        // The components are edited as JSON text; the content stores the decoded structure.
        if (is_string($content['components'] ?? null)) {
            $components = json_decode($content['components'], true);

            if (! is_array($components)) {
                throw ValidationException::withMessages([
                    'data.components' => [__('shop.validation.componentsObject')],
                ]);
            }

            $content['components'] = $components;
        }

        return $content;
    }

    /**
     * The variants of the state, which must hold exactly one default. A real variant always carries an
     * `is_default` key (its toggle emits one); an entry without it is a leftover of the shared state path
     * and is not a variant.
     *
     * @param  array<string, mixed>  $state
     *
     * @throws ValidationException
     *
     * @return list<VariantEntry>
     */
    private static function variantsPayload(array $state): array
    {
        $variants = [];

        foreach (Arr::wrap($state['variants'] ?? []) as $variant) {
            if (is_array($variant) && array_key_exists('is_default', $variant)) {
                $variants[] = self::variantPayload($variant);
            }
        }

        // The composition repeater shares the variants path, so its rules replace those of the variants step
        // at submit: the invariant is checked here too, where nothing can skip it.
        $defaults = count(array_filter($variants, static fn (array $variant): bool => ($variant['is_default'] ?? false) === true));

        if ($defaults !== 1) {
            throw ValidationException::withMessages([
                'data.variants' => [__('shop.validation.oneDefaultVariant')],
            ]);
        }

        return $variants;
    }

    /**
     * @param  array<mixed>  $variant
     * @return VariantEntry
     */
    private static function variantPayload(array $variant): array
    {
        $id = $variant['id'] ?? null;
        $attributes = $variant['attributes'] ?? null;
        $items = [];

        foreach (Arr::wrap($variant['items'] ?? []) as $item) {
            if (is_array($item)) {
                $items[] = self::itemPayload($item);
            }
        }

        $entry = [
            'is_default' => ($variant['is_default'] ?? false) === true,
            'attributes' => is_array($attributes) && $attributes !== [] ? self::stringKeyed($attributes) : null,
            'items' => $items,
        ];

        // A variant added in the form has no identity yet: without an id the action creates it.
        if ((is_int($id) || is_string($id)) && $id !== '') {
            $entry['id'] = $id;
        }

        return $entry;
    }

    /**
     * A key-value map keyed by strings. PHP stores a numeric key such as `"2024"` as an integer, so the keys
     * are rebuilt from their string form rather than dropped.
     *
     * @param  array<mixed>  $map
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $map): array
    {
        $keyed = [];

        foreach ($map as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
    }

    /**
     * @param  array<mixed>  $item
     * @return array{item_id?: int|string|null, quantity?: int|float|string, role?: string}
     */
    private static function itemPayload(array $item): array
    {
        $entry = [];
        $item_id = $item['item_id'] ?? null;
        $quantity = $item['quantity'] ?? null;
        $role = $item['role'] ?? null;

        if (is_int($item_id) || is_string($item_id)) {
            $entry['item_id'] = $item_id;
        }

        if (is_int($quantity) || is_float($quantity) || is_string($quantity)) {
            $entry['quantity'] = $quantity;
        }

        if (is_string($role)) {
            $entry['role'] = $role;
        }

        return $entry;
    }

    /**
     * The quantity of a composition row as the form shows it: the column keeps its fixed decimals (`2.0000`),
     * which the user would otherwise read back as a number they never typed.
     */
    private static function quantityOf(mixed $quantity): string
    {
        $text = is_scalar($quantity) ? (string) $quantity : '';

        return str_contains($text, '.') ? mb_rtrim(mb_rtrim($text, '0'), '.') : $text;
    }

    private static function formatDate(mixed $value, string $format): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format($format) : null;
    }

    /**
     * The items a variant can be composed of: those of the product's company, so none until it is chosen.
     *
     * @return array<int, string>
     */
    private static function itemOptions(mixed $company_id, ?string $search = null): array
    {
        if (! is_numeric($company_id)) {
            return [];
        }

        return Item::query()
            ->whereIn('company_id', [(int) $company_id])
            ->when(filled($search), static fn (Builder $query): Builder => $query->where(
                static fn (Builder $query): Builder => $query
                    ->whereLike('name', "%{$search}%")
                    ->orWhereLike('sku', "%{$search}%"),
            ))
            ->orderBy('name')
            ->limit(self::OPTION_LIMIT)
            ->get()
            ->mapWithKeys(static fn (Item $item): array => [(int) $item->id => "{$item->name} ({$item->sku})"])
            ->all();
    }

    private static function itemLabel(mixed $item_id): ?string
    {
        $item = is_numeric($item_id) ? Item::query()->find((int) $item_id) : null;

        return $item instanceof Item ? "{$item->name} ({$item->sku})" : null;
    }
}
