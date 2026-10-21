<?php

declare(strict_types=1);

namespace Modules\Shop\Filament\Resources\Products\Pages;

use Filament\Resources\Pages\EditRecord;
use Modules\Core\Filament\Utils\HasCloseOrCancelFormAction;
use Modules\Shop\Filament\Resources\Products\ProductResource;
use Override;

final class EditProduct extends EditRecord
{
    use HasCloseOrCancelFormAction;

    #[Override]
    protected static string $resource = ProductResource::class;
}
