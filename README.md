<p>&nbsp;</p>
<p align="center">
	<a href="https://github.com/swolley" target="_blank">
		<img src="https://raw.githubusercontent.com/swolley/images/refs/heads/master/logo_laraplate.png?raw=true" width="300" alt="Laraplate Logo" />
    </a>
</p>
<p>&nbsp;</p>
<p align="center">
    <img src="https://img.shields.io/badge/Laravel-12.x-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" alt="Laravel 12">
    <img src="https://img.shields.io/badge/PHP-8.5+-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.2+">
    <img src="https://img.shields.io/badge/License-GNU_AGPL_v3-green?style=for-the-badge" alt="GNU AGPL v3 License">
</p>
<p>&nbsp;</p>

# Laraplate E-Shop Module

> ⚠️ **Caution**: This package is a **work in progress**. **Don't use this in production or use at your own risk**—no guarantees are provided... or better yet, collaborate with me to create the definitive Laravel boilerplate; that's the right place to introduce your ideas. Let me know your ideas...

## Table of Contents

-   [Description](#description)
-   [Installation](#installation)
-   [Configuration](#configuration)
-   [Current Status](#current-status)
-   [Roadmap](#roadmap)
-   [Scripts](#scripts)
-   [Contributing](#contributing)
-   [License](#license)

## Description

The Shop Module provides the online sales channel for Laraplate.
It orchestrates CMS content, ERP items and SAO support into a storefront.

The catalog foundation ships: a `Product` that extends a CMS `Content` through the content-extension seam (with a transparent content merge), `ProductVariant`s, and a `VariantItem` composition from ERP items. Storefront browsing, cart, checkout, payments, digital fulfilment and reviews are not built yet and arrive in later plans.

## Installation

If you want to add this module to your project, you can use the `joshbrw/laravel-module-installer` package.

Add repository to your `composer.json` file:

```json
"repositories": [
    {
        "type": "composer",
        "url": "https://github.com/getlaraplate/laraplate-core.git"
    },
    {
        "type": "composer",
        "url": "https://github.com/getlaraplate/laraplate-cms.git"
    },
    {
        "type": "composer",
        "url": "https://github.com/getlaraplate/laraplate-erp.git"
    },
    {
        "type": "composer",
        "url": "https://github.com/getlaraplate/laraplate-sao.git"
    },
    {
        "type": "composer",
        "url": "https://github.com/getlaraplate/laraplate-shop.git"
    }
]
```

```bash
composer require joshbrw/laravel-module-installer getlaraplate/laraplate-core getlaraplate/laraplate-cms getlaraplate/laraplate-erp getlaraplate/laraplate-sao getlaraplate/laraplate-shop
```

Then, you can install the module by running the following command:

```bash
php artisan module:install Core
php artisan module:install CMS
php artisan module:install ERP
php artisan module:install SAO
php artisan module:install Shop
```

## Configuration

The module configuration is automatically mapped as `shop.*` when the module is active.
Configuration file: `Modules/Shop/config/config.php`.

> The module defines no environment variables yet. They will be added as domain features are introduced.

## Current Status

-   Module metadata (`module.json`) configured with provider registration; requires Core, CMS, ERP, SAO
-   Service providers extend Core's `ModuleServiceProvider` (`ShopServiceProvider`, `RouteServiceProvider`, `EventServiceProvider`)
-   Catalog foundation shipped: `Product` (content-extension seam consumer with transparent merge), `ProductVariant` (single default per product), `VariantItem` composition from ERP items, `ProductKind`, and the Shop-owned product content-entity seed
-   Independent git repository registered as the `Modules/Shop` submodule
-   Enabled in `modules_statuses.json`; no HTTP surface yet (the generic scaffold controller was removed)

## Roadmap

Planned next steps for Shop domain design and implementation:

-   Catalog exposure of ERP items enriched with CMS content
-   Cart and checkout workflows
-   Order handoff to ERP sales orders
-   Customer support integration through SAO

## Scripts

```bash
# Local formatting (dirty files only from project root)
vendor/bin/pint --dirty
```

## Contributing

If you want to contribute to this project, follow these steps:

1. Fork the repository.
2. Create a new branch for your feature or correction.
3. Send a pull request.

## License

Shop Module is open-sourced software licensed under the [GNU AGPL v3](https://www.gnu.org/licenses/agpl-3.0.html).

Copyright (c) 2025 Marco P. The full licence text is in [LICENSE](LICENSE).
