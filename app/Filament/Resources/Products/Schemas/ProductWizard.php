<?php

declare(strict_types=1);

namespace Modules\Shop\Filament\Resources\Products\Schemas;

use Filament\Schemas\Components\Wizard\Step;

/**
 * The four steps that create a product: the product's own columns, its CMS content, its variants, and the
 * ERP items that compose each variant. The components are those of {@see ProductForm}, which also lays them
 * out as the tabs of the edit form and reshapes the state of either into the payload of the write actions.
 */
final class ProductWizard
{
    /**
     * @return list<Step>
     */
    public static function steps(): array
    {
        return [
            Step::make(__('shop.wizard.steps.product'))
                ->schema(ProductForm::productComponents())
                ->columns(2),
            Step::make(__('shop.wizard.steps.content'))
                ->schema(ProductForm::contentComponents())
                ->columns(2),
            Step::make(__('shop.wizard.steps.variants'))
                ->schema(ProductForm::variantsComponents()),
            Step::make(__('shop.wizard.steps.composition'))
                ->schema(ProductForm::compositionComponents()),
        ];
    }
}
