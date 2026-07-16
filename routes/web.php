<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/docs', function () {
    return view('api.scalar');
});

Route::get('/docs/openapi.yaml', function () {
    return response()->file(base_path('docs/api/openapi-v1.yaml'));
});
