# Artistic Hub — Backend Software Requirements Specification

Version: 1.1 (aligned with the codebase on 2026-10-01)
Platform: Candle e-commerce
Backend: Laravel 13 (`Artistic-hub-backend/`)
Authorization: spatie/laravel-permission
Payment provider: Razorpay

## 1. Purpose and authority

Implement the backend for a candle store with Admin and Customer roles.

The ER diagram at `docs/database/er-diagram.md` is the database source of truth.
Migrations in database/migrations and models in app/Models must
match it. Update the ER diagram in the same change as any approved
schema modification.

These requirements supersede conflicting assumptions in the earlier
PRD (`docs/candle-ecommerce-prd.md`). The PRD still applies where this
document is silent.

Distinguish:
- Confirmed requirements: behavior explicitly requested below.
- Proposed implementation decisions: suggestions requiring agreement
  before implementing affected features.

Do not silently add tables, columns, enum values, payment methods,
order-management capabilities, or business policies.

Approved schema changes since version 1.0:
- 2026-09-30: `carts` and `cart_items` added to the ER diagram (section 9).

## 2. Roles and authorization

Exactly two roles:
- admin
- customer

Use Spatie roles and model_has_roles. Do not add a role column to users.

Seed/provision the initial admin securely. There is no public
admin-registration endpoint.

Public registration always assigns customer. Reject or ignore
client-supplied roles and account-status fields.

All customer resources must be scoped to the authenticated customer.
Admin endpoints must enforce the admin role on the backend.

A customer must never access another customer's profile,
addresses, cart, orders, payments, or checkout status.

Admin order mutations are restricted to:
- tracking_provider
- tracking_number

Admin cannot manually change order items, totals, address, payment
status, order status, or customer assignment.

## 3. Authentication

### BE-AUTH-01: Registration

Input:
- email
- password
- password_confirmation

Requirements:
- Validate and normalize email consistently.
- Enforce unique email.
- Hash passwords.
- Create an active customer account automatically.
- Do not require admin approval.
- Do not create a completed customer profile at registration.
- Customer signs in and then creates their profile.
- Do not expose passwords or password hashes in responses.

Schema decision:
users.name exists, but registration collects only email/password.
Before implementation, agree how users.name is represented until
profile creation. Recommended: nullable until onboarding is complete,
with the migration and ER diagram updated together.

Do not invent a fake customer name from the email.

### BE-AUTH-02: Sign in and sign out

Both roles sign in using email/password.

Return authenticated user information, roles, account status,
and a derived profile-completion indicator.

Only active accounts can sign in.
Blocked, suspended, or pending accounts must not gain access.

Authentication mechanism: not yet chosen. The frontend currently sends
a Bearer token in the Authorization header (Sanctum API tokens would fit).
Agree on Sanctum token or cookie authentication before implementing
the frontend contract.

### BE-AUTH-03: Password management

Both roles can:
- Request a forgot-password email.
- Reset their password with a valid, expiring, single-use token.
- Update their password while signed in.

Authenticated password changes require:
- current_password
- password
- password_confirmation

Return a neutral forgot-password response regardless of whether
the email exists. Rate-limit authentication and reset requests.

Password change must be a dedicated operation, even when its form
appears inside the profile page.

## 4. Customer profile

### BE-PROFILE-01: Initial creation

After the customer's first sign-in, allow profile creation.

Use:
- users.name for the customer's name.
- customer_profiles for phone, date_of_birth, gender, avatar_path,
  and other approved profile fields.

customer_profiles.user_id must remain unique.

Save the user name and profile atomically.
Repeated submissions must not create duplicate profiles.

Proposed minimum onboarding fields:
- name: required
- phone: required
- date_of_birth, gender, avatar: optional

Confirm these field requirements before implementation.

Require a completed profile before checkout. Because carts belong to
`customer_profiles`, a customer also needs a profile before items can
be saved to their server cart.

### BE-PROFILE-02: Profile updates

Allow customers to update their own approved profile fields.

Do not allow customers to modify:
- user_id
- role
- status
- email_verified_at
- internal notes

Treat customer_profiles.notes as internal by default and do not expose
an editing capability unless requested.

Email changes are outside the confirmed scope.

Validate uploaded avatar type and size, if avatar upload is implemented.

## 5. Addresses

Customers can create and update multiple addresses belonging to
their own customer_profile.

Fields follow CUSTOMER_ADDRESSES in the ER diagram.

Require shipping fields needed to deliver an order:
- recipient_name
- phone
- address_line_1
- city
- state
- postal_code
- country

address_line_2 is optional.

Default-address behavior:
- Exactly one default address when a customer has saved addresses.
- The first address becomes default automatically.
- Making another address default clears the previous default.
- Perform this change atomically, including concurrent requests.
- A customer cannot make another customer's address their default.

Address deletion is not required by the latest scope.

Editing a saved address must never alter historical order snapshots.

## 6. Product and variant management

### BE-CATALOG-01: Products

Admin can create, update, activate, deactivate, and delete products.

Use PRODUCTS fields from the ER diagram, including unique slug.

Products group variants; variants are the purchasable entities.

### BE-CATALOG-02: Variants

Admin can create, update, activate, deactivate, and delete variants.

Use PRODUCT_VARIANTS fields:
- product_id
- name
- sku
- slug
- original_price
- selling_price
- stock
- description
- is_active

Validate:
- Existing parent product.
- Unique SKU and slug.
- Valid decimal prices.
- Non-negative integer stock.
- Selling price must be positive for checkout.

Whether selling_price may exceed original_price requires an explicit
pricing decision; do not infer it from field names alone. (The admin
frontend currently rejects a selling price above the original price.)

### BE-CATALOG-03: Visibility

A variant is eligible for the customer listing only when:
- Its product exists and is active.
- The variant exists and is active.
- stock > 0.

Exclude variants with stock <= 0 even if legacy data contains
negative stock.

Do not permit purchases through direct URLs that bypass these rules.

Deactivating a product deactivates all child variants.
A child cannot be activated while its parent is inactive.

Proposed reactivation rule:
Reactivating the product does not automatically reactivate variants.

### BE-CATALOG-04: Deletion and history

Deleting a product deletes its variants from the catalog.
Deleting variants must not delete historical ORDER_ITEMS.

Use nullable order_items.product_variant_id with null-on-delete
behavior where physical deletion is used.

Deleting a variant removes it from every cart (cart_items cascade on
delete).

Order history must use snapshots, never current catalog values.

Retain historical image assets or copy them to durable order storage
so deleting a catalog photo does not break a purchased-item image.

Do not introduce soft-delete columns without updating the ER diagram.

## 7. Variant images and tags

### BE-IMAGE-01

A variant can have multiple PRODUCT_VARIANT_PHOTOS.

Admin can upload, remove, and reorder images.

Use sort_order for display ordering.
Proposed primary image: the image with the lowest sort_order,
then lowest ID as a deterministic tie-breaker.

No primary-image column exists in the supplied schema.

Validate file types and sizes. Store generated safe filenames.
Never trust uploaded filenames as storage paths.

The admin frontend accepts JPG, PNG and WebP up to 5 MB, at most 8
photos per variant, and sends an alt text per photo. PRODUCT_VARIANT_PHOTOS
has no alt-text column: agree whether to add one or drop it from the UI.

### BE-TAG-01

Admin can create, update, and delete tags.

Tag names and slugs are unique according to the ER diagram.

Attach/detach tags through PRODUCT_VARIANT_TAGS.
Enforce unique product_variant_id/tag_id pairs.

Deleting a tag removes associations, not variants.

The admin frontend creates, renames and deletes tags from the product
form. Create and rename send `{ name }`; the backend generates the slug
and returns 422 with a message when the name or slug is already taken.

## 8. Customer variant listing

Support:
- Pagination.
- Minimum and maximum selling price.
- Filtering by attached tag IDs.
- Sort newest.
- Sort price low to high.
- Sort price high to low.

Price range is a filter, not a sort mode.

Proposed query contract:
GET /api/v1/variants
    ?min_price=200.00
    &max_price=1000.00
    &tag_ids[]=1
    &tag_ids[]=3
    &sort=price_asc
    &page=1

Sort values:
- newest
- price_asc
- price_desc

Use variant.created_at for newest, with ID as a tie-breaker.
Use selling_price for price filtering and sorting.

Proposed multiple-tag behavior:
Match any selected tag; combine tag and price filters with AND.

Return only eligible variants.
Reject invalid price ranges and unsupported sort values.

Details must identify the exact variant and include:
- Parent product information.
- Variant information and stock.
- Ordered images.
- Attached tags.
- Selling/original prices.

## 9. Cart and checkout

### BE-CART-01: Server cart

Approved 2026-09-30: the ER diagram has `carts` and `cart_items`.

- Each customer has at most one cart (`carts.customer_profile_id` is unique).
  Create it on the first add.
- A cart item is a variant and a positive integer quantity. A variant
  appears once per cart (unique `cart_id` + `product_variant_id`);
  adding it again increases the quantity.
- Cart items store no prices. Every cart response shows current
  selling/original prices and marks items that are no longer eligible
  (section 8) or exceed available stock, without silently removing them.
- Customers can view the cart, add a variant, change a quantity and
  remove an item.
- Only eligible variants can be added.
- After verified payment, remove only the quantities that were paid for;
  items added to the cart afterwards stay.

Open: whether signed-out visitors keep a browser cart that is merged into
the server cart on sign-in, or must sign in before adding to cart. Until
decided, the frontend's browser cart remains a stand-in.

### BE-CHECKOUT-01: Review

Accept:
- Selected customer address ID.

Read the items and quantities from the customer's cart; do not accept
an item list from the client.

Backend must:
- Check address ownership.
- Check profile completion.
- Reject an empty cart.
- Reload current product/variant eligibility.
- Validate quantities against available stock.
- Calculate prices and totals using trusted data.
- Return a review response for customer confirmation.

Never trust client totals, discounts, shipping fees, or customer identity.

If price or availability changed, require renewed review.

### BE-CHECKOUT-02: Snapshots and totals

Persist immutable customer, address, item, and price snapshots.

Use decimal arithmetic or exact decimal utilities, never binary
floating-point arithmetic for money.

Keep money columns decimal as specified by the ER diagram.
Convert to Razorpay currency subunits only at the provider boundary.

Formula:
order_items.subtotal = selling_unit_price * quantity
order_items.total_amount = subtotal - discount_amount

orders.subtotal = sum(order_items.subtotal)
orders.total_amount =
    subtotal - discount_amount + shipping_amount

Define orders.discount_amount as the aggregate discount applied
against orders.subtotal. Do not subtract item discounts twice.

The difference between original_unit_price and selling_unit_price
must not be deducted again if subtotal already uses selling_unit_price.

Currency, shipping charges, and any tax policy must be confirmed
before production checkout. (The shop frontend currently shows a
free-shipping threshold and a tax line; neither is a confirmed policy.)

## 10. Razorpay and order confirmation

### BE-PAY-01: Start payment

Create an internal pending order and pending payment attempt before
redirecting the customer to Razorpay.

This pending order supports correlation; it is not yet a confirmed
customer purchase.

Proposed state before payment:
- orders.status = pending
- payments.status = pending
- orders.confirmed_at = null

Allow multiple payment attempts per order while preventing duplicate
concurrent payable attempts.

Proposed hosted integration: Razorpay Payment Links.
Verify current provider documentation during implementation.

Suggested field mapping:
- payments.provider = razorpay
- payments.payment_session_id = Razorpay payment link ID
- payments.transaction_id = Razorpay payment ID after confirmation
- payments.amount/currency = expected payment amount/currency

Before payment, payments.method is unknown.
Make it nullable or agree on an explicit unknown value through a
documented schema change. Do not falsely default to card.

### BE-PAY-02: Captured webhook

Handle Razorpay payment.captured as the payment-confirmation event.

Requirements:
1. Verify webhook signature using the raw request body.
2. Resolve the payment to a saved attempt/order.
3. Check provider payment ID, captured state, amount and currency.
4. Process confirmation in a database transaction.
5. Mark payments.status = paid and save paid_at.
6. Save transaction_id.
7. Change orders.status from pending to confirmed.
8. Set confirmed_at.
9. Deduct purchased stock exactly once.
10. Remove the paid quantities from the customer's cart (BE-CART-01).
11. Schedule one order-confirmation email after successful commit.

Important:
The ER diagram does not have orders.status = paid.
“Paid order” means a verified paid payment associated with a
confirmed order. Do not add a paid order-status enum silently.

Do not assume payment.captured includes the payment-link ID.
Implement verified correlation using provider order/payment mappings,
provider retrieval where necessary, and trusted saved references.

Persist any required new provider-order mapping explicitly and update
the diagram when adding a column.

### BE-PAY-03: Idempotency and recovery

Duplicate/concurrent webhooks must not:
- Deduct stock twice.
- Confirm duplicate orders.
- Clear cart quantities twice.
- Schedule duplicate confirmation emails.

Do not confirm payment based on browser redirect parameters alone.

A closed browser must not prevent order confirmation.
A redirect arriving before the webhook must return a pending state.

Reconcile ambiguous provider timeouts before creating another attempt.
Handle failed/expired attempts without creating a confirmed order.

Unexpected second successful payments require an operational exception;
they must not trigger duplicate fulfillment.

Proposed schema additions for reliable processing:
- Webhook event deduplication records.
- Durable notification/outbox records.
- Provider correlation metadata where existing columns are insufficient.

These are schema gaps, not part of the supplied ER diagram.
Document and agree on additions before implementation.

## 11. Stock consistency

Confirmed business behavior:
- Admin maintains variant stock.
- Successful order confirmation deducts purchased quantities.
- Stock <= 0 makes a variant ineligible for listing.

A pre-payment stock check alone cannot prevent overselling during
concurrent Razorpay sessions.

Proposed production approach:
- Reserve quantities atomically when payment begins.
- Available-to-purchase = stock minus active reservations.
- Do not decrease physical stock until verified payment confirmation.
- On capture, consume reservation and deduct stock once.
- On expiry/failure, safely release the reservation.
- Lock affected rows in deterministic order.
- Prevent admin stock edits from invalidating active reservations.

The ER diagram has no reservation structure (cart items are not
reservations). Agree on the reservation schema and expiry/late-capture
policy before implementing production checkout.

For a payment captured after reservation expiry:
Record payment truth, stop automatic fulfillment if stock is insufficient,
and raise an operational exception. Do not silently oversell or ignore
the successful charge.

## 12. Orders and admin views

### BE-ORDER-01: Customer orders

Customers can list and view their own placed orders, including:
- Order number and dates.
- Order status and payment status separately.
- Multiple order items.
- Snapshot customer/address/item values.
- Totals.
- Tracking provider and number.

Pending checkout records should not appear as successfully placed orders.

### BE-ORDER-02: Admin customers and orders

Admin can:
- List customers.
- View customer details.
- View orders belonging to a selected customer.
- List all placed orders.
- View each order and its customer relationship.

Customer-management mutation operations are not requested.

### BE-ORDER-03: Tracking only

Admin can update:
- tracking_provider
- tracking_number

Require both fields together.
Store tracking numbers as strings to preserve leading zeros.

Reject other submitted order fields.
Customer history/details must show updated tracking on the next fetch.

The schema says tracking_number is required before completed.
Enforce that invariant for any future transition.

However, saving tracking must not automatically mean completed:
the user has not defined completion semantics or authorized a manual
status editor.

Do not implement automatic processing/completed/cancelled transitions
until their triggers are specified.

## 13. Email

Required:
- Password-reset email for either role.
- Order-confirmation email after verified captured payment.

Order email includes:
- Order number.
- Item names and quantities.
- Paid total.
- Shipping snapshot.
- Link to the customer's order details.

Use queued delivery with retries.
Email failures must not roll back successful payment confirmation.

Welcome emails and tracking-update emails remain unconfirmed.

## 14. Proposed API surface

Prefix: /api/v1

Public/auth:
POST /auth/register
POST /auth/login
POST /auth/forgot-password
POST /auth/reset-password
GET  /variants
GET  /variants/{id}
GET  /tags

Authenticated:
GET  /auth/me
POST /auth/logout
PUT  /auth/password

Customer:
POST /customer/profile
GET  /customer/profile
PUT  /customer/profile
GET  /customer/addresses
POST /customer/addresses
PUT  /customer/addresses/{id}
PATCH /customer/addresses/{id}/default
GET    /customer/cart
POST   /customer/cart/items
PATCH  /customer/cart/items/{id}
DELETE /customer/cart/items/{id}
POST /checkout/review
POST /checkout/payment
GET  /checkout/{order_number}/status
GET  /customer/orders
GET  /customer/orders/{order_number}

Admin:
Resource endpoints for products, variants and tags.
Image upload/remove/reorder endpoints for variants.
GET   /admin/customers
GET   /admin/customers/{id}
GET   /admin/customers/{id}/orders
GET   /admin/orders
GET   /admin/orders/{order_number}
PATCH /admin/orders/{order_number}/tracking

Provider:
POST /webhooks/razorpay

The webhook uses provider signature verification, not customer login.

These names are a proposed shared frontend/backend contract.
Adapt consistently to existing repository conventions. Section 17 lists
where the frontend currently differs.

Return decimal money as strings.
Return field-level validation errors.
Use consistent unauthenticated, forbidden, missing-resource,
conflict and validation responses.

## 15. Critical acceptance tests

- Email/password registration creates only an active customer.
- Customer creates exactly one profile after sign-in.
- Default-address changes leave exactly one default.
- Cross-customer resource access fails, including carts.
- Adding the same variant twice leaves one cart line with the combined quantity.
- Checkout review uses the server cart and current prices, not client data.
- Inactive parent or zero-stock variants are excluded.
- Tag/price filters and all three sort modes work together.
- Deleted variants do not destroy order snapshots.
- Tampered client prices do not affect payable totals.
- Invalid payment signatures cannot confirm orders.
- Verified capture confirms payment/order, deducts stock once and clears only the paid cart quantities.
- Duplicate concurrent webhooks have no duplicate side effects.
- Two competing checkouts cannot oversell reserved stock.
- Browser closure does not prevent confirmation.
- Admin can edit tracking but cannot change other order fields.
- Customer sees updated tracking.
- Email failure preserves the confirmed order.

## 16. Instructions to Claude

Inspect the existing repository before changing it.

Implement only confirmed scope. Resolve schema gaps explicitly:
- Registration without users.name.
- Unknown payment method before payment.
- placed_at timing for pre-payment pending orders.
- Reservation and late-payment handling.
- Provider correlation and webhook deduplication.
- Durable confirmation-email scheduling.
- Shipping, currency and tax rules.
- Completion/status transition semantics.
- Guest cart and merge on sign-in.
- Photo alt text.

Do not change decimal money columns to integer columns.
Do not add a paid value to orders.status without approval.
Do not introduce unrestricted admin order editing.
Keep the ER diagram, migrations, models and API contract synchronized.

## 17. Frontend alignment

The frontend (`Artistic-hub-frontend/`) was built on mock data before this
specification. Its own requirements are in `Artistic-hub-frontend/docs/frontend-srs.md`,
whose section 18 lists screen-level differences. Its API paths live in `src/api/config.ts`. Where it differs,
this specification is the proposed contract; agree each item and then change
the frontend to match, rather than bending the backend to the mock.

| Topic | This specification | Frontend today |
|---|---|---|
| Base URL | `/api/v1` | `VITE_API_BASE_URL=http://localhost:8000/api` |
| Registration | email, password, password_confirmation; profile afterwards | Collects first and last name at sign-up |
| Customer name | Single `users.name` | `firstName` / `lastName` |
| Admin sign-in | Shared `/auth/login`; role comes back with the user | Separate `/admin/auth/*` endpoints and admin session |
| Admin roles | admin only | `AdminUser.role` is `owner` or `staff` |
| Customer paths | `/customer/profile`, `/customer/addresses`, `/customer/orders`, `/auth/password` | `/account/profile`, `/account/addresses`, `/orders`, `/account/password` |
| Catalog | `/variants` listing (variants are the cards) | `/products` listing |
| Cart | Server cart at `/customer/cart` | Browser localStorage cart |
| Checkout | `/checkout/review`, `/checkout/payment`, status polling | `POST /orders` |
| Money | Decimal strings (`"499.00"`) | Admin: integer paise; shop: whole rupees |
| Selling price field | `selling_price` | Admin: `effective_price` |
| Product save | Separate product, variant and photo endpoints | One `PUT /admin/products/{id}` with nested variants and photo URLs |
| Photo upload | Variant image upload/remove/reorder endpoints | `POST /admin/uploads/images` returning `{ url }` |
| Tracking | `PATCH /admin/orders/{order_number}/tracking` with `tracking_provider`, `tracking_number` | `PUT /admin/orders/{id}/shipment` with `courier`, `tracking_number` |
| Order status | pending, confirmed, processing, completed, cancelled (only pending → confirmed is automated) | processing, shipped, delivered, cancelled |
| Customer status | active, blocked, suspended, pending | active, blocked |
| Shipping and tax | Unconfirmed | Free-shipping threshold and a tax line |
