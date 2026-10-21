<?php

declare(strict_types=1);

namespace Modules\Shop\Filament\Resources\Products\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
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
 *
 * Known gap: nothing guards the aggregate against a stale write. A product has no lock version, this page takes
 * no lease on its content and the variant sync writes the desired final state, so a variant that another admin
 * added after this form was opened is removed by this save. Acceptable for the superadmin backoffice of v1;
 * optimistic locking of the aggregate is a feature of its own.
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
     * The action refuses with the keys of its payload (`content.title`, `variants.0.id`), which name no field of
     * the form: they are put under `data.`, content fields as the form names them, and said in a notification, so
     * a save that fails (a variant removed meanwhile, say) never looks like it did nothing.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    #[Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Product $record */
        try {
            return resolve(UpdateProductAction::class)->handle($record, ProductForm::updatePayload($data));
        } catch (ValidationException $exception) {
            Notification::make()
                ->danger()
                ->title(__('shop.validation.saveRefused'))
                ->body(implode(' ', Arr::flatten($exception->errors())))
                ->send();

            $errors = [];

            foreach ($exception->errors() as $key => $messages) {
                $errors[self::formKey($key)] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Where the form keeps what the action names with `key`: under `data`, a content field as a field of the form.
     */
    private static function formKey(string $key): string
    {
        return match (true) {
            str_starts_with($key, 'data.') => $key,
            str_starts_with($key, 'content.') => 'data.' . mb_substr($key, mb_strlen('content.')),
            default => 'data.' . $key,
        };
    }
}
