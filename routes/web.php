<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| React application shell
|--------------------------------------------------------------------------
|
| The storefront, customer account, vendor dashboard and admin panel are
| compiled by Vite and served by this same Laravel application. API,
| Sanctum, storage and framework health routes are intentionally excluded.
|
*/
Route::view('/{path?}', 'app')
    ->where('path', '^(?!api(?:/|$)|sanctum(?:/|$)|storage(?:/|$)|up$).*$')
    ->name('spa');
