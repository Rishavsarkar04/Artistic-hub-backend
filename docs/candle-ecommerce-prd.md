# Candle E-commerce — Product Requirements Document

Version: 1.1 draft (aligned with the Artistic Hub codebase on 2026-09-30)  
Prepared for: implementation with Claude  
Scope: single-store candle e-commerce website with Admin and Customer roles

Identical copies live in `Artistic-hub-backend/docs/` and `Artistic-hub-frontend/docs/`. Change both together.

## 0. Project alignment

The backend SRS (`Artistic-hub-backend/docs/backend-srs.md`) is newer and overrides this PRD wherever they conflict; this PRD still applies where the SRS is silent.

This section maps the PRD onto the actual project. Where it records a decision, that decision replaces the matching "proposed" item further down. Where it lists a conflict, nothing is decided yet: ask before building the affected behavior.

### Project layout and stack

| Part | Location | Stack |
|---|---|---|
| Backend API | `Artistic-hub-backend/` | Laravel 13, PHP. Local database is SQLite; production database still to be confirmed. |
| Frontend | `Artistic-hub-frontend/` | React 19 + TypeScript (strict), Vite, Tailwind CSS v4, shadcn/ui, Zustand, axios. Hosted on Vercel. Currently runs on mock data in `src/data/` until the API exists. |
| Schema | `Artistic-hub-backend/docs/database/er-diagram.md` | Source of truth for tables and columns. It replaces the conceptual model in section 9. |

### Decided in the project

- **Roles:** `admin` and `customer`, stored with `spatie/laravel-permission` (`roles`, `model_has_roles`). Not installed yet.
- **Customer data:** `users` holds login and account `status`; `customer_profiles` holds phone, date of birth, gender, avatar and notes; `customer_addresses` holds labelled addresses (home, work, other) with one default.
- **Catalog:** products have one or more variants; variants are the sellable unit with unique `sku` and `slug`, `original_price` and selling price, `stock`, optional description (falls back to the product's) and `is_active`. Photos and tags belong to variants.
- **Stock and discounts are in scope:** each variant tracks `stock`, and a lower selling price shows the original struck through. Stock reservation during payment is still undecided (see conflicts).
- **Variant photos:** ordered by `sort_order`; the lowest is the cover. There is no separate primary-image flag.
- **Tags:** `tags` (unique `name` and `slug`) linked through `product_variant_tags`. Admins add, rename and delete tags from the product form ("Add or edit tags" dialog), not a separate screen. Renames and deletes apply at once to every product.
- **Currency and money:** INR. Money columns stay `decimal` and the API returns them as strings (SRS); Razorpay subunits are used only at the provider boundary. The admin frontend's integer paise must be converted.
- **Registration and profile (SRS):** sign-up takes only email and password; the customer creates a profile (name, phone, …) after signing in, and needs it before checkout.
- **Checkout (SRS):** a pending order and pending payment are created before Razorpay; the verified `payment.captured` webhook moves the order from pending to confirmed and deducts stock once. There are no separate checkout tables.
- **Listing (SRS):** customers browse variants; only active variants of active products with stock above 0 are listed.
- **Orders:** order and order-item rows snapshot customer, address, product, SKU, photo and prices at purchase; `order_items.product_variant_id` becomes null if the variant is deleted.
- **Admin order editing:** only the tracking provider (courier) and tracking number. No status buttons, cancel or delete in the admin UI.
- **Cart:** server-side, one per customer (`carts`, `cart_items` in the ER diagram). Items hold variant and quantity only, with no prices; checkout revalidates price, stock and availability. The frontend cart in browser localStorage stands in until the cart API exists. Whether signed-out visitors keep a browser cart that merges into the server cart on sign-in is still open (CART-01).
- **Guest behavior:** public browsing; sign-in required for checkout, order confirmation and account pages.
- **Password reset:** built in the frontend for both customers and admins, with a neutral forgot-password response.

### Open conflicts to resolve

| Topic | PRD says | ER diagram / code says | Decision needed |
|---|---|---|---|
| Selling price name | "selling price" | ER `selling_price`; admin frontend `effective_price` | Choose one field name for the API |
| Photo table | `variant_images`, image location | ER `product_variant_photos.path`; frontend `product_variant_images` with `url` and `alt_text` | Choose table and columns; decide whether `alt_text` is kept |
| Admin roles | Exactly two roles (SRS confirms) | Frontend `AdminUser.role` is `owner` or `staff` | Remove owner/staff from the frontend |
| Customer account state | Active on registration; disabled accounts stay disabled | ER `users.status`: active, blocked, suspended, pending; frontend: active, blocked | Confirm the states and who can change them |
| Order status | Only "placed"; no manual status changes | ER and SRS: pending → confirmed on verified payment; processing, completed, cancelled have no defined triggers yet; frontend shows processing, shipped, delivered, cancelled | Define the remaining transitions; align the frontend statuses |
| Payment methods | Razorpay Payment Links, full payment only | ER `payments.method` includes cod, card, upi, bank_transfer, wallet; provider includes stripe and cash. SRS: method is unknown before payment | Make `method` nullable (or add an unknown value); confirm whether COD is in scope |
| Tax | Must be decided before checkout | ER `orders` has discount and shipping but no tax column; frontend order detail shows tax | Decide whether prices include tax and whether a tax column is needed |
| Tracking fields | Tracking provider and number | ER `tracking_provider`; frontend and API path use `courier` | Choose one field name |
| Supporting tables | Webhook events, tracking audit, notification outbox | Not in the ER diagram; SRS also needs stock reservations and provider correlation | Add them to the ER before payment and email work |
| Frontend API contract | — | SRS section 17 lists where the frontend's paths, fields and money format differ | Agree each item, then change the frontend |
| Store name | Brand still to be confirmed | Repo is "Artistic Hub"; frontend is branded "Ember & Bloom" | Confirm the brand |

## 1. Objective

Build a responsive candle store where customers browse purchasable product variants, manage their accounts and shipping addresses, add variants to a cart, pay through Razorpay, and view their orders and shipment tracking details. Admin manages the catalog and tags, views placed orders, and updates only the tracking provider and tracking number on an order.

This document distinguishes confirmed requirements from proposed defaults. Proposed defaults are not additional confirmed business requirements. Resolve launch-blocking questions in section 13 before production implementation.

## 2. Roles and permissions

Exactly two user roles exist: Admin and Customer. An unauthenticated visitor is an access state, not a third role.

| Capability | Admin | Customer |
|---|---|---|
| Public self-registration | No | Yes |
| Sign in, sign out, forgot password, change password | Yes | Yes |
| Manage own customer profile and addresses | Not required | Yes |
| Create/update/delete products and variants | Yes | No |
| Activate/deactivate products and variants | Yes | No |
| Manage variant images and tags | Yes | No |
| Browse catalog and purchase | Not required | Yes |
| View placed orders | All store orders | Own orders only |
| Save/edit tracking provider and tracking number | Yes | No |
| Manually change payment/order status or order contents | No | No |
| Cancel, refund, or delete orders in the application | No | No |

Provision the initial admin securely during setup; do not expose public admin registration or ship a hard-coded production password. Public registration always creates a Customer regardless of role fields submitted by the client.

## 3. Authentication and account management

### AUTH-01: Customer registration and activation

- Customers can create an account and sign in.
- New customers become active automatically after successful registration, without admin approval.
- Proposed interpretation: activation occurs on registration; later sign-ins do not reactivate a disabled account.
- Proposed registration fields: name, email, password, password confirmation; phone is optional until shipping requirements are settled.
- Email must be unique. No email-verification gate is required for account activation in this scope.
- Whether registration also signs the customer in automatically is a proposed UX default: yes.

### AUTH-02: Password management

- Both roles can request a password-reset email and set a new password through an expiring, single-use reset token.
- Both roles can change their password while signed in, with current-password verification.
- Return a neutral forgot-password response to avoid disclosing whether an email is registered.
- Rate-limit login and reset requests. Store password hashes, never plaintext passwords.

### ACCOUNT-01: Customer profile

- A customer can view and update their own profile.
- Proposed editable fields: name and phone. Email-change behavior remains a decision in section 13.
- Customers cannot change their role, activation state, or another user's data.

### ACCOUNT-02: Multiple addresses

- Customers can add multiple shipping addresses and select one at checkout.
- Proposed supporting behavior: edit/delete addresses and select a default address.
- Proposed fields: recipient name, phone, address line 1, optional address line 2, city, state/region, postal code, country.
- Validate address ownership on every read and write.
- Copy the selected address into the checkout/order snapshot. Editing or deleting the saved address later must not change an existing order.

## 4. Admin catalog management

### CAT-01: Product and variant structure

- A product is a parent grouping with one or more variants.
- Each variant belongs to exactly one product and is the purchasable unit.
- Customer listing cards represent variants, not only parent products.
- Admin can create and update products and their variants.
- Fields now follow the ER diagram (see section 0); the original proposal is kept for reference.

| Entity | Fields in this project |
|---|---|
| Product | ID, name, unique slug, description, active flag |
| Variant | ID, product ID, name, unique SKU, unique slug, original price, selling price, stock, optional description, active flag |
| Variant photo | ID, variant ID, path, sort order (lowest is the cover) |
| Tag | ID, unique name, unique slug |

- Store prices in integer minor units with an explicit currency; never use client-submitted prices as the payable amount.
- Images belong to variants. Allow multiple images, replacing/removing images and ordering them; the first in order is the primary image.
- Proposed validation: require a positive price and at least one image before a variant can be published.
- Optional candle attributes such as scent, wax type, size, color, weight, or burn time are not confirmed requirements. Do not build a generic option/attribute engine without confirmation.
- Per-variant stock and original/discount prices are in scope (section 0). Categories and tag hierarchy are still not requirements.

### CAT-02: Activation rules

1. Admin can activate/deactivate individual variants and parent products.
2. Deactivating a product must also deactivate all of its variants.
3. A variant cannot be activated while its parent is inactive.
4. An inactive product or variant is unavailable for new purchases and absent from public listings.
5. Direct links to unavailable variants must not allow purchase.
6. Proposed reactivation behavior: reactivating a product leaves its variants inactive until the admin activates the desired variants. This prevents accidental republication.
7. If an item becomes unavailable while in a cart, checkout must reject it with a clear message and require the cart to be corrected.

### CAT-03: Deletion rules

- Admin can delete an individual variant.
- Deleting a product must delete all variants under it from the live catalog.
- Implement catalog deletion using soft deletion or equivalent archival behavior when needed to preserve purchase history.
- Product deletion must not delete historical orders or order items.
- Retain purchased item names, SKU, price, quantity, and needed image references independently of the live catalog.
- A deleted variant cannot be purchased using an old URL or stale cart.
- Apply product and child-variant changes atomically so partial deletion/deactivation cannot leave live orphan variants.

### CAT-04: Tags

- Admin can create, update, and delete tags.
- Admin can attach/detach multiple tags to/from each variant.
- A tag can belong to multiple variants: use a many-to-many relationship.
- Tags are attached to variants, not automatically to all variants of a parent product.
- Renaming a tag updates its current catalog display wherever attached.
- Deleting a tag removes its variant associations without deleting variants.
- Show attached tags on variant details. Tag-based filtering is optional until confirmed.

## 5. Customer browsing and cart

### SHOP-01: Variant listing

- Show active variants whose parent products are active.
- Proposed card content: primary image, variant name, parent product name where useful, and price.
- Clicking a card opens the selected variant's details page.
- Support pagination or equivalent bounded loading.
- Public browsing without login is a proposed default; authentication is required before checkout.

### SHOP-02: Variant details

- Display product description, selected variant name, variant image gallery, price, tags, and availability.
- Allow a positive integer quantity to be selected and the selected variant to be added to cart.
- Optional sibling-variant selection may be provided but must preserve the exact selected variant ID, price, and images.

### CART-01: Cart behavior

- Each line identifies a variant and quantity.
- Adding the same variant again increases that line's quantity.
- Customers can view the cart, update quantities, remove items, and proceed to checkout.
- Show line totals and cart subtotal.
- Proposed resolution of the overview's navigation ambiguity: Add to Cart opens the cart page; payment does not begin until the customer selects an address, reviews checkout, and clicks Pay.
- Revalidate catalog availability and prices on the backend at checkout. If a price changes, show the new total and require renewed confirmation before payment.
- Do not silently remove unavailable items or silently charge a changed total.
- Guest cart persistence and merging on login are not confirmed features.

## 6. Checkout and Razorpay payment

### CHECKOUT-01: Address and review

1. Customer opens cart and selects Proceed to Checkout.
2. Customer signs in if required.
3. Customer selects an address they own; if none exists, they add one.
4. Customer reviews variants, quantities, prices, shipping address, and final total.
5. Customer clicks Pay to start Razorpay payment.

Shipping charges, taxes, currency, and supported shipping regions must be decided before production checkout is completed. Do not silently assume zero shipping or tax.

### PAY-01: Initiate payment

- Use Razorpay. Proposed integration method, based on the payment-link flow discussed: a backend-created Razorpay Payment Link, with full payment only.
- Calculate the payable total on the server from trusted data.
- Before contacting Razorpay, persist a pending checkout record and payment attempt with a stable internal reference and an immutable item/address/amount snapshot.
- Save the returned payment link ID and URL on the attempt, then return the URL to the frontend.
- Disable repeated Pay submissions in the UI and handle concurrent requests on the backend; do not create duplicate live payment attempts for the same checkout.
- Keep Razorpay API secrets and webhook secrets on the server.
- Make external payment-provider calls outside long-running database transactions, and reconcile ambiguous failures before retrying creation.

### PAY-02: Confirm and place the order

- The customer's order is considered placed only after the backend verifies successful payment.
- A pending checkout/payment record may exist before payment for correlation and recovery; it is not a placed order.
- Receive Razorpay notifications at a dedicated webhook endpoint and verify the signature against the raw body.
- Match the saved payment attempt/link, expected total, currency, and successful captured/full-payment state before confirming the purchase.
- Do not trust browser query parameters, a frontend success message, or an unsigned request as payment proof.
- Process repeated or concurrent notifications idempotently: create/confirm one order, record the payment ID once, and create one confirmation-email task.
- Publish the placed order to both customer history and admin order list after successful confirmation.
- Implement current Razorpay API and SDK details against official documentation during development; this PRD defines behavior, not a fixed API version.

### PAY-03: Return page and recovery

- After the browser returns from Razorpay, the result page requests the authenticated customer's checkout/order status from the backend.
- Show Confirming payment while confirmation is pending; use bounded polling and provide a safe way to revisit the result later.
- Browser closure must not prevent webhook-based order confirmation or the confirmation email.
- Failed, cancelled, or abandoned payment attempts must not produce a placed order or success email.
- Before allowing a replacement payment attempt, reconcile the existing attempt and avoid two concurrently payable links. Expire/cancel old links when appropriate.
- If a provider timeout leaves the outcome unknown, show pending confirmation and reconcile; do not assume failure and charge again.
- Clear only the cart quantities represented by the paid checkout. Preserve items added afterward.
- Log and surface unexpected late or duplicate successful charges for reconciliation; do not create duplicate fulfillment orders or silently discard the charge.

### Proposed internal lifecycle

| Record | States | Customer meaning |
|---|---|---|
| Checkout/payment attempt | pending, paid, failed, expired | Payment preparation and confirmation |
| Placed order | placed | Purchase confirmed after verified payment |
| Tracking data | absent or recorded | Admin has or has not supplied shipment details |

Do not add manual shipped/delivered/cancelled status controls. Tracking data alone is not proof that a carrier has delivered a parcel.

## 7. Orders and tracking

### ORDER-01: Customer history

- Customers can list and open only their own placed orders.
- Display order number, placement date, purchased variants, quantities, unit prices, totals, shipping-address snapshot, and payment confirmation state.
- Show tracking provider and tracking number when present; otherwise show Tracking details not added yet.
- Historical item details and paid totals remain unchanged after catalog, pricing, or address edits.

### ORDER-02: Admin order view

- Admin can view all placed orders and their details, including customer information, purchased items, address snapshot, totals, and payment references.
- The only order-related editing capability is saving/updating the tracking provider and tracking number.
- No manual payment confirmation, item editing, address editing, total changes, refunds, cancellations, or order deletion controls.
- Backend authorization and input allowlists must enforce these restrictions even if requests bypass the UI.

### TRACK-01: Tracking update

- Admin enters a tracking provider and tracking number on an existing placed order.
- Require both values together and validate reasonable lengths; preserve leading zeros in tracking numbers by storing them as text.
- Proposed input: provider is plain text, because a maintained provider list was not requested.
- Admin can correct saved values.
- Store the update time and admin identity for audit purposes.
- After saving, the current values appear in the customer's order history/details on the next fetch or refresh; real-time push is not required.
- Do not require courier API integration, live shipment status, automatic tracking URLs, or tracking emails in v1.

## 8. Email notifications

| Event | Recipient | Requirement |
|---|---|---|
| Password reset | Requesting admin/customer | Required |
| Verified order placement | Purchasing customer | Required |
| Successful registration | New customer | Proposed welcome email; overview's general email requirement needs clarification |
| Tracking update | Customer | Not included unless confirmed |
| New order | Admin | Not included unless confirmed |

- The order confirmation email contains order number, items/quantities, total, shipping address, and a link to authenticated order details.
- Send confirmation only after the paid order is committed successfully.
- Queue delivery with retries. An email failure must not undo a paid order.
- Use a durable, deduplicated notification job/outbox keyed by order and notification type to prevent webhook redelivery from scheduling duplicate confirmations.
- Do not include passwords, payment secrets, full card information, or reset tokens in application logs.

## 9. Suggested data model

Superseded by `Artistic-hub-backend/docs/database/er-diagram.md`. The table below is the original conceptual model; rows with no ER equivalent yet (checkouts, webhook events, tracking audit, notification outbox) are listed as open conflicts in section 0.

| Entity | Responsibility and relationships |
|---|---|
| users | Admin/Customer role, account state, profile, password hash |
| customer_addresses | Multiple saved addresses per customer |
| products | Parent catalog grouping and active/deleted state |
| product_variants | Belongs to product; purchasable name, SKU, price, state |
| variant_images | Multiple ordered images per variant |
| tags | Reusable tag names |
| variant_tag | Unique variant/tag pairs |
| carts / cart_items | Proposed server-side customer cart; unique variant per cart |
| checkouts / checkout_items | Pending purchase snapshot before verified payment |
| payment_attempts | Checkout reference, provider link/payment IDs, amount, currency, outcome |
| orders / order_items | One confirmed order per checkout; immutable purchase snapshots |
| webhook_events | Deduplicated processing, event references and outcomes |
| order_tracking_audit | Tracking changes, admin identity and timestamp |
| notification_outbox | Durable order-email scheduling and delivery state |

Use database uniqueness constraints for SKU, applicable provider identifiers, webhook event identity, and one order per checkout. Keep tracking fields on the order for current display; an audit record preserves corrections. Historical snapshots must survive catalog deletion.

## 10. Required screens

### Customer storefront and account

- Variant catalog/listing.
- Variant details and image gallery.
- Sign up, sign in, forgot/reset password.
- Cart.
- Checkout: address selection and review.
- Payment result/confirmation pending.
- Profile and change password.
- Saved addresses.
- Order history and order details with tracking.

### Admin

- Sign in, forgot/reset password, change password.
- Product list and product create/edit with activation and deletion.
- Variant create/edit with images, tags, activation and deletion.
- Tag management.
- Placed-order list and read-only details, with tracking provider/number form.

Provide loading, empty, validation-error, unavailable-item, payment-pending, and retry states. Support mobile and desktop layouts, labelled form controls, keyboard access, and readable validation messages.

## 11. Acceptance criteria

| ID | Scenario | Expected result |
|---|---|---|
| AC-01 | Customer registers with valid data | Active Customer account created without admin approval |
| AC-02 | Customer submits role=admin | Request cannot create or promote an Admin |
| AC-03 | Either role resets a password | Valid expiring token permits reset; expired/reused token fails |
| AC-04 | Admin creates product with two variants | Both can have independent images, prices and tags |
| AC-05 | Admin deactivates parent product | All child variants become inactive and unpurchasable |
| AC-06 | Admin tries to activate child of inactive parent | Activation rejected |
| AC-07 | Admin deletes product | Children disappear from live catalog; old orders remain readable |
| AC-08 | Admin deletes a tag | Associations removed; variants remain |
| AC-09 | Customer opens a listing card | Details show the exact selected variant |
| AC-10 | Customer adds same variant twice | One cart line reflects combined quantity |
| AC-11 | Customer uses another customer's address ID | Backend rejects access |
| AC-12 | Cart price or availability changes before payment | Backend revalidates and requests correction/reconfirmation |
| AC-13 | Customer tampers with client total | Backend uses trusted server-calculated amount |
| AC-14 | Signed valid full-payment notification arrives | Exactly one placed order appears in customer/admin views |
| AC-15 | Duplicate/concurrent payment notification arrives | No duplicate order or confirmation-email scheduling |
| AC-16 | Invalid webhook signature or mismatched amount arrives | Purchase is not confirmed; failure recorded |
| AC-17 | Browser closes immediately after paying | Verified webhook can still confirm order and schedule email |
| AC-18 | Payment fails or is abandoned | No placed order and no success email |
| AC-19 | Admin saves tracking provider and number | Customer sees the saved values on next order fetch |
| AC-20 | Admin submits changed order amount/status via API | Unauthorized fields are rejected and order remains unchanged |
| AC-21 | Customer requests another customer's order | Backend denies access |
| AC-22 | Product/address changes after purchase | Historical order snapshot is unchanged |
| AC-23 | Order email temporarily fails | Order stays placed and delivery retries |

Test these behavior boundaries, especially authorization, payment idempotency, and catalog cascades. Use Razorpay test mode for payment integration validation.

## 12. Out of scope for v1

- Additional roles, marketplace sellers, multi-tenancy, or multiple stores.
- Customer/admin cancellation, return, refund, and order deletion workflows.
- Manual order-status changes beyond saving tracking information.
- Courier booking, shipping-label generation, live carrier tracking, and split shipments.
- Coupons, loyalty points, subscriptions, reviews, wishlists, gift cards, and recommendations.
- Category management, hierarchical tags, advanced search/filtering, and a generic variant-option builder unless confirmed.
- Social login, guest checkout, and mandatory email-verification activation.
- Inventory reservation and stock administration until the stock requirement is confirmed.

Unexpected payment or fulfillment exceptions still require an operational resolution process; their occurrence must be logged even though an in-app refund workflow is outside this scope.

## 13. Decisions and proposed defaults

Do not block implementation of confirmed catalog/account requirements while these are being clarified. Do not treat unconfirmed choices as approved launch requirements.

| Decision | Proposed default or question | Impact | Status in this project |
|---|---|---|---|
| Technology | Laravel backend; React + TypeScript frontend; MySQL. Confirm before scaffolding. | Project architecture | Laravel 13 and React 19 + TypeScript in use. Database: SQLite locally; production engine not confirmed |
| Payment integration | Razorpay hosted Payment Links, full payment only | Payment flow | Open: ER also lists COD and other methods |
| Currency and regions | Confirm INR and allowed shipping countries/regions | Launch blocker for checkout | INR decided; regions open |
| Shipping charges and taxes | Confirm rules and whether prices include taxes | Launch blocker for accurate totals | Open: ER has shipping and discount, no tax |
| Stock | Is stock tracked per variant? If yes, specify reservation, payment expiry and late-payment handling before checkout implementation. | Launch blocker for availability promises | Tracked per variant; reservation rules open |
| Variant fields | Confirm SKU, price, description overrides and candle-specific attributes | Catalog forms/schema | Decided per ER diagram; no candle-specific attributes |
| General email request | Proposed welcome email on registration, plus confirmed password-reset/order emails | Notification scope | Open |
| Profile editing | Name and phone proposed; decide whether email is editable and how changes are verified | Account security | Open (profile also has date of birth, gender, avatar) |
| Product reactivation | Leave child variants inactive until explicitly activated | Publishing behavior | Open |
| Add-to-cart navigation | Open cart, then address/review checkout, then Razorpay | UX interpretation | Open |
| Guest behavior | Public browsing; sign-in required before checkout | Authentication boundary | Decided as proposed |
| Tracking provider | Free-text provider and tracking number; no courier integration | Admin form | Decided as proposed; field name open |
| Store identity/content | Brand name, logo, support email, candle copy and applicable policy content | Launch content | Open ("Artistic Hub" vs "Ember & Bloom") |

## 14. Instructions to Claude

1. Treat confirmed requirements in this PRD as the product scope. Clearly distinguish proposed defaults and unresolved decisions.
2. This project already exists (section 0). Follow each repository's `CLAUDE.md` and the ER diagram, and reuse established patterns. Before building anything listed under "Open conflicts", ask which way to go.
3. If creating a new project, confirm the architecture and checkout launch blockers in section 13 before implementing affected behavior.
4. Keep exactly two roles. Do not add admin order mutation capabilities beyond tracking provider and tracking number.
5. Build around variants as the sellable entities; tags and images attach to variants.
6. Enforce parent activation/deletion cascades and preserve immutable purchase history.
7. Separate pending checkout/payment attempts from confirmed placed orders. Validate provider payment confirmation on the backend and make processing idempotent.
8. Verify current Razorpay integration requirements against official documentation when implementing payment code.
9. Implement incrementally: authentication/accounts, admin catalog/tags/images, storefront/cart, checkout/payment, orders/tracking, email and end-to-end verification.
10. Provide migrations, validation, authorization, required UI states, and tests for critical business rules. Report remaining assumptions and unimplemented requirements explicitly.
11. Do not add out-of-scope features or silently select tax, shipping, stock, or refund policies.
