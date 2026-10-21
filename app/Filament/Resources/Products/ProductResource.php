<?php

declare(strict_types=1);

namespace Modules\Shop\Filament\Resources\Products;

use BackedEnum;
use Coolsam\Modules\Resource;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Modules\Core\Filament\Utils\HasTranslatedResourceLabels;
use Modules\Shop\Filament\Resources\Products\Pages\CreateProduct;
use Modules\Shop\Filament\Resources\Products\Pages\EditProduct;
use Modules\Shop\Filament\Resources\Products\Pages\ListProducts;
use Modules\Shop\Models\Product;
use Override;
use UnitEnum;

/**
 * The backoffice surface of the product catalog. A product is created through a wizard whose submit runs
 * the product write action, so the panel and any other caller share one write path.
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

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
