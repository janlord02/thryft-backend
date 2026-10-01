<?php

/*
|--------------------------------------------------------------------------
| Business page blocks
|--------------------------------------------------------------------------
|
| The closed set of blocks a merchant can put on their page, with the fields
| each one carries. This is the contract between the editor, the API
| validation, the app renderer and the Blade renderer: a block type or field
| that is not listed here cannot be saved, so neither renderer ever meets a
| shape it does not know.
|
| Field specs:
|   string:N   plain text up to N characters (tags stripped)
|   text:N     multi-line plain text up to N characters
|   int:A,B    whole number between A and B
|   bool       true/false
|   url        http(s) link
|   path       an image uploaded through the editor (page-media/…)
|   paths:N    up to N such images
|   rows:N     up to N {label, value} pairs (opening hours)
|
*/

return [

    'max_blocks' => 20,

    'types' => [
        'hero' => [
            'label' => 'Hero',
            'description' => 'A big photo with your name and one line underneath.',
            'icon' => 'panorama',
            'fields' => ['image' => 'path', 'headline' => 'string:120', 'subheadline' => 'string:200'],
        ],
        'about' => [
            'label' => 'About',
            'description' => 'Who you are, in your own words.',
            'icon' => 'storefront',
            'fields' => ['title' => 'string:80', 'text' => 'text:2000'],
        ],
        'gallery' => [
            'label' => 'Photos',
            'description' => 'Up to twelve photos of the place, the food, the work.',
            'icon' => 'photo_library',
            'fields' => ['title' => 'string:80', 'images' => 'paths:12'],
        ],
        'hours' => [
            'label' => 'Hours',
            'description' => 'When you are open.',
            'icon' => 'schedule',
            'fields' => ['title' => 'string:80', 'rows' => 'rows:7'],
        ],
        'deals' => [
            'label' => 'Deals',
            'description' => 'Your current offers, kept up to date automatically.',
            'icon' => 'local_offer',
            'fields' => ['title' => 'string:80', 'limit' => 'int:1,12'],
        ],
        'events' => [
            'label' => 'Events',
            'description' => 'What is coming up, kept up to date automatically.',
            'icon' => 'event',
            'fields' => ['title' => 'string:80', 'limit' => 'int:1,12'],
        ],
        'contact' => [
            'label' => 'Contact',
            'description' => 'Address, phone and a map link, from your profile.',
            'icon' => 'place',
            'fields' => ['title' => 'string:80', 'note' => 'string:300', 'show_map' => 'bool'],
        ],
        'text' => [
            'label' => 'Text',
            'description' => 'A heading and a paragraph about anything.',
            'icon' => 'notes',
            'fields' => ['title' => 'string:80', 'body' => 'text:4000'],
        ],
        'cta' => [
            'label' => 'Button',
            'description' => 'A link out: online ordering, booking, your website.',
            'icon' => 'open_in_new',
            'fields' => ['label' => 'string:60', 'url' => 'url'],
        ],
    ],

    // Starting points. Each is a list of blocks the merchant then edits.
    'templates' => [
        [
            'key' => 'classic',
            'label' => 'Classic',
            'description' => 'Photo, your story, deals, hours, how to find you.',
            'blocks' => [
                ['type' => 'hero'],
                ['type' => 'about', 'title' => 'About us'],
                ['type' => 'deals', 'title' => 'Current deals', 'limit' => 6],
                ['type' => 'hours', 'title' => 'Hours'],
                ['type' => 'contact', 'title' => 'Find us', 'show_map' => true],
            ],
        ],
        [
            'key' => 'showcase',
            'label' => 'Showcase',
            'description' => 'Photos first, then the rest.',
            'blocks' => [
                ['type' => 'hero'],
                ['type' => 'gallery', 'title' => 'Photos'],
                ['type' => 'about', 'title' => 'About us'],
                ['type' => 'deals', 'title' => 'Current deals', 'limit' => 6],
                ['type' => 'events', 'title' => 'Coming up', 'limit' => 4],
                ['type' => 'contact', 'title' => 'Find us', 'show_map' => true],
            ],
        ],
        [
            'key' => 'simple',
            'label' => 'Simple',
            'description' => 'Just the essentials.',
            'blocks' => [
                ['type' => 'about', 'title' => 'About us'],
                ['type' => 'deals', 'title' => 'Current deals', 'limit' => 6],
                ['type' => 'contact', 'title' => 'Find us', 'show_map' => true],
            ],
        ],
    ],
];
