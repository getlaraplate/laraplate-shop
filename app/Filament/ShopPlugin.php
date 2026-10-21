<?php

declare(strict_types=1);

namespace Modules\Shop\Filament;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Modules\Core\Support\ModuleColor;

final class ShopPlugin implements Plugin
{
    use ModuleFilamentPlugin;

    public function getModuleName(): string
    {
        return 'Shop';
    }

    public function getId(): string
    {
        return 'shop';
    }

    public function boot(Panel $panel): void
    {
        //
    }

    /**
     * Own the module's presence in the panel: the navigation group, its icon and the module colour
     * (registered under the module id, so widgets can paint with it) are declared here.
     */
    public function afterRegister(Panel $panel): void
    {
        $color = ModuleColor::filament($this->getModuleName());

        if ($color !== null) {
            $panel->colors([$this->getId() => $color]);
        }

        $panel->navigationGroups([
            'shop.navigation.main' => NavigationGroup::make()
                ->label(static fn (): string => (string) __('shop.navigation.main'))
                ->icon(Heroicon::OutlinedShoppingBag),
        ]);
    }
}
