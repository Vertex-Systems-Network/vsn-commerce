<?php

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Email verification
|--------------------------------------------------------------------------
|
| Laravel's VerifyEmail notification requires a route named
| "verification.verify". The link is signed, so it can safely verify an
| account even when the browser no longer has the registration session.
|
*/
Route::get('/email/verify/{id}/{hash}', function (Request $request, string $id, string $hash) {
    abort_unless($request->hasValidSignature(), 403);

    $user = User::query()->findOrFail($id);
    abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403);

    if (! $user->hasVerifiedEmail()) {
        $user->markEmailAsVerified();
        event(new Verified($user));
    }

    return redirect('/account?email_verified=1');
})->middleware('throttle:6,1')->name('verification.verify');

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