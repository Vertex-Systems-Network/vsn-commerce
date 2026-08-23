<?php

use App\Http\Controllers\EmailVerificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Email verification
|--------------------------------------------------------------------------
|
| Laravel's VerifyEmail notification requires a route named
| "verification.verify". The signed middleware validates the temporary
| signature before the controller verifies the addressed account.
|
*/
Route::get('/email/verify/{id}/{hash}', EmailVerificationController::class)
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');

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
