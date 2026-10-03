<?php

use App\Http\Controllers\Api\Shop\ProductVariantController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Shared API
|--------------------------------------------------------------------------
|
| Routes that belong to neither the customer nor the admin API: no user
| session and no role. bootstrap/app.php only loads this file; the prefix,
| version and middleware are set below.
|
| Belongs here:
| - The public shop catalogue under /shop (product-variant listing, later details).
| - Provider callbacks: the Razorpay webhook (authenticated by signature).
|
| Anything that needs a signed-in customer goes in routes/api/customer.php,
| and anything for the admin panel in routes/api/admin.php.
|
| Endpoint list: docs/backend-srs.md, section 14.
| Conventions: docs/backend-architecture.md, section 2.1.
|
*/

Route::prefix('api/v1')->middleware('api')->name('api.v1.')->group(function () {
    // Storefront catalogue, open to everyone.
    Route::prefix('shop')->name('shop.')->group(function () {
        Route::get('product-variants', [ProductVariantController::class, 'index'])->name('product-variants.index');
    });
});
