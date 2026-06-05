<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Collection Name
    |--------------------------------------------------------------------------
    | The name of the generated Postman collection.
    */
    'name' => env('APP_NAME', 'Laravel') . ' API Documentation',

    /*
    |--------------------------------------------------------------------------
    | Base URL
    |--------------------------------------------------------------------------
    | The base URL used for all requests in the collection.
    */
    'base_url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Output File
    |--------------------------------------------------------------------------
    | The filename for the generated Postman collection JSON.
    | This file will be placed in the project root directory.
    */
    'output_file' => 'postman_collection.json',

    /*
    |--------------------------------------------------------------------------
    | Include Middleware
    |--------------------------------------------------------------------------
    | Only include routes that are assigned to one of these middleware groups.
    | Leave empty to include all routes.
    */
    'include_middleware' => ['api'],

    /*
    |--------------------------------------------------------------------------
    | Exclude Patterns
    |--------------------------------------------------------------------------
    | Route URI patterns to exclude from the generated collection.
    | Supports simple string matching (strpos).
    */
    'exclude_patterns' => [
        'telescope',
        'horizon',
        '_debugbar',
        'sanctum',
        'livewire',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    | Default auth configuration for all requests.
    | type: noauth | bearer | basic
    */
    'auth' => [
        'type'  => 'noauth', // noauth, bearer, basic
        'token' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Grouping Strategy
    |--------------------------------------------------------------------------
    | How to group endpoints in the collection.
    | prefix    => group by route URI prefix segments
    | controller => group by controller class name
    */
    'group_by' => 'prefix', // prefix or controller

    /*
    |--------------------------------------------------------------------------
    | Generate Fake Values
    |--------------------------------------------------------------------------
    | When true, the package will generate realistic sample values for
    | request parameters based on their validation rules.
    */
    'generate_fake_values' => true,

    /*
    |--------------------------------------------------------------------------
    | Persian Descriptions
    |--------------------------------------------------------------------------
    | When true, auto-generated endpoint descriptions will be written in Farsi.
    */
    'persian_descriptions' => true,

    /*
    |--------------------------------------------------------------------------
    | Default Headers
    |--------------------------------------------------------------------------
    | Headers that will be added to every request in the collection.
    */
    'default_headers' => [
        ['key' => 'Content-Type', 'value' => 'application/json'],
        ['key' => 'Accept',       'value' => 'application/json'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Include Route Parameters Description
    |--------------------------------------------------------------------------
    | Attempt to infer descriptions for path parameters from parameter names.
    */
    'describe_route_params' => true,

];
