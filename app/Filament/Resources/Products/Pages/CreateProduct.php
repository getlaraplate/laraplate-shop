<?php

declare(strict_types=1);

namespace Modules\Shop\Filament\Resources\Products\Pages;

use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Filament\Utils\HasCloseOrCancelFormAction;
use Modules\Shop\Actions\Products\CreateProductAction;
use Modules\Shop\Filament\Resources\Products\ProductResource;
use Modules\Shop\Filament\Resources\Products\Schemas\ProductWizard;
use Override;

/**
 * Creates a product through the {@see ProductWizard}. The submit hands the wizard state, reshaped into the
 * action payload, to {@see CreateProductAction}: the page holds no write logic of its own.
 */
final class CreateProduct extends CreateRecord
{
    use HasCloseOrCancelFormAction;
    use HasWizard;

    #[Override]
    protected static string $resource = ProductResource::class;

    /**
     * @return list<Step>
     */
    public function getSteps(): array
    {
        return ProductWizard::steps();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[Override]
    protected function handleRecordCreation(array $data): Model
    {
        return resolve(CreateProductAction::class)->handle(ProductWizard::payload($data));
    }
}
