<?php

use App\Http\Controllers\Api\Customer\AddressController;
use App\Http\Controllers\Api\Customer\AddressDefaultController;
use App\Http\Controllers\Api\Customer\Auth\PasswordController;
use App\Http\Controllers\Api\Customer\Auth\PasswordResetController;
use App\Http\Controllers\Api\Customer\Auth\RegisterController;
use App\Http\Controllers\Api\Customer\Auth\SessionController;
use App\Http\Controllers\Api\Customer\CartController;
use App\Http\Controllers\Api\Customer\CartItemController;
use App\Http\Controllers\Api\Customer\CheckoutController;
use App\Http\Controllers\Api\Customer\OrderController;
use App\Http\Controllers\Api\Customer\ProfileAvatarController;
use App\Http\Controllers\Api\Customer\ProfileController;
use App\Support\OrderNumber;
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
|   /customer/profile, addresses, cart, checkout, orders.
|
| Endpoint list: docs/backend-srs.md, section 14.
| Conventions: docs/backend-architecture.md, section 2.1.
|
*/

Route::prefix('api/v1')->middleware('api')->name('customer.v1.')->group(function () {
    Route::prefix('auth')->name('auth.')->middleware('throttle:6,1')->group(function () {
        Route::post('register', [RegisterController::class, 'store'])->name('register');
        Route::post('login', [SessionController::class, 'store'])->name('login');
        Route::post('forgot-password', [PasswordResetController::class, 'sendLink'])->name('forgot-password');
        Route::post('reset-password', [PasswordResetController::class, 'reset'])->name('reset-password');
    });

    Route::middleware(['auth:api', 'scope:customer', 'role:customer', 'active', 'token.fresh'])->group(function () {
        Route::prefix('auth')->name('auth.')->group(function () {
            Route::get('me', [SessionController::class, 'show'])->name('me');
            Route::post('logout', [SessionController::class, 'destroy'])->name('logout');
            // Throttled: the current password must not be guessable by repeated tries.
            Route::put('password', [PasswordController::class, 'update'])->middleware('throttle:6,1')->name('password');
        });

        Route::prefix('customer')->group(function () {
            // /customer/profile
            Route::prefix('profile')->name('profile.')->group(function () {
                Route::controller(ProfileController::class)->group(function () {
                    Route::get('/', 'show')->name('show');
                    Route::post('/', 'store')->name('store');
                    Route::put('/', 'update')->name('update');
                });

                // POST (not PUT): file uploads are sent as multipart/form-data, which PHP only parses on POST.
                Route::controller(ProfileAvatarController::class)->prefix('avatar')->name('avatar.')->group(function () {
                    Route::post('/', 'update')->name('update');
                    Route::delete('/', 'destroy')->name('destroy');
                });
            });

            // /customer/addresses. No delete: address deletion is not in scope.
            Route::prefix('addresses')->name('addresses.')->group(function () {
                Route::controller(AddressController::class)->group(function () {
                    Route::get('/', 'index')->name('index');
                    Route::post('/', 'store')->name('store');
                    Route::put('{address}', 'update')->whereUlid('address')->name('update');
                });

                Route::patch('{address}/default', [AddressDefaultController::class, 'update'])->whereUlid('address')->name('default');
            });

            // /customer/cart
            Route::prefix('cart')->name('cart.')->group(function () {
                Route::get('/', [CartController::class, 'show'])->name('show');

                Route::controller(CartItemController::class)->prefix('items')->name('items.')->group(function () {
                    Route::post('/', 'store')->name('store');
                    Route::patch('{item}', 'update')->whereUlid('item')->name('update');
                    Route::delete('{item}', 'destroy')->whereUlid('item')->name('destroy');
                });
            });

            // /customer/checkout
            Route::prefix('checkout')->name('checkout.')->controller(CheckoutController::class)->group(function () {
                Route::get('review', 'review')->name('review');
                Route::post('/', 'store')->name('store');
            });

            // /customer/orders
            Route::prefix('orders')->name('orders.')->controller(OrderController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('{order}', 'show')->where('order', OrderNumber::PATTERN)->name('show');
            });
        });
    });
});
