<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return to_route('filament.store.tenant');
});
