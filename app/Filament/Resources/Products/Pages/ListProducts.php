<?php

declare(strict_types=1);

namespace Modules\Shop\Filament\Resources\Products\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Shop\Filament\Resources\Products\ProductResource;
use Override;

final class ListProducts extends ListRecords
{
    #[Override]
    protected static string $resource = ProductResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
