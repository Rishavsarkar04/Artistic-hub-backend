<?php

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
| - Signed in (auth:sanctum + role:customer): /auth/me, logout, password;
|   /customer/profile, addresses, cart, orders; /checkout/*.
|
| Endpoint list: docs/backend-srs.md, section 14.
| Conventions: docs/backend-architecture.md, section 2.1.
|
*/

Route::prefix('api/v1')->middleware('api')->name('customer.v1.')->group(function () {
    //
});
