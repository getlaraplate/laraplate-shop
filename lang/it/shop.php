<?php

declare(strict_types=1);

return [
    'entities' => [
        'products' => [
            'plural' => 'Prodotti',
            'singular' => 'Prodotto',
        ],
    ],
    'fields' => [
        'attributes' => 'Attributi',
        'company' => 'Azienda',
        'company_id' => 'Azienda',
        'featured' => 'In evidenza',
        'is_default' => 'Variante predefinita',
        'is_published_in_shop' => 'Pubblicato nello shop',
        'item_id' => 'Articolo',
        'items' => 'Composizione',
        'kind' => 'Tipo',
        'metadata' => 'Metadati',
        'quantity' => 'Quantità',
        'release_date' => 'Data di rilascio',
        'variants' => 'Varianti',
    ],
    'items' => [
        'discontinued' => 'fuori produzione',
    ],
    'kinds' => [
        'digital' => 'Digitale',
        'display_only' => 'Solo vetrina',
        'physical' => 'Fisico',
    ],
    'navigation' => [
        'main' => 'Shop',
    ],
    'roles' => [
        'component' => 'Componente',
        'main' => 'Principale',
    ],
    'tabs' => [
        'composition' => 'Composizione',
        'content' => 'Contenuto',
        'product' => 'Prodotto',
        'variants' => 'Varianti',
    ],
    'validation' => [
        'componentsObject' => 'I componenti devono essere un oggetto JSON.',
        'oneDefaultVariant' => 'Esattamente una variante deve essere quella predefinita.',
        'saveRefused' => 'Il prodotto non può essere salvato.',
    ],
    'wizard' => [
        'default' => 'predefinita',
        'steps' => [
            'composition' => 'Composizione',
            'content' => 'Contenuto',
            'product' => 'Prodotto',
            'variants' => 'Varianti',
        ],
        'variant' => 'Variante',
    ],
];
