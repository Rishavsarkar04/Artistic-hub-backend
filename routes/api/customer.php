<?php

use App\Http\Controllers\Api\Customer\AddressController;
use App\Http\Controllers\Api\Customer\AddressDefaultController;
use App\Http\Controllers\Api\Customer\Auth\RegisterController;
use App\Http\Controllers\Api\Customer\Auth\SessionController;
use App\Http\Controllers\Api\Customer\ProfileAvatarController;
use App\Http\Controllers\Api\Customer\ProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Customer API
|--------------------------------------------------------------------------
|
| bootstrap/app.php only loads this file; the prefix, version and
| middleware are set below. Everything the storefront calls lives here;
| admin routes live in routes/api/admin.php and never share a group
| with these.
|
| Public routes with no session (catalog, tags, webhook) are in
| routes/api.php.
|
| Groups inside each version, in this order:
| - Signed out, throttled: /auth/register, login, forgot-password,
|   reset-password. Sign-in accepts customer accounts only.
| - Signed in (auth:api + scope:customer + role:customer + active + token.fresh): /auth/me, logout, password;
|   /customer/profile, addresses, cart, orders; /checkout/*.
|
| Endpoint list: docs/backend-srs.md, section 14.
| Conventions: docs/backend-architecture.md, section 2.1.
|
*/

Route::prefix('api/v1')->middleware('api')->name('customer.v1.')->group(function () {
    Route::prefix('auth')->name('auth.')->middleware('throttle:6,1')->group(function () {
        Route::post('register', [RegisterController::class, 'store'])->name('register');
        Route::post('login', [SessionController::class, 'store'])->name('login');
    });

    Route::middleware(['auth:api', 'scope:customer', 'role:customer', 'active', 'token.fresh'])->group(function () {
        Route::prefix('auth')->name('auth.')->group(function () {
            Route::get('me', [SessionController::class, 'show'])->name('me');
            Route::post('logout', [SessionController::class, 'destroy'])->name('logout');
        });

        Route::prefix('customer')->group(function () {
            Route::get('profile', [ProfileController::class, 'show'])->name('profile.show');
            Route::post('profile', [ProfileController::class, 'store'])->name('profile.store');
            Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
            // POST (not PUT): file uploads are sent as multipart/form-data, which PHP only parses on POST.
            Route::post('profile/avatar', [ProfileAvatarController::class, 'update'])->name('profile.avatar.update');
            Route::delete('profile/avatar', [ProfileAvatarController::class, 'destroy'])->name('profile.avatar.destroy');

            // No delete: address deletion is not in scope.
            Route::get('addresses', [AddressController::class, 'index'])->name('addresses.index');
            Route::post('addresses', [AddressController::class, 'store'])->name('addresses.store');
            Route::put('addresses/{address}', [AddressController::class, 'update'])->whereUlid('address')->name('addresses.update');
            Route::patch('addresses/{address}/default', [AddressDefaultController::class, 'update'])->whereUlid('address')->name('addresses.default');
        });
    });
});
