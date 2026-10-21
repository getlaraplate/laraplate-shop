<?php

declare(strict_types=1);

return [
    'entities' => [
        'products' => [
            'plural' => 'Products',
            'singular' => 'Product',
        ],
    ],
    'fields' => [
        'attributes' => 'Attributes',
        'company' => 'Company',
        'company_id' => 'Company',
        'featured' => 'Featured',
        'is_default' => 'Default variant',
        'is_published_in_shop' => 'Published in the shop',
        'item_id' => 'Item',
        'items' => 'Composition',
        'kind' => 'Kind',
        'metadata' => 'Metadata',
        'quantity' => 'Quantity',
        'release_date' => 'Release date',
        'variants' => 'Variants',
    ],
    'items' => [
        'discontinued' => 'discontinued',
    ],
    'kinds' => [
        'digital' => 'Digital',
        'display_only' => 'Display only',
        'physical' => 'Physical',
    ],
    'navigation' => [
        'main' => 'Shop',
    ],
    'roles' => [
        'component' => 'Component',
        'main' => 'Main',
    ],
    'tabs' => [
        'composition' => 'Composition',
        'content' => 'Content',
        'product' => 'Product',
        'variants' => 'Variants',
    ],
    'validation' => [
        'componentsObject' => 'The components must be a JSON object.',
        'oneDefaultVariant' => 'Exactly one variant must be the default.',
        'saveRefused' => 'The product could not be saved.',
    ],
    'wizard' => [
        'default' => 'default',
        'steps' => [
            'composition' => 'Composition',
            'content' => 'Content',
            'product' => 'Product',
            'variants' => 'Variants',
        ],
        'variant' => 'Variant',
    ],
];
