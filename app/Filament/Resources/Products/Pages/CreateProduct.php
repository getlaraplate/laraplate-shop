<?php

declare(strict_types=1);

namespace Modules\Shop\Filament\Resources\Products\Pages;

use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Modules\Core\Filament\Utils\HasCloseOrCancelFormAction;
use Modules\Shop\Actions\Products\CreateProductAction;
use Modules\Shop\Filament\Resources\Products\Concerns\HandlesProductWriteErrors;
use Modules\Shop\Filament\Resources\Products\ProductResource;
use Modules\Shop\Filament\Resources\Products\Schemas\ProductForm;
use Modules\Shop\Filament\Resources\Products\Schemas\ProductWizard;
use Override;

/**
 * Creates a product through the {@see ProductWizard}. The submit hands the wizard state, reshaped into the
 * action payload, to {@see CreateProductAction}: the page holds no write logic of its own.
 */
final class CreateProduct extends CreateRecord
{
    use HandlesProductWriteErrors;
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
     *
     * @throws ValidationException
     */
    #[Override]
    protected function handleRecordCreation(array $data): Model
    {
        return $this->writeThroughAction(
            fn (): Model => resolve(CreateProductAction::class)->handle(ProductForm::createPayload($data)),
        );
    }
}
