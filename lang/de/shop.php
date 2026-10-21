<?php

declare(strict_types=1);

return [
    'entities' => [
        'products' => [
            'plural' => 'Produkte',
            'singular' => 'Produkt',
        ],
    ],
    'fields' => [
        'attributes' => 'Attribute',
        'company' => 'Unternehmen',
        'company_id' => 'Unternehmen',
        'featured' => 'Hervorgehoben',
        'is_default' => 'Standardvariante',
        'is_published_in_shop' => 'Im Shop veröffentlicht',
        'item_id' => 'Artikel',
        'items' => 'Zusammensetzung',
        'kind' => 'Art',
        'metadata' => 'Metadaten',
        'quantity' => 'Menge',
        'release_date' => 'Veröffentlichungsdatum',
        'variants' => 'Varianten',
    ],
    'kinds' => [
        'digital' => 'Digital',
        'display_only' => 'Nur Anzeige',
        'physical' => 'Physisch',
    ],
    'navigation' => [
        'main' => 'Shop',
    ],
    'roles' => [
        'component' => 'Komponente',
        'main' => 'Hauptartikel',
    ],
    'tabs' => [
        'composition' => 'Zusammensetzung',
        'content' => 'Inhalt',
        'product' => 'Produkt',
        'variants' => 'Varianten',
    ],
    'validation' => [
        'componentsObject' => 'Die Komponenten müssen ein JSON-Objekt sein.',
        'oneDefaultVariant' => 'Genau eine Variante muss die Standardvariante sein.',
    ],
    'wizard' => [
        'default' => 'Standard',
        'steps' => [
            'composition' => 'Zusammensetzung',
            'content' => 'Inhalt',
            'product' => 'Produkt',
            'variants' => 'Varianten',
        ],
        'variant' => 'Variante',
    ],
];
