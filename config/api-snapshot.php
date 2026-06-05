<?php

return [

    'name' => env('APP_NAME', 'Laravel') . ' API Documentation',

    'base_url' => env('APP_URL', 'http://localhost'),

    'output_file' => 'postman_collection.json',

    'include_middleware' => ['api'],

    'exclude_patterns' => [
        'telescope',
        'horizon',
        '_debugbar',
        'sanctum',
        'livewire',
    ],

    'auth' => [
        'type'  => 'noauth', // noauth | bearer | basic
        'token' => null,
    ],

    'group_by' => 'prefix', // prefix | controller

    'generate_fake_values' => true,

    'persian_descriptions' => true,

    'default_headers' => [
        ['key' => 'Content-Type', 'value' => 'application/json'],
        ['key' => 'Accept',       'value' => 'application/json'],
    ],

];
