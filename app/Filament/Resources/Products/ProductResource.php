<?php

declare(strict_types=1);

namespace Modules\Shop\Filament\Resources\Products;

use BackedEnum;
use Coolsam\Modules\Resource;
use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Filament\Utils\HasTranslatedResourceLabels;
use Modules\Shop\Filament\Resources\Products\Pages\CreateProduct;
use Modules\Shop\Filament\Resources\Products\Pages\EditProduct;
use Modules\Shop\Filament\Resources\Products\Pages\ListProducts;
use Modules\Shop\Filament\Resources\Products\Schemas\ProductForm;
use Modules\Shop\Filament\Resources\Products\Tables\ProductsTable;
use Modules\Shop\Models\Product;
use Override;
use UnitEnum;

/**
 * The backoffice surface of the product catalog. A product is created through a wizard and edited through
 * tabs, and both submit through the product write actions, so the panel and any other caller share one write
 * path. Access follows the products table permissions, through {@see \Modules\Shop\Policies\ProductPolicy}.
 */
final class ProductResource extends Resource
{
    use HasTranslatedResourceLabels;

    #[Override]
    protected static ?string $model = Product::class;

    #[Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    #[Override]
    protected static string|UnitEnum|null $navigationGroup = 'shop.navigation.main';

    #[Override]
    protected static ?int $navigationSort = 1;

    public static function getSlug(?Panel $panel = null): string
    {
        return 'shop/products';
    }

    /**
     * The module being enabled is not enough: the resource is reached by who may read products, which is also
     * what gates its create and edit pages.
     */
    #[Override]
    public static function canAccess(): bool
    {
        return parent::canAccess() && self::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['company', 'content.translation']));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
