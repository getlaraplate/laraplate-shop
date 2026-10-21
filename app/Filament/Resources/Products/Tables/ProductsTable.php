<?php

declare(strict_types=1);

namespace Modules\Shop\Filament\Resources\Products\Tables;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Modules\Core\Filament\Utils\HasTable;
use Modules\Shop\Enums\ProductKind;
use Modules\Shop\Filament\Resources\Products\ProductResource;
use Modules\Shop\Models\Product;

/**
 * The products of the catalog. The table queries {@see Product}, the extender, so the rows are the products
 * themselves and the company scope and the soft deletes apply to them; the title is the one of the content
 * merged onto each product.
 */
final class ProductsTable
{
    use HasTable;

    public static function configure(Table $table): Table
    {
        return self::configureTable(
            table: $table,
            columns: static function (Collection $default_columns): void {
                $default_columns->unshift(...[
                    TextColumn::make('company.name')
                        ->toggleable(),
                    TextColumn::make('title'),
                    TextColumn::make('kind')
                        ->badge()
                        ->formatStateUsing(static fn (ProductKind $state): string => __("shop.kinds.{$state->value}")),
                    IconColumn::make('is_published_in_shop')
                        ->boolean(),
                    IconColumn::make('featured')
                        ->boolean(),
                ]);
            },
            actions: static function (Collection $default_actions): void {
                // Core's view and edit actions open a modal over the resource form: the edit one fills it from the
                // bare columns and saves them with `$record->update()`, outside the write action and its transaction.
                // They are taken out. A product is opened by a link to its edit page, which loads and saves the whole
                // aggregate, and the link has no action to run, so there is nothing for a forged call to reach.
                foreach ($default_actions as $key => $action) {
                    if ($action instanceof ViewAction || $action instanceof EditAction) {
                        $default_actions->forget($key);
                    }
                }

                $default_actions->prepend(
                    Action::make('edit')
                        ->hiddenLabel()
                        ->icon(Heroicon::PencilSquare)
                        ->url(static fn (Product $record): string => ProductResource::getUrl('edit', ['record' => $record]))
                        ->visible(static fn (Product $record): bool => ProductResource::canEdit($record)),
                );
            },
        )
            ->defaultSort('id', 'desc');
    }
}
