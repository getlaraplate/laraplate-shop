<?php

declare(strict_types=1);

return [
    'entities' => [
        'products' => [
            'plural' => 'Izdelki',
            'singular' => 'Izdelek',
        ],
    ],
    'fields' => [
        'attributes' => 'Atributi',
        'company' => 'Podjetje',
        'company_id' => 'Podjetje',
        'featured' => 'Izpostavljeno',
        'is_default' => 'Privzeta različica',
        'is_published_in_shop' => 'Objavljeno v trgovini',
        'item_id' => 'Artikel',
        'items' => 'Sestava',
        'kind' => 'Vrsta',
        'metadata' => 'Metapodatki',
        'quantity' => 'Količina',
        'release_date' => 'Datum izida',
        'variants' => 'Različice',
    ],
    'items' => [
        'discontinued' => 'ukinjen',
    ],
    'kinds' => [
        'digital' => 'Digitalni',
        'display_only' => 'Samo prikaz',
        'physical' => 'Fizični',
    ],
    'navigation' => [
        'main' => 'Trgovina',
    ],
    'roles' => [
        'component' => 'Komponenta',
        'main' => 'Glavni',
    ],
    'tabs' => [
        'composition' => 'Sestava',
        'content' => 'Vsebina',
        'product' => 'Izdelek',
        'variants' => 'Različice',
    ],
    'validation' => [
        'componentsObject' => 'Komponente morajo biti objekt JSON.',
        'oneDefaultVariant' => 'Natanko ena različica mora biti privzeta.',
        'saveRefused' => 'Izdelka ni bilo mogoče shraniti.',
    ],
    'wizard' => [
        'default' => 'privzeta',
        'steps' => [
            'composition' => 'Sestava',
            'content' => 'Vsebina',
            'product' => 'Izdelek',
            'variants' => 'Različice',
        ],
        'variant' => 'Različica',
    ],
];
