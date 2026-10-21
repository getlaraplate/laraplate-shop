<?php

declare(strict_types=1);

namespace Modules\Shop\Filament\Resources\Products\Concerns;

use Closure;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * The write actions refuse with the keys of their payload (`content.title`, `variants.0.id`, a model rule such as
 * `quantity`), which name no field of the form. A page that writes through them wraps the call with this: the
 * refusal is said in a notification and its keys are moved under `data.` (content fields as the form names them),
 * so a save that fails looks like a failure, on the field at fault, instead of looking like it did nothing. Create
 * and edit share it so the two paths report a refusal the same way.
 */
trait HandlesProductWriteErrors
{
    /**
     * @param  Closure(): Model  $write
     *
     * @throws ValidationException
     */
    protected function writeThroughAction(Closure $write): Model
    {
        try {
            return $write();
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
