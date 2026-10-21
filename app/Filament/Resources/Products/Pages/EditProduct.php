<?php

declare(strict_types=1);

namespace Modules\Shop\Filament\Resources\Products\Pages;

use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Filament\Utils\HasCloseOrCancelFormAction;
use Modules\Shop\Actions\Products\UpdateProductAction;
use Modules\Shop\Filament\Resources\Products\ProductResource;
use Modules\Shop\Filament\Resources\Products\Schemas\ProductForm;
use Modules\Shop\Models\Product;
use Override;

/**
 * Edits a product through the tabs of {@see ProductForm}. The form is filled from the aggregate (the product,
 * its content and its variants, not the bare columns) and the save hands its state, reshaped into the action
 * payload, to {@see UpdateProductAction}: the page holds no write logic of its own.
 */
final class EditProduct extends EditRecord
{
    use HasCloseOrCancelFormAction;

    #[Override]
    protected static string $resource = ProductResource::class;

    /**
     * The variants saved now carry identities the form did not know. It is filled again from the stored
     * product, so the next save updates those variants instead of creating them a second time.
     */
    protected function afterSave(): void
    {
        $this->fillForm();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    #[Override]
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Product $product */
        $product = $this->getRecord();

        return ProductForm::stateOf($product);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Product $record */
        return resolve(UpdateProductAction::class)->handle($record, ProductForm::updatePayload($data));
    }
}
