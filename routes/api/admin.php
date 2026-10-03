<?php

use App\Http\Controllers\Api\Admin\Auth\SessionController;
use App\Http\Controllers\Api\Admin\CustomerController;
use App\Http\Controllers\Api\Admin\OrderController;
use App\Http\Controllers\Api\Admin\ProductController;
use App\Http\Controllers\Api\Admin\ProductVariantController;
use App\Http\Controllers\Api\Admin\TagController;
use App\Http\Controllers\Api\Admin\VariantPhotoController;
use App\Support\OrderNumber;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin API
|--------------------------------------------------------------------------
|
| bootstrap/app.php only loads this file; the prefix, version and
| middleware are set below. Customer routes live in
| routes/api/customer.php and never share a group with these.
|
| Groups inside each version, in this order:
| - Signed out, throttled: /admin/auth/login, forgot-password,
|   reset-password. Sign-in accepts admin accounts only.
| - Signed in (auth:api + scope:admin + role:admin + active + token.fresh): /admin/auth/me, logout, password;
|   products, variants, variant photos, tags; read-only customers and
|   orders; PATCH orders/{order}/tracking (no other order updates).
|
| Endpoint list: docs/backend-srs.md, section 14.
| Conventions: docs/backend-architecture.md, section 2.1.
|
*/

Route::prefix('api/v1/admin')->middleware('api')->name('admin.v1.')->group(function () {
    Route::prefix('auth')->name('auth.')->middleware('throttle:6,1')->group(function () {
        Route::post('login', [SessionController::class, 'store'])->name('login');
    });

    Route::middleware(['auth:api', 'scope:admin', 'role:admin', 'active', 'token.fresh'])->group(function () {
        Route::prefix('auth')->name('auth.')->group(function () {
            Route::get('me', [SessionController::class, 'show'])->name('me');
            Route::post('logout', [SessionController::class, 'destroy'])->name('logout');
        });

        // Read-only: no customer-management mutations are in scope.
        Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');

        Route::controller(OrderController::class)->prefix('orders')->name('orders.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('{order}', 'show')->where('order', OrderNumber::PATTERN)->name('show');
        });

        // Catalog. A product is saved whole, with its variants, tags and photo ids (one Save in the form).
        Route::apiResource('products', ProductController::class)->whereUlid('product');

        Route::delete('products/{product}/variants/{variant}', [ProductVariantController::class, 'destroy'])
            ->whereUlid(['product', 'variant'])->name('products.variants.destroy');
        Route::post('uploads/variant-photos', [VariantPhotoController::class, 'store'])->name('uploads.variant-photos.store');
        Route::delete('uploads/variant-photos/{media}', [VariantPhotoController::class, 'destroy'])
            ->whereUlid('media')->name('uploads.variant-photos.destroy');
        Route::apiResource('tags', TagController::class)->except('show')->whereUlid('tag');
    });
});
