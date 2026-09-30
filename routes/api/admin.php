<?php

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
| - Signed in (auth:sanctum + role:admin): /admin/auth/me, logout, password;
|   products, variants, variant photos, tags; read-only customers and
|   orders; PATCH orders/{order}/tracking (no other order updates).
|
| Endpoint list: docs/backend-srs.md, section 14.
| Conventions: docs/backend-architecture.md, section 2.1.
|
*/

Route::prefix('api/v1/admin')->middleware('api')->name('admin.v1.')->group(function () {
    //
});
