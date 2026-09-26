<?php

/*
|--------------------------------------------------------------------------
| Machine themes
|--------------------------------------------------------------------------
|
| Every theme plays the same Hay Link game (config/hay_link.php) and
| shares the linked Major and Grand. A theme only changes the artwork and the
| names of the symbols. Each symbol id's image is `{images}/{id}.png`.
|
*/

return [

    'farmers-frenzy' => [
        'title' => 'Farmers Frenzy',
        'images' => 'images/farmers-frenzy',
        'scene' => 'scene.jpg',
        'session_key' => 'farmers_frenzy.state',
        'names' => [
            'farmer' => 'Farmer', 'moon' => 'Cowgirl', 'tractor' => 'Tractor', 'barn' => 'Barn', 'dog' => 'Dog',
            'milk_bottle' => 'Milk Bottle', 'king' => 'K', 'queen' => 'Q', 'jack' => 'J', 'ten' => '10', 'nine' => '9',
            'bale' => 'Hay Bale', 'door' => 'Barn Door',
        ],
        'plurals' => ['farmer' => 'Farmer wilds', 'moon' => 'Cowgirls', 'bale' => 'Hay Bales', 'door' => 'Barn Doors'],
    ],

];
