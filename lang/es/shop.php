<?php

declare(strict_types=1);

return [
    'entities' => [
        'products' => [
            'plural' => 'Productos',
            'singular' => 'Producto',
        ],
    ],
    'fields' => [
        'attributes' => 'Atributos',
        'company' => 'Empresa',
        'company_id' => 'Empresa',
        'featured' => 'Destacado',
        'is_default' => 'Variante predeterminada',
        'is_published_in_shop' => 'Publicado en la tienda',
        'item_id' => 'Artículo',
        'items' => 'Composición',
        'kind' => 'Tipo',
        'metadata' => 'Metadatos',
        'quantity' => 'Cantidad',
        'release_date' => 'Fecha de lanzamiento',
        'variants' => 'Variantes',
    ],
    'items' => [
        'discontinued' => 'descatalogado',
    ],
    'kinds' => [
        'digital' => 'Digital',
        'display_only' => 'Solo exposición',
        'physical' => 'Físico',
    ],
    'navigation' => [
        'main' => 'Tienda',
    ],
    'roles' => [
        'component' => 'Componente',
        'main' => 'Principal',
    ],
    'tabs' => [
        'composition' => 'Composición',
        'content' => 'Contenido',
        'product' => 'Producto',
        'variants' => 'Variantes',
    ],
    'validation' => [
        'componentsObject' => 'Los componentes deben ser un objeto JSON.',
        'oneDefaultVariant' => 'Exactamente una variante debe ser la predeterminada.',
        'saveRefused' => 'No se pudo guardar el producto.',
    ],
    'wizard' => [
        'default' => 'predeterminada',
        'steps' => [
            'composition' => 'Composición',
            'content' => 'Contenido',
            'product' => 'Producto',
            'variants' => 'Variantes',
        ],
        'variant' => 'Variante',
    ],
];
