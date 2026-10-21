<?php

declare(strict_types=1);

namespace Modules\Shop\Filament\Resources\Products\Pages;

use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Modules\Core\Filament\Utils\HasCloseOrCancelFormAction;
use Modules\Shop\Actions\Products\UpdateProductAction;
use Modules\Shop\Filament\Resources\Products\Concerns\HandlesProductWriteErrors;
use Modules\Shop\Filament\Resources\Products\ProductResource;
use Modules\Shop\Filament\Resources\Products\Schemas\ProductForm;
use Modules\Shop\Models\Product;
use Override;

/**
 * Edits a product through the tabs of {@see ProductForm}. The form is filled from the aggregate (the product,
 * its content and its variants, not the bare columns) and the save hands its state, reshaped into the action
 * payload, to {@see UpdateProductAction}: the page holds no write logic of its own.
 *
 * Known gap: nothing guards the aggregate against a stale write. A product has no lock version, this page takes
 * no lease on its content and the variant sync writes the desired final state, so a variant that another admin
 * added after this form was opened is removed by this save. Acceptable for the superadmin backoffice of v1;
 * optimistic locking of the aggregate is a feature of its own.
 */
final class EditProduct extends EditRecord
{
    use HandlesProductWriteErrors;
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
     * A save that fails (a variant removed meanwhile, say) must never look like it did nothing: the refusal is
     * said and re-keyed onto the form through {@see HandlesProductWriteErrors}.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    #[Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Product $record */
        return $this->writeThroughAction(
            fn (): Model => resolve(UpdateProductAction::class)->handle($record, ProductForm::updatePayload($data)),
        );
    }
}
