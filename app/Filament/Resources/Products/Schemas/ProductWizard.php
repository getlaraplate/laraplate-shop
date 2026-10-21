<?php

declare(strict_types=1);

namespace Modules\Shop\Filament\Resources\Products\Schemas;

use function Modules\ERP\Helpers\current_company_id;

use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Modules\CMS\Filament\Resources\Contents\Schemas\ContentForm;
use Modules\ERP\Models\Company;
use Modules\ERP\Models\Item;
use Modules\Shop\Actions\Products\CreateProductAction;
use Modules\Shop\Enums\ProductKind;
use Modules\Shop\Models\VariantItem;

/**
 * The four steps that create a product: the product's own columns, its CMS content (the fields of the CMS
 * content form, reused as they are), its variants, and the ERP items that compose each variant.
 *
 * The variants live in one piece of form state, `variants`, that two repeaters edit: the first step shows the
 * variant itself (default flag, attributes), the second shows its composition. Sharing the state path keeps
 * the composition keyed on the variants by construction, so there is nothing to synchronise between steps and
 * {@see self::payload()} reads the list as it is.
 *
 * @phpstan-import-type VariantEntry from \Modules\Shop\Actions\Products\SyncProductVariantsAction
 */
final class ProductWizard
{
    /**
     * The most options a select offers before the user has to search.
     */
    private const int OPTION_LIMIT = 50;

    /**
     * @return list<Step>
     */
    public static function steps(): array
    {
        return [
            Step::make(__('shop.wizard.steps.product'))
                ->schema(self::productComponents())
                ->columns(2),
            Step::make(__('shop.wizard.steps.content'))
                ->schema(self::contentComponents())
                ->columns(2),
            Step::make(__('shop.wizard.steps.variants'))
                ->schema([self::variantsRepeater()]),
            Step::make(__('shop.wizard.steps.composition'))
                ->schema([self::compositionRepeater()]),
        ];
    }

    /**
     * Reshapes the wizard state into the payload of {@see CreateProductAction}: the product columns, the
     * content fields under `content` and the variants with their composition rows under `variants`. A value
     * of an unexpected type is left out rather than coerced, so the models reject it as a missing one.
     *
     * @param  array<string, mixed>  $state
     *
     * @throws ValidationException
     *
     * @return array{company_id?: int|string|null, kind?: string, is_published_in_shop?: bool, featured?: bool, release_date?: string|null, metadata?: array<string, mixed>|null, content: array<string, mixed>, variants: list<VariantEntry>}
     */
    public static function payload(array $state): array
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
            $payload['metadata'] = array_filter($metadata, is_string(...), ARRAY_FILTER_USE_KEY);
        }

        return $payload;
    }

    /**
     * @return list<Component>
     */
    private static function productComponents(): array
    {
        return [
            Select::make('company_id')
                ->options(static fn (): array => Company::query()->orderBy('name')->pluck('name', 'id')->all())
                ->default(static fn (): ?int => current_company_id())
                ->searchable()
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
    private static function contentComponents(): array
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

    private static function variantsRepeater(): Repeater
    {
        return Repeater::make('variants')
            ->schema([
                Toggle::make('is_default')
                    ->default(false),
                KeyValue::make('attributes'),
            ])
            ->default([['is_default' => true]])
            ->minItems(1)
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
            ->columnSpanFull();
    }

    private static function compositionRepeater(): Repeater
    {
        return Repeater::make('variants')
            // The same state as the variants step, a different component: it needs a key of its own.
            ->key('composition')
            ->schema([
                Repeater::make('items')
                    ->schema([
                        Select::make('item_id')
                            ->options(static fn (Get $get): array => self::itemOptions($get('../../../../company_id')))
                            ->getSearchResultsUsing(static fn (string $search, Get $get): array => self::itemOptions($get('../../../../company_id'), $search))
                            ->getOptionLabelUsing(static fn (mixed $value): ?string => self::itemLabel($value))
                            ->searchable()
                            ->preload()
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
            ->columnSpanFull();
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
     * @param  array<string, mixed>  $state
     * @return list<VariantEntry>
     */
    private static function variantsPayload(array $state): array
    {
        $variants = [];

        foreach (Arr::wrap($state['variants'] ?? []) as $variant) {
            if (is_array($variant)) {
                $variants[] = self::variantPayload($variant);
            }
        }

        return $variants;
    }

    /**
     * @param  array<mixed>  $variant
     * @return VariantEntry
     */
    private static function variantPayload(array $variant): array
    {
        $attributes = $variant['attributes'] ?? null;
        $items = [];

        foreach (Arr::wrap($variant['items'] ?? []) as $item) {
            if (is_array($item)) {
                $items[] = self::itemPayload($item);
            }
        }

        return [
            'is_default' => ($variant['is_default'] ?? false) === true,
            'attributes' => is_array($attributes) && $attributes !== [] ? array_filter($attributes, is_string(...), ARRAY_FILTER_USE_KEY) : null,
            'items' => $items,
        ];
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
            ->where('company_id', (int) $company_id)
            ->when(filled($search), static fn (Builder $query): Builder => $query->where(
                static fn (Builder $query): Builder => $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%"),
            ))
            ->orderBy('name')
            ->limit(self::OPTION_LIMIT)
            ->get()
            ->mapWithKeys(static fn (Item $item): array => [(int) $item->getKey() => "{$item->name} ({$item->sku})"])
            ->all();
    }

    private static function itemLabel(mixed $item_id): ?string
    {
        $item = is_numeric($item_id) ? Item::query()->find((int) $item_id) : null;

        return $item instanceof Item ? "{$item->name} ({$item->sku})" : null;
    }
}
