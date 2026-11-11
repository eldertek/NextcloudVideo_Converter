<?php

declare(strict_types=1);

return [
    'routes' => [
        [
            'name' => 'conversion#convertHere',
            'url' => '/ajax/convertHere.php',
            'verb' => 'POST',
        ],
        [
            'name' => 'conversion#convert',
            'url' => '/api/v1/convert',
            'verb' => 'POST',
        ],
        [
            'name' => 'conversion#formats',
            'url' => '/api/v1/formats',
            'verb' => 'GET',
        ],
    ],
];
