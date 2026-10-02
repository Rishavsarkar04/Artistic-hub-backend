# Artistic Hub — Laravel Backend Architecture and Design Patterns

Version: 1.1 (aligned with the codebase on 2026-10-01)

## 1. Purpose

Define the implementation architecture for the Artistic Hub candle
e-commerce backend.

Read this document together with:
- `docs/backend-srs.md` (required behavior and API contract)
- `docs/database/er-diagram.md` (database schema)

The SRS defines required behavior.
The ER diagram defines the database schema.
This document defines code organization and implementation patterns.

If these documents conflict, report the conflict before implementing
the affected behavior.

Do not silently add database tables, columns, enum values, or features.

### 1.1 Repository state

As of 2026-10-02 the backend is a Laravel 13 application with:
- API routes split into `routes/api.php`, `routes/api/customer.php`
  and `routes/api/admin.php` (section 2.1). Built so far: customer
  register/login/me/logout and admin login/me/logout (section 2.2),
  customer profile (create/read/update) and addresses
  (list/create/update/set default), and the admin customer list
  (`AdminCustomerQuery`).
  Do not run `php artisan install:api`: it would register `routes/api.php`
  through the `api:` option with its own prefix and overwrite the file.
- Installed: `laravel/passport` (personal access tokens only),
  `spatie/laravel-permission` (roles only) and `dedoc/scramble`.
  Not installed: the Razorpay SDK.
- Built: users and customer profiles (both soft-deleting together; users with `status`), roles, `customer_profiles` and
  `customer_addresses` migrations and models; enums `UserStatus`, `Role`,
  `Gender`, `AddressLabel`; `RoleSeeder`; `php artisan admin:create`
  (backed by `App\Services\Accounts\AdminAccountService`).
- The base `App\Http\Controllers\Controller` is empty, so it has no
  `authorize()` helper. Use `Gate::authorize()` in controllers.
- Database is MySQL (`DB_CONNECTION=mysql`, database `artistic_hub`), locally and as the planned production engine.

Follow `CLAUDE.md` and the `laravel-best-practices` skill in `.claude/skills/`.

## 2. Architectural approach

Use a modular Laravel monolith with:

- Thin controllers.
- Form Requests for request validation.
- Policies and middleware for authorization.
- Application services for business operations.
- Eloquent models for relationships and persistence.
- API Resources for response serialization.
- A Razorpay gateway adapter class for external API calls.
- Queued jobs for asynchronous work.
- Explicit database transactions for atomic operations.

Use domain-specific classes with clear responsibilities.
Avoid putting every operation into one large service.

Inject concrete classes; do not add interface-to-implementation
bindings in service providers for now (section 26.2).

Customer and admin APIs are kept apart by route file, URL prefix,
middleware group and controller namespace (section 2.1).

Default request flow:

Route
→ Authentication and role middleware
→ Form Request
→ Controller and policy authorization
→ Application service
→ Eloquent / payment gateway
→ API Resource

Authorization must happen before protected data is returned or changed.
Form Request authorize() may perform policy checks where appropriate.

## 2.1 Route organization

Split routes by audience. Each file owns its middleware, so a route's
access is clear from where it lives.

There are three API route files:

routes/
├── api.php            /api/v1/...        Shared: routes for neither audience,
│                                         with no user session. Public catalog
│                                         reads, tags, the Razorpay webhook.
└── api/
    ├── customer.php   /api/v1/...        Customer auth (/auth/*) and
    │                                     customer-only routes
    │                                     (/customer/*, /checkout/*).
    └── admin.php      /api/v1/admin/...  Everything for the admin panel,
                                          including /admin/auth/*.

`bootstrap/app.php` only loads the three files, with no prefix, name or
middleware. It uses `then:` rather than the built-in `api:` option, because
`api:` would add its own prefix and middleware:

then: function () {
    Route::group([], base_path('routes/api.php'));
    Route::group([], base_path('routes/api/customer.php'));
    Route::group([], base_path('routes/api/admin.php'));
},

Each file sets its own URL prefix, version, `api` middleware and route-name
prefix in one wrapper group per API version:

// routes/api.php
Route::prefix('api/v1')->middleware('api')->name('api.v1.')->group(function () {
    // public catalog, tags, webhook
});

// routes/api/customer.php
Route::prefix('api/v1')->middleware('api')->name('customer.v1.')->group(function () {
    // public, signed-out and signed-in groups
});

// routes/api/admin.php
Route::prefix('api/v1/admin')->middleware('api')->name('admin.v1.')->group(function () {
    // signed-out and signed-in groups
});

Versioning: keep the version in the route files, never in
`bootstrap/app.php`. A new version is a second wrapper group
(`api/v2`, names `api.v2.` / `customer.v2.` / `admin.v2.`) in the same file, holding
only the endpoints that changed; unchanged endpoints stay in v1. Route
names include the version so both can exist side by side. Controllers for
a changed endpoint can get a version namespace (`Api\Customer\V2\...`)
when that happens; do not create version folders before then.

The wrapper groups are currently empty; add routes as each feature is
built. Inside each version group, in this order:
- `routes/api.php`: public routes only; no `auth` or `role` middleware.
- Customer and admin files:
- A signed-out `auth/*` group (register only for customers; login, forgot
  and reset password), rate-limited.
- A signed-in group with the middleware stack from section 2.2.

When adding routes:
- Use `[Controller::class, 'method']` actions.
- Bind orders by `order_number`; use scoped bindings for nested resources
  (e.g. a photo must belong to the variant in the URL).
- Admin orders get only read routes and `PATCH orders/{order}/tracking`;
  never a generic order update route.

Rules:
- Route names are prefixed `api.v1.`, `customer.v1.` or `admin.v1.`, and controllers live in
  `App\Http\Controllers\Api\Customer` or `...\Api\Admin`
  (auth controllers in `Api\Customer\Auth` / `Api\Admin\Auth`).
  Controllers for `routes/api.php` live in `Api\Catalog` (catalog, tags)
  and `Api\Webhooks` (Razorpay).
- Never mix audiences in one group. A route that both roles need is
  defined once per file with its own controller, or stays public.
- Admin and customer sign-in are separate controllers. Each accepts
  only its own role and returns the same invalid-credentials response
  for the other role.
- Middleware aliases (`role`, `scope`, `active`, `token.fresh`) are
  registered in `bootstrap/app.php`. Policies still check ownership and
  abilities inside each group; the role middleware is not the only guard.
- Password-reset links point to the matching frontend (customer or admin
  reset page), for example by setting `ResetPassword::createUrlUsing()`
  based on the user's role.
- A route that needs no user session and belongs to neither audience goes
  in `routes/api.php`. The webhook stays there: it is authenticated by
  signature only.

## 2.2 Authentication (Passport personal access tokens)

Decided 2026-10-02 (backend SRS BE-AUTH-02).

**Tokens.** Admins and customers sign in through their own endpoints
(`POST /auth/login`, `POST /admin/auth/login`), which return a Passport
personal access token (a signed JWT, also stored in `oauth_access_tokens`
so it can be revoked). The frontend sends it as `Authorization: Bearer`.
There are no refresh tokens; when a token expires the user signs in again.
Passport's OAuth routes (`/oauth/*`) are switched off
(`Passport::ignoreRoutes()` in `AppServiceProvider`).

**Guards.**
- Login guard: `api` (driver `passport`, provider `users`) in
  `config/auth.php`, used by every signed-in route as `auth:api`.
  Admins and customers share it.
- Default guard: `web`, unchanged.
- Spatie role guard name: `Role::GUARD` (`web`), pinned on
  `User::$guard_name`. It matches Laravel's default guard, so Spatie calls
  without a guard land on it too. Role checks compare names, so roles
  stored under `web` work for users signed in through `api`. Never pass a
  guard to `hasRole()`, and never type `'web'` for roles: use `Role::GUARD`.

**Scopes.** Each token carries one scope equal to its role name
(`Role::scope()`): `customer` or `admin`. Scopes are registered with
`Passport::tokensCan()` in `AppServiceProvider`.

**Middleware stack for signed-in groups:**

| Group | Middleware |
|---|---|
| Customer | `auth:api`, `scope:customer`, `role:customer`, `active`, `token.fresh` |
| Admin | `auth:api`, `scope:admin`, `role:admin`, `active`, `token.fresh` |

- `scope:` (Passport `CheckToken`) rejects a token without the scope: 403.
- `role:` (Spatie `RoleMiddleware`) rejects a user without the role: 403.
  Scope and role together mean a customer token never reaches admin routes
  even if a role were assigned by mistake.
- `active` (`EnsureUserIsActive`) rejects blocked, suspended or pending
  users with an existing token: 403.
- `token.fresh` (`EnsureTokenIsFresh`) rejects a token whose stored
  `expires_at` has passed (401) and revokes it; see below.
- Soft-deleted users are never found by the guard, so their tokens fail: 401.
- API guests get a 401 JSON response, never a redirect
  (`redirectGuestsTo(fn () => null)` in `bootstrap/app.php`).

**Lifetimes.** Per role in `config/auth.php` `token_lifetimes` (minutes;
`.env` `CUSTOMER_TOKEN_LIFETIME_MINUTES`, default 7 days, and
`ADMIN_TOKEN_LIFETIME_MINUTES`, default 2 days). Passport gives every
personal access token the same expiry (the longest lifetime, in the JWT
`exp` and the row). At sign-in, `AuthTokenService` overwrites the row's
`oauth_access_tokens.expires_at` with the role's real expiry and returns
the same value as `expires_at`; `EnsureTokenIsFresh` enforces it on every
request. The lifetime is fixed when the token is issued, so changing the
setting later does not affect tokens already handed out.

**Sign-in rules** (`App\Services\Accounts\AuthTokenService`):
- Wrong password, unknown email and an account of the other role all throw
  `InvalidCredentials` (401, one message), so the response never reveals
  whether an email exists or which role it has.
- Correct credentials on an inactive account throw `AccountNotActive` (403).
- Sign-out revokes only the current token.
- Registration (`CustomerRegistrationService`) creates an active customer
  from email and password only and returns no token; the customer signs in
  next.

**Keys.** `php artisan passport:keys` writes `storage/oauth-*.key`
(git-ignored). In production set `PASSPORT_PRIVATE_KEY` /
`PASSPORT_PUBLIC_KEY` instead. Tokens are issued from a personal access
client: `php artisan passport:client --personal --provider=users`.

**Tests.** Use `Tests\Concerns\IssuesRealTokens` (seeds roles and creates
the personal access client) to sign in through the real endpoints, or
`Passport::actingAs($user, [$role->scope()])` when sign-in is not under
test. Call `$this->app['auth']->forgetGuards()` before reusing a token in
the same test, so the guard re-checks it.

## 3. Suggested directory structure

app/
├── Data/
│   ├── CheckoutData.php
│   └── CapturedPaymentData.php
├── Enums/
│   ├── UserStatus.php
│   ├── OrderStatus.php
│   ├── PaymentStatus.php
│   └── PaymentMethod.php
├── Http/
│   ├── Controllers/
│   │   └── Api/
│   │       ├── Customer/      (incl. Auth/)
│   │       ├── Admin/         (incl. Auth/)
│   │       ├── Catalog/
│   │       └── Webhooks/
│   ├── Requests/
│   │   ├── Customer/      (incl. Auth/)
│   │   ├── Admin/         (incl. Auth/)
│   │   └── Checkout/
│   └── Resources/
├── Integrations/
│   └── Razorpay/
│       ├── RazorpayGateway.php
│       └── RazorpayWebhookVerifier.php
├── Jobs/
│   ├── SendOrderConfirmation.php
│   └── ReconcilePaymentAttempt.php
├── Models/
├── Policies/
├── Queries/
│   └── VariantListingQuery.php
├── Services/
│   ├── Accounts/
│   │   ├── CustomerRegistrationService.php
│   │   ├── CustomerProfileService.php
│   │   └── CustomerAddressService.php
│   ├── Catalog/
│   │   ├── ProductService.php
│   │   ├── ProductVariantService.php
│   │   ├── VariantPhotoService.php
│   │   └── TagService.php
│   ├── Cart/
│   │   └── CartService.php
│   ├── Checkout/
│   │   ├── CheckoutReviewService.php
│   │   └── CheckoutPaymentService.php
│   ├── Payments/
│   │   └── PaymentCaptureService.php
│   ├── Inventory/
│   │   └── InventoryService.php
│   └── Orders/
│       └── OrderTrackingService.php
└── Support/
    └── Money.php

There is no `Contracts/` folder for now: services depend on concrete
classes (section 26.2). An interface appears only as the shared type of a
strategy family (section 26.9), next to its strategies, for example
`app/Services/Shipping/ShippingCalculator.php`.

This is a suggested structure, not a requirement to create empty classes.

Reuse equivalent existing classes and directory conventions.

## 4. Controller pattern

Controllers coordinate HTTP concerns only.

Responsibilities:
- Receive validated input.
- Authorize the operation.
- Call the appropriate application service.
- Return an API Resource or HTTP response.

Controllers must not:
- Calculate authoritative checkout totals.
- Implement stock deduction.
- Call Razorpay directly.
- Contain multi-model transaction workflows.
- Mass-assign arbitrary request data.

Example (see section 27.3 for the DTO version):

final class OrderTrackingController extends Controller
{
    public function update(
        UpdateOrderTrackingRequest $request,
        Order $order,
        OrderTrackingService $service
    ): OrderResource {
        Gate::authorize('updateTracking', $order);

        $validated = $request->validated();

        return new OrderResource($service->updateTracking(
            $order,
            new UpdateTrackingData(
                provider: $validated['tracking_provider'],
                number: $validated['tracking_number'],
            ),
        ));
    }
}

The base controller has no `authorize()` helper; use `Gate::authorize()`.

## 5. Form Request pattern

Use one Form Request per meaningful write operation.

Examples:
- RegisterCustomerRequest
- StoreCustomerProfileRequest
- UpdateCustomerProfileRequest
- ChangePasswordRequest
- StoreCustomerAddressRequest
- UpdateCustomerAddressRequest
- StoreProductRequest
- UpdateProductVariantRequest
- AddCartItemRequest
- UpdateCartItemRequest
- ReviewCheckoutRequest
- StartCheckoutPaymentRequest
- UpdateOrderTrackingRequest

Validate:
- Required fields.
- Data types and bounds.
- String lengths.
- Decimal formats.
- Upload types and sizes.
- Allowed sort/filter values.
- Basic referenced-record existence.

Resource ownership must also be authorized or scoped.
An exists rule alone does not prove ownership.

Only pass validated, explicitly allowed data to services.

Example tracking rules:

public function rules(): array
{
    return [
        'tracking_provider' => ['required', 'string', 'max:100'],
        'tracking_number' => ['required', 'string', 'max:255'],

        'status' => ['prohibited'],
        'total_amount' => ['prohibited'],
        'customer_profile_id' => ['prohibited'],
    ];
}

Explicitly allowlist writable fields in the service as well.
If the API promises rejection of every unexpected key, implement a
consistent unknown-field check; validated() alone discards extra keys.

## 6. Authorization pattern

Use Spatie roles for role membership:
- admin
- customer

Use policies for resource-level permissions.

Suggested policies:
- CustomerProfilePolicy
- CustomerAddressPolicy
- CartPolicy
- ProductPolicy
- ProductVariantPolicy
- TagPolicy
- OrderPolicy

Rules:
- Customer resources (profile, addresses, cart, orders) belong to the
  authenticated customer.
- Admin can manage catalog resources.
- Admin can view all placed orders.
- Customer can view only their own orders.
- Admin may update order tracking only.
- Customer cannot update order tracking.
- Neither role gets unrestricted order update permissions.

Avoid a global admin bypass that automatically grants every policy action.

Prefer an explicit updateTracking ability over a generic update ability.

Do not trust user IDs or customer profile IDs supplied by the frontend
when the identity can be derived from the authenticated user.

## 7. Application service pattern

Application services own business workflows.

Examples:

CustomerRegistrationService:
- Create active user.
- Assign customer role.
- Keep profile creation separate.

CustomerProfileService:
- Create/update customer profile.
- Update users.name.
- Prevent duplicate profiles.

CustomerAddressService:
- Create/update addresses.
- Maintain exactly one default address.

ProductService:
- Update parent product.
- Deactivate child variants when parent becomes inactive.
- Cascade catalog deletion while preserving order history.

CartService:
- Create the customer's cart on first add.
- Add a variant (increase quantity if already present), change quantity,
  remove an item.
- Allow only eligible variants.
- Remove paid quantities when called by PaymentCaptureService.

CheckoutPaymentService:
- Validate checkout.
- Persist pending snapshots and payment attempt.
- Coordinate payment-link creation.

PaymentCaptureService:
- Confirm verified payment.
- Enforce idempotency.
- Confirm order.
- Deduct stock exactly once.
- Remove the paid quantities from the cart.
- Schedule durable confirmation notification.

OrderTrackingService:
- Update tracking provider and number only.

Services should not depend on the HTTP Request object.
Pass validated arrays, model instances, or small typed DTOs.

## 8. DTO usage

Use DTOs when they improve clarity across service or integration boundaries.

Good candidates:
- CheckoutData
- CapturedPaymentData
- CreatedPaymentLinkData

Do not create DTOs for every trivial CRUD request.

A normalized captured-payment DTO may contain:
- Provider payment ID.
- Provider order ID, when present.
- Currency.
- Amount in provider subunits.
- Captured state.
- Provider payment method.
- Verified event reference.

Only construct trusted payment-confirmation input after signature
verification and successful correlation.

A DTO organizes data; it does not replace validation or authorization.

## 9. Eloquent and query pattern

Use Eloquent directly inside services and dedicated query classes.

Do not introduce a generic BaseRepository or one repository per model
that only repeats Eloquent methods.

Add a repository only if a concrete requirement justifies it, such as
multiple persistence sources behind the same business operation.

Models should contain:
- Relationships.
- Casts.
- Small query scopes.
- Simple entity-level helpers.

Avoid payment workflows, email delivery, and external API calls
inside models.

Use VariantListingQuery for:
- Active parent/variant filtering.
- Positive stock filtering.
- Price range.
- Tag filtering.
- Sort order.
- Pagination.

Use eager loading for images, tags, and parent products to avoid N+1 queries.

Whitelist sortable columns and directions.
Never insert user-provided SQL identifiers directly into queries.

## 10. API Resource pattern

Use Resources for explicit public response shapes.

Suggested resources:
- CurrentUserResource
- CustomerProfileResource
- CustomerAddressResource
- CartResource
- ProductVariantListResource
- ProductVariantDetailResource
- CustomerOrderResource
- AdminOrderResource
- PaymentStatusResource

Separate customer/admin resources where exposure differs.

Never return complete Eloquent models blindly.

Do not expose:
- Password hashes.
- Tokens and secrets.
- Internal customer notes.
- Raw gateway_response.
- Sensitive provider metadata.

Return decimal money as strings.
Return dates in a consistent timezone-aware format.

Order resources must use stored snapshots for historical names,
prices, addresses, and customer details.

## 11. Transaction pattern

Use database transactions when multiple writes form one invariant.

Examples:
- User creation and role assignment.
- Name update and profile creation.
- Default-address replacement.
- Parent/child deactivation.
- Order confirmation, payment update, stock deduction and cart clearing.

The application service should own the transaction boundary.

Avoid hidden transaction ownership scattered across model observers.

Do not hold database locks while calling:
- Razorpay.
- Email providers.
- File storage APIs.

For default addresses:
Lock a stable customer/profile row before changing defaults.
This also handles concurrency when no address rows exist yet.

For stock:
Lock affected variants in a deterministic order.
Revalidate after locking.

Do not assume frontend checks prevent concurrent updates.

## 12. External payment adapter

Keep Razorpay SDK calls inside the concrete `RazorpayGateway` class.
Services inject `RazorpayGateway` directly through their constructors;
Laravel resolves it without any service-provider binding. There is no
`PaymentGateway` interface for now.

Expose only the operations actually required, such as:
- createPaymentLink
- fetchPayment
- fetchPaymentLink
- cancelPaymentLink

Read credentials from `config/services.php` (backed by `.env`) inside the
gateway, not in callers.

The adapter owns:
- Credentials and provider client setup.
- Provider request/response mapping.
- Decimal-to-provider-subunit conversion.
- Provider exception normalization.

Application services own:
- Which order is payable.
- Expected amount/currency.
- Whether a retry is permitted.
- Local state changes.

Do not implement Stripe/COD support merely because generic enum examples
appear in the schema. Razorpay is the current payment scope. If a second
provider is approved later, see section 26.9.

## 13. Payment initiation workflow

Recommended sequence:

1. Validate customer, profile, address, cart items, and prices.
2. In a short transaction:
   - Enforce idempotent checkout initiation.
   - Save pending order and immutable item/address snapshots.
   - Save pending payment attempt.
   - Reserve stock if the approved reservation design exists.
3. Commit.
4. Create the payment link through the gateway adapter.
5. Save returned provider identifiers in another short transaction.
6. Return the hosted payment URL.

Use a stable internal attempt reference for reconciliation.

If provider creation succeeds but local persistence fails:
Do not blindly create a second payment link.
Reconcile using the stable reference/provider retrieval.

The exact recovery metadata must be reflected in the schema.

## 14. Webhook processing pattern

Webhook controller responsibilities:
- Read the original request body.
- Verify Razorpay's signature.
- Parse the supported event.
- Pass normalized data to the capture service.
- Return the appropriate HTTP response.

Handle payment.captured as specified in the backend SRS.

Do not require customer authentication on the webhook route.
Do require valid provider authentication/signature.

Do not assume payment.captured contains a payment-link ID.
Correlate using verified provider identifiers and saved mappings.

Fetch provider data outside a locked database transaction if necessary.

Only acknowledge successful handling after:
- Processing has committed, or
- The event has been durably recorded for asynchronous processing.

Do not return success before an event is safely retained.

Duplicate events must be safe to acknowledge without repeating effects.

## 15. Payment capture transaction

Within one short transaction:

1. Lock the relevant payment attempt and order.
2. Validate the expected order/payment relationship.
3. Verify amount, currency, and captured state.
4. Check whether this payment was already processed.
5. Lock affected inventory rows in deterministic order.
6. Consume valid reservations and deduct stock once.
7. Mark payments.status = paid.
8. Save transaction_id and paid_at.
9. Move the pending order to confirmed.
10. Set confirmed_at.
11. Remove the paid quantities from the customer's cart.
12. Persist a deduplicated confirmation-notification task.

Do not use orders.status = paid.
That value is absent from the supplied ER diagram.

A second successful payment for an already paid order is a payment
exception, not an instruction to deduct stock or fulfill again.

Persist payment truth and route exceptional cases for reconciliation.

## 16. Inventory pattern

Centralize stock-changing operations in InventoryService.

Avoid stock decrements in:
- Controllers.
- Model observers.
- Email jobs.
- Multiple event listeners.

Confirmed business rules:
- Admin manages variant stock.
- Successful confirmation deducts purchased quantities.
- Stock <= 0 excludes a variant from listing.

Concurrency rule:
A pre-payment stock check alone cannot prevent overselling.
Cart items are not reservations.

Recommended solution:
- Atomic reservations before payment.
- Available quantity = stock minus active reservations.
- Consume reservation on capture.
- Release safely on failure/expiry.
- Prevent admin adjustments from invalidating reservations.

The ER diagram has no reservation structure.
Agree on this schema extension before implementing it.

Late capture after reservation expiry requires explicit exception handling.
Never allow negative stock as the normal overselling solution.

## 17. Order status transition pattern

Use explicit named operations for business transitions.

Examples:
- confirmFromCapturedPayment(...)
- updateTracking(...)

Do not expose a generic public setStatus(...) operation.

Existing order statuses:
- pending
- confirmed
- processing
- completed
- cancelled

Existing statuses do not automatically authorize UI controls or workflows.

Tracking updates must not silently move an order to completed.

If completion is implemented later:
- Define who/what triggers it.
- Require tracking_number.
- Record completed_at.
- Test allowed source states.

Do not add cancellation/refund workflows until requested.

## 18. Jobs, events and notifications

Use queued jobs for:
- Order-confirmation email.
- Payment reconciliation.
- Other approved asynchronous work.

Use domain events only when multiple independent consumers benefit.
Do not add events merely to move a direct method call elsewhere.

Never rely on an asynchronous email listener to perform required
payment or stock changes.

Dispatch only after commit.

For reliable confirmation emails:
Use an approved durable outbox or equivalent deduplicated notification
record committed with the order.

afterCommit alone prevents early dispatch but does not eliminate the
crash window between commit and queue delivery.

Jobs must tolerate retries.
Use stable deduplication keys such as order ID plus notification type.

Exactly-once external email delivery cannot be assumed; use provider
idempotency where supported and minimize duplicate scheduling.

## 19. Model observer policy

Do not use observers for critical business workflows:
- Capturing payments.
- Deducting inventory.
- Sending order confirmations.
- Cascading activation.
- Changing order statuses.

These operations need explicit orchestration and transaction boundaries.

Observers may be used for minor local behavior only when the repository
already follows that convention and the behavior is documented.

Documented exception: `User::booted()` soft-deletes and restores the
customer profile together with the user, so every delete path (services,
Tinker, future code) keeps them in step. It only mirrors the soft-delete
flag; it does no other work.

## 20. Money handling

Preserve the ER diagram's decimal money columns.

Agree on precision and scale in migrations.

Use exact decimal arithmetic.
Never calculate authoritative money using PHP floats.

Keep calculations centralized:

Item subtotal = selling unit price × quantity
Item total = subtotal − item discount

Order total = subtotal − aggregate discount + shipping

Do not subtract the original-to-selling-price reduction twice.

Store immutable price snapshots on order items.
Convert to currency subunits only when calling Razorpay.
Validate currency-specific precision during conversion.

## 21. File storage

Use Laravel Storage for variant images and avatars.

Rules:
- Validate content/type/size.
- Generate safe storage paths.
- Do not trust original filenames.
- Do not store file bytes in database columns.
- Clean up abandoned uploads.
- Preserve historical order image references.

Database transactions do not roll back remote storage writes.
Use explicit cleanup/compensation for failed operations.

Do not delete an image still required by order history.

## 22. Error handling and logging

Use consistent API errors with:
- HTTP status.
- Stable application error code where useful.
- Human-readable message.
- Field errors for validation.

Examples:
- Insufficient stock.
- Variant unavailable.
- Checkout price changed.
- Payment confirmation pending.
- Payment amount mismatch.

Do not return raw provider exceptions or stack traces to customers.

Log correlation identifiers:
- Internal order number.
- Payment attempt ID.
- Provider payment/link/order ID.
- Webhook event reference.

Never log:
- Passwords.
- API secrets.
- Authorization headers.
- Full card data.
- Password-reset tokens.

Apply retention and redaction to stored gateway payloads.

## 23. Testing strategy

Test behavior and failure boundaries.

Feature tests:
- Role and ownership enforcement.
- Profile onboarding.
- Default-address concurrency.
- Cart ownership, merge of repeated adds, and eligibility.
- Listing filters and eligibility.
- Tracking-only order edits.
- Historical snapshots after catalog deletion.

Payment tests:
- Invalid signature rejection.
- Amount/currency mismatch.
- Duplicate and concurrent capture.
- Provider timeout during initiation.
- Provider success followed by local save failure.
- Browser closure before webhook.
- Duplicate successful charge.
- Email scheduling after commit.

Inventory tests:
- Competing purchases for limited stock.
- Duplicate capture does not decrement twice.
- Reservation expiry.
- Late capture.
- Admin stock changes during reservations.

Never call Razorpay from application tests. Without an interface, swap the
concrete gateway in the container instead:
- `$this->mock(RazorpayGateway::class, fn ($mock) => ...)` for expectations, or
- `$this->app->instance(RazorpayGateway::class, $fake)` with a small fake
  subclass under `tests/`.

Use database-backed tests for transaction and locking behavior.
Do not treat SQLite tests as proof of MySQL concurrency behavior.

## 24. Patterns to avoid

Do not introduce:
- Generic BaseService/BaseRepository layers with no concrete purpose.
- A repository for every Eloquent model.
- One giant OrderService handling all domains.
- A generic CRUD endpoint for orders.
- Event sourcing or microservices for this scope.
- Business logic hidden in model observers.
- External network requests while holding inventory locks.
- Frontend-only authorization or payment confirmation.
- Blind provider retries that can create duplicate payable links.
- Interface-to-class bindings in service providers (for now; section 26.2).
- A strategy/factory for a behavior that has only one implementation.

## 25. Claude implementation checklist

Before coding:
1. Read the SRS and ER diagram.
2. Inspect the current repository.
3. Identify existing authentication and API conventions.
4. List schema gaps affecting the requested feature.
5. Resolve affected decisions before implementing them.

During coding:
1. Add request validation and policies.
2. Implement focused application services.
3. Keep controllers thin.
4. Serialize responses explicitly.
5. Use short transactions and appropriate row locks.
6. Keep provider operations behind the adapter.
7. Implement idempotency for payment side effects.
8. Preserve snapshots and decimal money.
9. Update the ER diagram with approved schema changes.
10. Test meaningful business and failure scenarios.

Deliver:
- Implemented code.
- Approved migrations and model changes.
- Updated ER diagram where needed.
- Relevant tests.
- API contract changes.
- Remaining assumptions and incomplete requirements.

## 26. Low-level design principles and patterns

### 26.1 Single Responsibility Principle

Each class should have one clear responsibility.

Examples:
- CheckoutReviewService: validate and calculate checkout.
- CheckoutPaymentService: initiate payment.
- PaymentCaptureService: process verified capture.
- InventoryService: manage stock and reservations.
- CartService: manage the customer's cart.
- OrderTrackingService: update tracking details.
- RazorpayGateway: communicate with Razorpay.

Split a service when unrelated workflows make it difficult to understand
or test. Do not create a class for every trivial statement.

### 26.2 Dependency injection with concrete classes

Inject dependencies through constructors.

For now, type-hint concrete classes. Laravel's container builds them
automatically, so no service-provider binding is needed.

Example:

final class CheckoutPaymentService
{
    public function __construct(
        private RazorpayGateway $gateway,
        private CheckoutReviewService $reviewService,
    ) {}
}

Rules:
- Do not create interfaces for services or the payment gateway yet.
- Do not add `bind()` / `singleton()` calls that map an interface to a
  class in `AppServiceProvider` or a new provider.
- Do not instantiate the Razorpay SDK inside controllers or business
  services, and do not `new` up services by hand; let the container
  inject them.
- In tests, swap concrete classes with `$this->mock()` or
  `$this->app->instance()` (section 23).

Revisit this only when a second real implementation exists. Even then,
prefer the strategy + simple factory in section 26.9 over a container
binding.

### 26.3 Adapter Pattern

RazorpayGateway adapts Razorpay's SDK/API to the application's own
methods and DTOs. It is a concrete class (no interface for now).

Responsibilities:
- Translate application input into provider requests.
- Convert provider responses into application DTOs.
- Normalize provider errors.
- Keep provider-specific field names outside core business workflows.

Do not return raw SDK entities from the adapter into controllers.

### 26.4 Application Service Pattern

Expose business operations through clearly named methods.

Prefer:

- createProfile(...)
- setDefaultAddress(...)
- addToCart(...)
- deactivateProduct(...)
- reviewCheckout(...)
- initiatePayment(...)
- confirmCapturedPayment(...)
- updateTracking(...)

Avoid generic methods such as:

- handleEverything(...)
- processData(...)
- updateAnyModel(...)
- changeStatus(...) with unrestricted status input

Methods should describe the business intent and enforce its rules.

### 26.5 DTO Pattern

Use immutable DTOs for structured input crossing application boundaries.

Use:
- Scalars for a small number of simple arguments.
- DTOs for related fields passed together.
- Eloquent models where an existing persisted entity is required.

Example:

final readonly class UpdateTrackingData
{
    public function __construct(
        public string $provider,
        public string $number,
    ) {}
}

Avoid passing unstructured arrays through multiple service layers.

A DTO does not replace request validation, authorization, or business
invariant checks.

### 26.6 Value Object Pattern

Use value objects where values have important invariants or behavior.

Good candidates:
- Money: decimal amount and currency.
- ShippingAddressSnapshot: immutable delivery details.
- CheckoutTotals: subtotal, discount, shipping and total.

For a Money value object:
- Use exact decimal arithmetic.
- Reject unsupported precision.
- Prevent arithmetic across different currencies.
- Convert to provider subunits explicitly.

A value object must not perform database queries or external API calls.

Do not wrap every primitive value in a class without a practical benefit.

### 26.7 Query Object Pattern

Use focused query classes for complex read operations.

Example:

VariantListingQuery:
- Applies catalog eligibility.
- Applies price and tag filters.
- Applies approved sorting.
- Eager-loads required relationships.
- Returns paginated results.

Keep query objects free of write operations and payment side effects.

### 26.8 Explicit State Transitions

Represent status values using enums matching the database.

Enforce transitions through named business operations.

Example:
confirmCapturedPayment(...) may move a pending order to confirmed
after verifying payment and inventory conditions.

Do not allow arbitrary status assignment from HTTP input.

Use a small transition map or explicit guards first.
Introduce separate State-pattern classes only if transition behavior
becomes complex enough to justify them.

Do not implement transitions for processing, completed, or cancelled
until their triggers and rules are defined.

### 26.9 Strategy + Simple Factory — only when behavior varies at runtime

Use this pair when the same operation has several real implementations
and the right one is chosen at runtime from a stored key, such as a
slug, type or provider name (`payments.provider`, a shipping-method
slug, a discount type).

- Strategy: one small interface for the operation, and one class per
  variant implementing it. This interface is the shared type of the
  family; it is not bound in a service provider.
- Simple factory: one class with a `for(string $key)` (or enum) method
  that maps the key to a strategy class with a `match` expression and
  builds it through the container, so strategies keep constructor
  injection. An unknown key throws a clear domain exception; never
  resolve a class name taken directly from input.

Example shape (for when a second shipping rule is approved):

interface ShippingCalculator
{
    public function calculate(CheckoutTotals $totals, ShippingAddressSnapshot $address): Money;
}

final class ShippingCalculatorFactory
{
    public function __construct(private Container $container) {}

    public function for(string $method): ShippingCalculator
    {
        return $this->container->make(match ($method) {
            'standard' => StandardShipping::class,
            'express' => ExpressShipping::class,
            default => throw new UnsupportedShippingMethod($method),
        });
    }
}

Services inject the factory (a concrete class) and call
`$factory->for($order->shipping_method)`.

Rules:
- Only introduce it when at least two approved variants exist. The
  current scope has one payment provider (Razorpay) and no approved
  shipping or discount rules, so none of these exist yet.
- Keys must be values the backend stores or validates (enum or allowlist).
- Keep the mapping in the factory only; do not spread `if/switch` on the
  same key across services.
- A new variant means a new strategy class plus one `match` arm, with a
  test for each key and for the unknown-key error.
- Do not add a registry, config-driven class lists or service-provider
  tags for this; the `match` is the registry.

### 26.10 Prefer Composition Over Inheritance

Compose services from focused collaborators.

Avoid deep inheritance chains such as:
BaseService → CrudService → OrderService → PaidOrderService.

Use shared helpers or composed services for genuinely shared behavior.
Do not create generic base classes merely to reduce a few repeated lines.

## 27. Method parameters: keep HTTP Request at the boundary

### 27.1 Core rule

Request and FormRequest objects belong in the HTTP layer.

They may be accepted by:
- Controllers.
- Middleware.
- Form Requests and HTTP-specific validation components.

Do not pass Request or FormRequest objects into:
- Application services.
- Query objects.
- Payment adapters.
- Strategies and factories.
- Jobs.
- Domain events.
- Model business methods.

These classes must receive explicit application data.

This allows the same operation to be called safely from:
- HTTP controllers.
- Queue jobs.
- Console commands.
- Automated tests.

### 27.2 Avoid

public function updateTracking(Request $request, Order $order): Order
{
    $order->update($request->all());

    return $order;
}

Problems:
- Business code depends on HTTP.
- Accepted fields are unclear.
- Mass assignment can modify unintended fields.
- Tests must construct HTTP requests unnecessarily.

### 27.3 Preferred controller-to-service flow

The controller reads validated input and constructs a DTO:

final class OrderTrackingController extends Controller
{
    public function update(
        UpdateOrderTrackingRequest $request,
        Order $order,
        OrderTrackingService $service,
    ): OrderResource {
        Gate::authorize('updateTracking', $order);

        $validated = $request->validated();

        $data = new UpdateTrackingData(
            provider: $validated['tracking_provider'],
            number: $validated['tracking_number'],
        );

        return new OrderResource(
            $service->updateTracking($order, $data)
        );
    }
}

The service accepts only the required data:

final class OrderTrackingService
{
    public function updateTracking(
        Order $order,
        UpdateTrackingData $data,
    ): Order {
        $order->tracking_provider = $data->provider;
        $order->tracking_number = $data->number;
        $order->save();

        return $order;
    }
}

Add business guards and approved audit persistence where required.
The example demonstrates method boundaries, not the full workflow.

### 27.4 Use scalars for simple operations

A DTO is unnecessary when one or two arguments clearly express the intent.

Example signature:

public function setDefaultAddress(
    CustomerProfile $customer,
    int $addressId,
): CustomerAddress;

The implementation must resolve the address within that customer's
addresses and apply the default change atomically.

Do not assume that receiving an ID proves ownership.

### 27.5 Pass the actor explicitly when needed

Do not call auth() or request() inside business services.

If the operation needs the acting user for ownership or auditing,
pass the User or a clearly named actor ID explicitly.

Example:

public function updateProfile(
    User $customer,
    UpdateCustomerProfileData $data,
): CustomerProfile;

Do not accept the actor identity from an untrusted request body.
Derive it from the authenticated context in the controller.

### 27.6 Webhooks follow the same boundary rule

The webhook controller reads:
- Raw request body.
- Signature header.

Pass those explicit values to the verifier:

public function verify(
    string $rawBody,
    string $signature,
): void;

After verification and correlation, pass a normalized DTO to the
payment capture service.

Never pass the entire HTTP Request to PaymentCaptureService.

### 27.7 Jobs receive stable identifiers

Prefer job constructor arguments such as:

public function __construct(
    public readonly int $orderId,
) {}

Reload required records when the job runs.
Do not serialize HTTP requests, authentication sessions, SDK clients,
or unnecessary sensitive payloads into jobs.

### 27.8 Additional method-design rules

- Name injected classes after their type (see section 29.3).
- Declare parameter and return types.
- Prefer named business methods over boolean mode flags.
- Avoid long positional parameter lists; group related fields in a DTO.
- Avoid generic array parameters when their shape is important.
- Do not use global request() access to hide a dependency.
- Do not use request()->all() for persistence.
- Keep constructors for dependency assignment, not database/API work.
- Return explicit models, DTOs, collections, or result types.
- Keep HTTP response creation inside controllers/resources.

## 28. API documentation (Scramble)

API docs are generated by Scramble (`dedoc/scramble`) from the code itself:
routes, Form Request rules, API Resources and return types. No OpenAPI
annotations are written by hand.

### 28.1 Document

There is one document for the whole API: everything under `api/v1`
(`routes/api.php`, `routes/api/customer.php`, `routes/api/admin.php`).
Admin endpoints appear under `/admin/...` in the same document.

- UI: `/docs/api`. JSON: `/docs/api.json`.
- Configured in `config/scramble.php` (`api_path` is `api/v1`, plus title
  and description). There is no code setup in service providers.
- `php artisan scramble:export` writes the spec to
  `docs/api/openapi.json` (the `export_path` in `config/scramble.php`).
  This file is committed: it is the API contract the frontend reads.
  Re-export it in the same change as any route, Form Request, Resource or
  enum change, so it never lags behind the code.
- A future `api/v2` can be added to `api_path` or given its own document
  when it exists.

### 28.2 Access

The docs pages are open only in the `local` environment. Anywhere else
they return 403 unless a `viewApiDocs` gate allows the user; define that
gate (for example, admins only) before exposing docs on a shared server.
Never make them public in production.

### 28.3 Writing code that documents well

Scramble can only describe what the code states. Follow the rest of this
guide and the docs stay accurate:
- Validate every input in a Form Request (section 5); its `rules()` become
  the request schema, including query parameters for listing endpoints.
- Return API Resources with declared return types (section 10); they
  become the response schema. Avoid returning arrays or models directly.
- Use enums for status fields (section 26.8); Scramble lists their values.
- Give each controller method a one-line docblock summary; it becomes the
  endpoint title. Add a longer description only where behavior is not
  obvious (for example, "tracking only; other fields are rejected").
- Throw the standard exceptions (validation, authorization, not found) so
  error responses are documented automatically.
- Run `php artisan scramble:analyze` after adding endpoints to catch
  anything it could not infer.

### 28.4 Authentication in the docs

Every endpoint is documented as needing a Bearer token (set in
`AppServiceProvider::configureApiDocs()`). Mark endpoints that need none,
such as public catalog routes and sign-in/registration, with
`@unauthenticated` in the controller method's docblock. Document error
responses by throwing (or declaring with `@throws`) Laravel's exception
types or subclasses of them, e.g. `InvalidCredentials` extends
`AuthenticationException` (401) and `AccountNotActive` extends
`AuthorizationException` (403).

## 29. Naming conventions

One place for how things are named. Follow the existing examples; when a
new kind of class appears, add its rule here.

### 29.1 Classes

| Kind | Folder | Pattern | Examples |
|---|---|---|---|
| Controller | `Http/Controllers/Api/{Customer,Admin,Catalog,Webhooks}/...` | `{Thing}Controller`, one per resource or action group | `SessionController`, `RegisterController` |
| Form Request | `Http/Requests/{Customer,Admin}/...` (mirrors the controller folder) | `{Action}Request` | `LoginRequest`, `RegisterRequest` |
| API Resource | `Http/Resources` | `{Model or audience}Resource`; a wrapper for another payload is `{Thing}{Payload}Resource` | `CustomerResource`, `AdminTokenResource` |
| Middleware | `Http/Middleware` | `Ensure{Condition}` (what must be true to pass) | `EnsureUserIsActive`, `EnsureTokenIsFresh` |
| Middleware alias | `bootstrap/app.php` | short, lowercase, dot for qualifiers | `active`, `token.fresh`, `role`, `scope` |
| Service | `Services/{Domain}` | `{Purpose}Service`, `final class` | `CustomerRegistrationService`, `AuthTokenService` |
| DTO | `Data` | a noun for what it holds, `final readonly class` | `IssuedToken` |
| Enum | `Enums` | singular noun, string-backed; cases in PascalCase, values snake_case | `UserStatus::Active` = `'active'` |
| Exception | `Exceptions/{Domain}` | the situation, no `Exception` suffix; extend the Laravel exception that gives the right status | `InvalidCredentials`, `AccountNotActive` |
| Query object | `Queries` | `{Audience}{Thing}Query`, `final class`, read-only (section 26.7) | `AdminCustomerQuery` |
| List filters | `Data` | `{Thing}ListFilters`, `final readonly class`, built by the list Form Request's `toFilters()` | `CustomerListFilters` |
| Sort options | `Enums` | `{Thing}Sort`, cases are the allowed `sort` values | `CustomerSort` |
| Console command | `Console/Commands` | class in PascalCase; signature `{area}:{action}` | `CreateAdmin` / `admin:create` |
| Model | `Models` | singular; table is the snake_case plural | `CustomerAddress` / `customer_addresses` |

### 29.2 Methods

- Name service methods after the business intent: `register()`,
  `signIn()`, `signOut()`, `createAdmin()`; not `handle()`, `process()`
  or `update()` with a mode flag (section 26.4).
- Booleans read as questions: `isActive()`, `isAdmin()`,
  `hasCompletedProfile()`.
- Controller actions use Laravel's resource names: `index`, `show`,
  `store`, `update`, `destroy`.

### 29.3 Variables and parameters

- Name an injected service or class after its type, in camelCase:
  `CustomerRegistrationService $customerRegistrationService`,
  `AuthTokenService $authTokenService`,
  `AdminAccountService $adminAccountService`. Avoid short names like
  `$auth`, `$registration` or `$accounts`: they read like data and can be
  confused with helpers such as `auth()`.
- Name models and data after what they are in this context, not their
  class: `$customer`, `$admin` (both `User`), `$issuedToken`.
- Use `$request` for the Form Request in controllers.

### 29.4 Routes and API

- Route names: `{api|customer|admin}.v1.{area}.{action}`, e.g.
  `customer.v1.auth.login` (section 2.1).
- URLs: lowercase, hyphenated, plural resources: `/customer/addresses`,
  `/auth/forgot-password`.
- JSON fields, request fields and query parameters: snake_case, matching
  the database columns (`expires_at`, `profile_completed`).

### 29.5 Database

- Tables: snake_case plural (`customer_profiles`); pivot tables follow the
  ER diagram (`product_variant_tags`).
- Columns: snake_case; booleans start with `is_` or `has_` (`is_default`);
  timestamps end with `_at` (`confirmed_at`).
- Status-like columns are strings backed by an enum in `app/Enums`.
