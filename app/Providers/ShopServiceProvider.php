<?php

declare(strict_types=1);

namespace Modules\Shop\Providers;

use Illuminate\Support\Facades\Gate;
use Modules\CMS\Services\ContentExtenderRegistry;
use Modules\Core\Overrides\ModuleServiceProvider;
use Modules\Shop\Models\Product;
use Modules\Shop\Policies\ProductPolicy;
use Override;

final class ShopServiceProvider extends ModuleServiceProvider
{
    #[Override]
    protected string $name = 'Shop';

    #[Override]
    protected string $nameLower = 'shop';

    /**
     * Provider classes to register.
     *
     * @var array<int, class-string>
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    /**
     * Register the product as a CMS content extender (E4). The registry is a singleton bound by
     * CMSServiceProvider; resolving() runs the registration whenever it is first resolved, and the
     * resolved() guard covers the case where it was already resolved before this provider booted —
     * so registration takes effect regardless of provider order.
     */
    #[Override]
    public function register(): void
    {
        parent::register();

        $this->app->resolving(
            ContentExtenderRegistry::class,
            static function (ContentExtenderRegistry $registry): void {
                $registry->register(Product::CONTENT_ALIAS, Product::class);
            },
        );

        if ($this->app->resolved(ContentExtenderRegistry::class)) {
            $this->app->make(ContentExtenderRegistry::class)->register(Product::CONTENT_ALIAS, Product::class);
        }
    }

    #[Override]
    public function boot(): void
    {
        parent::boot();

        Gate::policy(Product::class, ProductPolicy::class);
    }
}
