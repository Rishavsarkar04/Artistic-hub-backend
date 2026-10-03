# Checkout, Orders and Payments

How checkout works as built: from the cart to a Razorpay payment link, the
order and payment records it creates, how amounts are calculated, and what
the payment result page reads. Decisions are dated; requirements stay in
`backend-srs.md` (section 9), the schema in `database/er-diagram.md`.

Status (2026-10-03): review, checkout (payment link), the Razorpay
webhook that confirms payment, the order list and order details are all
built.

## 1. The flow

| # | Customer | Frontend calls | Backend |
|---|---|---|---|
| 1 | Opens the cart | `GET /customer/cart` | Items at current prices, `fare_breakup`, item issues |
| 2 | Picks an address | `GET /customer/addresses` | Saved addresses |
| 3 | Review page | `GET /customer/checkout/review?address_id=` | Runs every checkout check, changes nothing |
| 4 | Presses Pay | `POST /customer/checkout { address_id }` | Pending order + payment, Razorpay link, returns `payment_url` |
| 5 | Pays | Redirect the browser to `payment_url` | — (Razorpay's hosted page) |
| 6 | Payment succeeds | — (Razorpay calls `POST /webhooks/razorpay`) | Order confirmed, `placed_at` set, stock deducted once, paid quantities removed from the cart, one email queued (section 10) |
| 7 | Comes back | Result page calls `GET /customer/orders/{order_number}` | The order with its status and payment status |

Decided 2026-10-03:
- The client never sends a total. The backend calculates it from the cart,
  and Razorpay's page shows the exact amount, which the customer confirms.
- Prices include GST: nothing is added.
- No shipping fare for now: `shipping_amount` is 0.
- No stock reservation: stock is checked at checkout and deducted once
  when the payment is confirmed.
- The cart and stock do not change at checkout.

## 2. Code map

| Piece | File |
|---|---|
| Routes | `routes/api/customer.php` (`checkout.review`, `checkout.store`, `orders.index`, `orders.show`); `routes/api.php` (`webhooks.razorpay`) |
| Checkout logic | `app/Services/Checkout/CheckoutService.php` (`review()`, `checkout()`) |
| Order list and details | `app/Services/Orders/CustomerOrderService.php` (`listOrders()`, `getOrder()`) |
| Webhook | `app/Http/Controllers/Api/Webhooks/RazorpayWebhookController.php` → `app/Integrations/Razorpay/RazorpayWebhook.php` (verify, map to `PaymentLinkEvent`) → `app/Services/Payments/PaymentCaptureService.php` |
| Cancelling unpaid payments | `app/Services/Payments/PaymentCancellationService.php` (`cancelPending()`), used by `cancelOlderLinks()` and the expired/cancelled webhook |
| Confirmation email | `app/Mail/OrderConfirmed.php`, `resources/views/mail/orders/confirmed.blade.php` |
| Payment provider contract | `app/Contracts/PaymentGateway.php` (interface, `#[Bind(RazorpayGateway::class)]`) |
| Razorpay calls | `app/Integrations/Razorpay/RazorpayGateway.php` (REST API via Laravel's HTTP client) |
| Order number | `app/Support/OrderNumber.php` |
| Money arithmetic | `app/Support/Money.php` (bcmath on strings, never floats) |
| Responses | `CheckoutReviewResource`, `CheckoutPaymentResource`, `CustomerOrderResource`, `CustomerOrderSummaryResource`, `OrderItemResource` |
| Errors | `app/Exceptions/Checkout/PaymentGatewayUnavailable.php` (503), `CheckoutInProgress.php` (409), `InvalidWebhookSignature.php` (400) |
| Settings | `config/services.php` → `razorpay` (see `setup.md`, section 5c) |
| Tests | `tests/Feature/Customer/CheckoutReviewTest.php`, `CheckoutTest.php`, `OrderTest.php`, `tests/Feature/Webhooks/RazorpayWebhookTest.php`, `tests/Unit/OrderNumberTest.php` |

The payment gateway is the one interface in the codebase (decided
2026-10-03). It is bound with Laravel's `#[Bind]` attribute, not in a
service provider (architecture guide, sections 12 and 26.2).

## 3. Review: `GET /customer/checkout/review?address_id=`

Read-only. It checks exactly what checkout checks:

1. The address is one of the customer's. Any other id gets
   422 `address_id` "This address was not found. Choose another one."
2. The cart is not empty. Otherwise 422 `cart` "Your cart is empty."
3. Every item can be bought in its quantity. Otherwise 422 `items.N`
   (N is the item's position in the cart), with messages such as:
   - "Amber & Sandalwood (Small) is no longer available. Remove it to continue."
   - "… is out of stock. Remove it to continue."
   - "Only 2 left of … Lower the quantity to continue."

It returns the address, the items, `item_count` and the cart's
`fare_breakup` (`mrp_total`, `discount`, `subtotal`) at current prices.

## 4. Checkout: `POST /customer/checkout { address_id }`

### 4.1 Steps

Inside one database transaction:

1. Lock the customer's profile row, so only one checkout per customer
   runs at a time.
2. Run the review checks above. The 422s are the same.
3. Look for a pending order to reuse (section 4.3). If one is found,
   return its payment and stop.
4. Otherwise create the pending order with its snapshots, its items and a
   pending payment with no link yet (section 5).

After the transaction commits, the Razorpay call runs. A slow call
therefore holds no lock.

5. Ask Razorpay for a payment link (section 7).
   - **Success:** save `payment_session_id`, `payment_url`, `expires_at`
     and `gateway_response` on the payment, and return them.
   - **Failure:** mark the payment `failed` (with `failed_at` and
     `failure_reason`) and the order `cancelled` (with `cancelled_at` and
     the reason "The payment could not be started."). Answer 503. The
     customer can simply press Pay again; that creates a new order.

### 4.2 Response

```json
{
  "data": {
    "order_number": "ORD-20261003-7K2Q9M",
    "payment_number": "PAY01K6…",
    "total_amount": "1799.00",
    "currency": "INR",
    "payment_url": "https://rzp.io/i/…",
    "expires_at": "2026-10-03T10:30:00+00:00"
  }
}
```

| Status | When |
|---|---|
| 201 | A new order was created |
| 200 | The same pending order was returned again (pressing Pay twice) |
| 409 | The same checkout is still waiting for Razorpay (less than 60 seconds, no link yet) |
| 422 | A review check failed |
| 503 | Razorpay could not create the link |

### 4.3 Pressing Pay again: reusing the pending order

A double-click, the back button or a refresh must not create a second
order and a second link. `findSamePendingPayment()` loads the customer's
newest pending order and its newest payment (`latestPayment`):

```php
if ($payment === null || ! $this->isSameCheckout($order, $review)) {
    return null;
}
```

- **`$payment === null`:** there is no pending order, or the order has no
  payment. There is nothing to reuse. The `?->` in
  `$order?->latestPayment` makes this safe when there is no order, and
  `||` stops before `isSameCheckout()` is called with a null order.
- **`! isSameCheckout(...)`:** a pending order exists but is for a
  different purchase, so its link would charge the wrong amount or ship
  to the wrong place.

When both pass:
- **The link is still payable** (pending, has a URL, not expired): return
  it with 200, and the frontend redirects to the same Razorpay page.
- **The link is still being created** (pending, no URL, under 60 seconds
  old): throw `CheckoutInProgress` (409).
- **Otherwise** (expired or failed): return null.

A null return means the reuse branch is skipped and a new order is
created (steps 4 and 5).

### 4.4 `isSameCheckout()`: what "the same purchase" means

The address: the eight delivery fields of the chosen address now are
compared with the order's copy of them.

```
[recipient_name, phone, address_line_1, address_line_2, city, state, postal_code, country]
```

The order stores a copy of the address fields, not the address id. So
choosing a different address, or editing the same one (for example a
corrected postal code), both count as different.

The items: each line becomes one string, `variant id : quantity : price`.

```php
$cartLines = $review->cart->items
    ->map(fn (CartItem $item) => "{$item->product_variant_id}:{$item->quantity}:{$item->productVariant->selling_price}")
    ->sort()->values()->all();
$orderLines = $order->items
    ->map(fn (OrderItem $item) => "{$item->product_variant_id}:{$item->quantity}:{$item->selling_unit_price}")
    ->sort()->values()->all();
```

- **The cart side** uses the variant's current price. **The order side**
  uses the price saved at checkout. That difference is what catches a
  price change.
- **The `:` separator** keeps the parts apart (variant 1 with quantity 22
  is not the same as variant 12 with quantity 2).
- **`sort()`** puts both lists in the same order. Cart items come back in
  the order they were added, order items in the order they were saved.
- **`values()`** renumbers the keys to 0, 1, 2…, because `sort()` keeps
  the original keys and `===` compares keys too.
- **`all()`** turns the Collection into a plain array. `===` on two
  Collection objects is always false.

Example:

```
cart:  [ "22:1:450.00", "14:2:899.50" ]  → sorted → [ "14:2:899.50", "22:1:450.00" ]
order: [ "14:2:899.50", "22:1:450.00" ]  → sorted → [ "14:2:899.50", "22:1:450.00" ]   same → reuse
```

| Change since the pending order | Result |
|---|---|
| Quantity changed | new order |
| Item added or removed | new order |
| Admin changed the price | new order at the new price (an old, cheaper link is never reused) |
| Address changed or edited | new order |
| Nothing changed | reuse the existing link |

Not compared on purpose:
- **Discount and shipping:** both are always 0 for now. Add the totals to
  this comparison once either exists.
- **Product and variant names:** a rename does not change what the
  customer pays or receives.

### 4.5 Older pending orders (decided 2026-10-03)

When the purchase changed, a new order is created. So that an old tab
cannot charge the customer twice, `cancelOlderLinks()` runs after the new
link exists:

1. Find the customer's other payments that are still `pending`, have a
   link, and have not expired.
2. Ask Razorpay to cancel each link (`PaymentGateway::cancelPaymentLink`).
3. If Razorpay agrees: `PaymentCancellationService::cancelPending()`
   marks the payment `cancelled` and its order `cancelled`, with the
   reason "Replaced by a newer checkout."
4. If Razorpay refuses (typically because that link was just paid): log
   it and leave it alone. The webhook confirms that order.

This is best effort and never fails the new checkout. If an older link is
paid anyway, the webhook still confirms it: the money was taken, so it is
a real order (section 10).

## 5. The order record

### 5.1 Order number: `App\Support\OrderNumber`

```php
OrderNumber::unique(now(), fn (string $orderNumber) => Order::where('order_number', $orderNumber)->exists());
```

Format: `ORD-20261003-7K2Q9M`.

| Part | Meaning |
|---|---|
| `ORD` | Prefix |
| `20261003` | Date the order was created (YYYYMMDD, app timezone) |
| `7K2Q9M` | 6 random characters |

- **The alphabet** is `23456789ABCDEFGHJKLMNPQRSTUVWXYZ`: no `0`/`O` or
  `1`/`I`, so a customer can read the number to support over the phone.
- **`random_int`** is cryptographically secure. The number reveals
  nothing about order volume (unlike `#1042`) and cannot be guessed.
- **Redraw:** the closure says whether a number is taken, and the helper
  draws again. That is 32⁶ (about 1.07 billion) combinations per day.
- **The unique index** on `orders.order_number` is the final guarantee.
  A same-instant collision would be a 500 rather than a retry, which is
  accepted at these odds.
- **`OrderNumber::PATTERN`** is the regex for a valid number. The
  `/customer/orders/{order}` route uses it, so the route and the
  generator cannot disagree.
- **Timezone:** the date follows `APP_TIMEZONE`. Set `Asia/Kolkata` if it
  should match the Indian calendar date.
- **Unpaid orders:** a number is used even if the order is never paid.
  That is harmless.

### 5.2 Snapshots

The order copies everything it needs at checkout. It never reads the live
profile, address or catalog afterwards, so later edits never change a
past order.

- **Customer:** `customer_name` (user name), `customer_email`,
  `customer_phone` (profile phone).
- **Shipping address:** `recipient_name`, `recipient_phone`,
  `address_line_1`, `address_line_2`, `city`, `state`, `postal_code`,
  `country`.
- **Each item:** `product_name`, `variant_name`, `sku`,
  `variant_photo_path` (the media path of the variant's cover photo at
  checkout), `original_unit_price` (MRP), `selling_unit_price`,
  `quantity`.
- **`product_variant_id`:** kept for reference only. It becomes null if
  the variant is deleted, and the snapshot stays.

### 5.3 Dates: `created_at` and `placed_at`

| Field | Set when | Meaning |
|---|---|---|
| `created_at` | The customer pressed Pay | When the checkout started (the pending order was created) |
| `placed_at` | The payment is confirmed (webhook) | When the order was actually placed; null until then |

Example:
- **10:00:** Pay pressed. `created_at = 10:00`, `placed_at = null`.
- **10:12:** paid on Razorpay. The webhook sets `placed_at = 10:12`.
- **Never paid:** `placed_at` stays null; the order was never placed.

There is no `confirmed_at` (decided 2026-10-03). It would always equal
`placed_at`, and `status = confirmed` already records the confirmation.

Frontend:
- **When `placed_at` has a value:** show it as the order date ("Ordered on
  3 Oct, 10:12"). The order list will sort placed orders by it.
- **When it is null:** use `created_at` with a "not placed" label, for
  example "Payment started at 10:00" or "Checkout on 3 Oct, payment not
  completed".

### 5.4 Order statuses (`App\Enums\OrderStatus`)

| Status | Meaning |
|---|---|
| `pending` | Created at Pay; waiting for the payment |
| `confirmed` | Payment confirmed (webhook) |
| `processing` | Being prepared (admin) |
| `completed` | Delivered; `tracking_number` required |
| `cancelled` | The payment could not be started, the link expired or was cancelled unpaid, or it was replaced by a newer checkout (`cancellation_reason` says which) |

## 6. Amounts

All money is `decimal(10,2)` in the database and strings like `"899.50"`
in PHP and JSON. All arithmetic uses `App\Support\Money` (bcmath), never
floats, so `0.1 + 0.2` is exactly `0.30`.

### 6.1 Order line (`order_items`)

| Field | Formula | Example |
|---|---|---|
| `subtotal` | `selling_unit_price × quantity` | 899.50 × 2 = 1799.00 |
| `discount_amount` | A discount on this specific item (future coupons such as "10% off candles"); 0.00 for now | 0.00 |
| `total_amount` | `subtotal − discount_amount`: what is charged for this line | 1799.00 |

`discount_amount` is **not** the MRP savings: those are already in the
selling price. To show "You saved ₹599", the frontend calculates
`(original_unit_price − selling_unit_price) × quantity`.

All three are stored even though two are redundant today. Once coupons
exist, each line keeps a record of what was actually charged, which
partial refunds and invoices need.

### 6.2 Order (`orders`)

```
orders.subtotal        = sum of item subtotals
orders.discount_amount = order-level discount (none yet)
orders.shipping_amount = delivery fare (0 for now)
orders.total_amount    = subtotal − discount_amount + shipping_amount
```

Apply a discount in one place only: per line (`order_items`) or on the
whole order (`orders`), never both, or it is subtracted twice.

### 6.3 From cart to Razorpay

1. **Item subtotal:** `CartItem::subtotal()` is the variant's current
   `selling_price × quantity`. It is never a price sent by the client and
   never one stored in the cart (cart items keep no prices).
2. **Cart subtotal:** `Cart::subtotal()` sums the items that can be
   bought. At checkout that is every item, because the review checks
   already rejected the request otherwise.
3. **Order total:** `createPendingOrder()` calculates
   `subtotal − discount (0) + shipping (0)`.
4. **Payment amount:** `payments.amount = orders.total_amount`.
5. **Sent to Razorpay:** `Money::toPaise()` converts to paise
   (rupees × 100, e.g. `"2698.50"` → `269850`).

| Item | Price | Qty | Subtotal |
|---|---|---|---|
| Amber & Sandalwood (Small) | 899.50 | 3 | 2698.50 |
| Vanilla (Mini) | 450.00 | 1 | 450.00 |
| **Order total = payment amount** | | | **3148.50** (314850 paise) |

## 7. The payment record and Razorpay

### 7.1 One row per payment attempt

Each `payments` row is one Razorpay payment link. An order can have
several rows: for example a failed attempt, then a retry.

| Column | Purpose |
|---|---|
| `payment_number` | Our id (`PAY` + ULID), sent to Razorpay as the link's `reference_id` |
| `provider` | Which payment company handled it. Always `razorpay` today. It says whose format `transaction_id`, `payment_session_id` and `gateway_response` are in (and whose dashboard to check), keeps old rows readable after a provider change, and is the key a strategy factory would switch on if a second provider is approved (architecture guide, section 26.9) |
| `status` | `pending`, `processing`, `paid`, `failed`, `cancelled`, `refunded` |
| `method` | `card`, `upi`, `netbanking`, `wallet`, `emi`; null until paid |
| `amount`, `currency` | The order total, `INR` |
| `payment_session_id` | Razorpay payment link id (`plink_…`) |
| `payment_url` | Razorpay's hosted payment page |
| `expires_at` | When the link stops working |
| `transaction_id` | Razorpay payment id, once paid (webhook) |
| `paid_at`, `failed_at`, `refunded_at`, `failure_reason` | Outcome |
| `gateway_response` | Raw Razorpay response; hidden from API responses |

**Why `payment_url` is stored:**
- **Reuse:** pressing Pay again returns the same link without calling
  Razorpay (section 4.3).
- **"Still being created":** a pending payment with no URL is how a
  checkout still waiting for Razorpay (409) is told apart from a payable
  one.
- **Later uses:** a "Complete payment" action on a pending order, and
  support can see exactly which link the customer was sent.

It is not a secret: anyone with the link can pay this order, which is the
point of a link. It stops working at `expires_at`.

### 7.2 The order's payment status: `Order::latestPayment()`

```php
public function latestPayment(): HasOne
{
    return $this->hasOne(Payment::class)->latestOfMany('id');
}
```

The newest payment attempt's status is the order's payment status. It is
read through this relation, and `orders` keeps no copy, for three
reasons:
- **One source of truth:** a copied column would have to be updated on
  every payment change (webhook, failure, expiry, refund), and missing one
  would show the wrong status.
- **Retries:** an order can have several payments; the newest one is what
  counts.
- **Cheap lists:** `Order::with('latestPayment')` loads every status in
  one extra query.

To filter by payment status, use `whereHas('latestPayment', …)`. Add a
column only if that turns out to be slow.

### 7.3 What is sent to Razorpay

`POST {RAZORPAY_BASE_URL}/payment_links`, basic auth with the key id and
secret:

| Field | Value |
|---|---|
| `amount` | Total in paise |
| `currency` | `INR` |
| `accept_partial` | `false` |
| `reference_id` | `payment_number` |
| `description` | "Artistic Hub order ORD-…" |
| `customer` | Name, email and phone from the order snapshot |
| `notify` | SMS and email off (we send our own confirmation) |
| `reminder_enable` | `false` |
| `expire_by` | Now + `RAZORPAY_LINK_EXPIRY_MINUTES` (30; Razorpay's minimum is 15) |
| `callback_url` | `RAZORPAY_CALLBACK_URL?order=<order_number>` |
| `callback_method` | `get` |
| `notes` | `order_number`, `payment_number` |

Every Razorpay error (refused request, missing fields, unreachable) is
turned into `PaymentGatewayUnavailable`, which answers 503.

The callback is only a way back to the shop. Razorpay adds its
`razorpay_*` query parameters (including
`razorpay_payment_link_status=paid`), but those never confirm an order:
only the verified webhook does.

### 7.4 Razorpay API calls and references

| Call | Request | Razorpay docs |
|---|---|---|
| Create link (`createPaymentLink`) | `POST https://api.razorpay.com/v1/payment_links` | https://razorpay.com/docs/api/payments/payment-links/create-standard/ |
| Cancel link (`cancelPaymentLink`) | `POST https://api.razorpay.com/v1/payment_links/{id}/cancel` (no body; `{id}` is `payments.payment_session_id`) | https://razorpay.com/docs/api/payments/payment-links/cancel-standard/ |
| Webhook signature | HMAC-SHA256 of the raw body, `X-Razorpay-Signature` header | https://razorpay.com/docs/webhooks/validate-test/ |
| Webhook payloads | `payment_link.paid`, `expired`, `cancelled` | https://razorpay.com/docs/webhooks/payloads/payment-links/ |

All calls use basic auth with `RAZORPAY_KEY_ID:RAZORPAY_KEY_SECRET`. The
URL is the same in test and live mode; the keys decide which.

Cancel succeeds only while the link is `created` (unpaid). Razorpay
answers 400 when the link:
- is already paid or partially paid;
- has already expired or been cancelled;
- does not exist, or belongs to another account.

It may also answer 400 "an update is already in progress" while it holds
a short lock on the link. `cancelOlderLinks()` logs any of these and
moves on (section 4.5).

## 8. Order details and the result page: `GET /customer/orders/{order_number}`

Razorpay returns the customer to the frontend result page with
`?order=<order_number>`, and the page calls this endpoint. It returns the
customer's order in any status, including pending and cancelled
checkouts. Another customer's order number, or a malformed one, is 404.

```json
{
  "data": {
    "order_number": "ORD-20261003-7K2Q9M",
    "status": "pending",
    "placed_at": null,
    "created_at": "…",
    "cancelled_at": null,
    "cancellation_reason": null,
    "payment": {
      "payment_number": "PAY01K6…",
      "status": "pending",
      "method": null,
      "amount": "1799.00",
      "currency": "INR",
      "can_pay": true,
      "payment_url": "https://rzp.io/i/…",
      "expires_at": "…",
      "paid_at": null
    },
    "fare_breakup": { "subtotal": "1799.00", "discount_amount": "0.00", "shipping_amount": "0.00", "total_amount": "1799.00" },
    "customer": { "name": "…", "email": "…", "phone": "…" },
    "shipping_address": { "recipient_name": "…", "phone": "…", "address_line_1": "…", "...": "…" },
    "tracking": { "provider": null, "number": null },
    "items": [ { "product_name": "…", "variant_name": "…", "sku": "…", "photo_url": "…", "original_unit_price": "…", "selling_unit_price": "…", "quantity": 2, "subtotal": "…", "discount_amount": "…", "total_amount": "…" } ]
  }
}
```

- **`status`** is the order status. **`payment`** is the newest payment
  attempt (`latestPayment`).
- **`can_pay`** is true only while the link can still be paid.
  **`payment_url`** is filled only then: show "Complete payment".
- **Items** show the snapshot. Later catalog changes never appear here.

What the result page shows:

| `status` | `payment.status` | Show |
|---|---|---|
| `pending` | `pending` | "Confirming your payment…": poll every few seconds (limited), then offer Check Again |
| `confirmed` | `paid` | Order placed: number, summary, View Order, Continue Shopping |
| `cancelled` | `failed` | Payment could not be started; offer Pay again from the cart |
| `pending` | `pending`, `can_pay` false | Link expired; offer Pay again from the cart |

Razorpay usually calls the webhook within seconds, but it can arrive
after the customer is back, so poll while both are `pending`. The
`razorpay_payment_link_status=paid` query parameter may be used for the
"confirming" message, never as proof of payment.

## 9. Order list: `GET /customer/orders`

Built 2026-10-03.

- **Placed orders only** (`placed_at` set, i.e. payment confirmed), newest
  `placed_at` first.
- **Not listed:** pending and failed checkouts, because they are not
  orders yet. They are still reachable by number with
  `GET /customer/orders/{order_number}` (the result page).
- **Still listed:** an order cancelled after it was placed.
- **Pagination:** `?page=` and `?per_page=` (default 10, max 50), with the
  usual `data`, `links` and `meta`. No filters for now.

Each row is a summary for an order card:

```json
{
  "id": 12,
  "order_number": "ORD-20261003-7K2Q9M",
  "status": "confirmed",
  "payment_status": "paid",
  "placed_at": "…",
  "total_amount": "1799.00",
  "item_count": 2,
  "line_count": 1,
  "first_item": { "product_name": "Amber & Sandalwood", "variant_name": "Small", "photo_url": "…" }
}
```

- **`item_count`** is the total number of units.
- **`line_count`** is the number of lines. The card can say "Amber &
  Sandalwood and N more" with `line_count − 1`.
- **Details:** open the order by `order_number` for everything else.

## 10. Razorpay webhook: `POST /api/v1/webhooks/razorpay`

Built 2026-10-03. Razorpay calls it; the frontend never does. It is the
only thing that confirms an order: the browser redirect never does. It is
in `routes/api.php` with no sign-in, and is left out of the API docs
(`#[ExcludeRouteFromDocs]`).

### 10.1 Verification

Razorpay signs the raw request body with HMAC-SHA256, using the webhook
secret set in its dashboard, and sends the result in the
`X-Razorpay-Signature` header. `RazorpayWebhook::verify()` recomputes it
with `RAZORPAY_WEBHOOK_SECRET` and compares with `hash_equals`.

- **Wrong or missing signature, or no secret configured:** 400, and
  nothing changes.
- **Raw body:** the body is checked exactly as received. Decoding and
  re-encoding the JSON would change it.

### 10.2 Events handled

Enable these three events on the webhook in the Razorpay dashboard:

| Event | What happens |
|---|---|
| `payment_link.paid` | Capture (section 10.3) |
| `payment_link.expired` | `PaymentCancellationService::cancelPending()`: if the payment is still `pending`, payment `cancelled`, and the order `cancelled` (if still pending) with "The payment link expired." |
| `payment_link.cancelled` | Same, with "The payment link was cancelled." |

- **Other events,** and payloads missing the fields we need, are answered
  200 and ignored.
- **Links we did not create** are answered 200, logged, and ignored. A
  link is ours when `payment_session_id` matches the link id and the
  link's `reference_id` equals our `payment_number`.
- **200 tells Razorpay to stop retrying.** Any unexpected error is a 500,
  and Razorpay retries it.

`RazorpayWebhook::toEvent()` maps the payload to a `PaymentLinkEvent` DTO:

| DTO field | Source |
|---|---|
| `type` | The event name |
| `linkId` | `payload.payment_link.entity.id` |
| `referenceId` | `payload.payment_link.entity.reference_id` |
| `paymentId` | `payload.payment.entity.id` (only for `paid`; required) |
| `method` | `payload.payment.entity.method` (only for `paid`) |

`PaymentCaptureService` only ever sees this DTO, never the HTTP request.

### 10.3 Capture (`payment_link.paid`)

Inside one transaction, locking in the same order as checkout and the
cart (profile, then payment, order, and variants by id):

1. **Already `paid`?** Do nothing. Razorpay retries webhooks, so a repeat
   is expected, and stock, cart and email happen exactly once.
2. **Deduct stock** for each line whose variant still exists, down to a
   floor of 0.
3. **Remove the paid quantities from the cart.** Each cart line is reduced
   by the paid quantity and deleted at zero. Items added after checkout
   stay.
4. **Payment:** `paid`, with `transaction_id` (the Razorpay payment id),
   `method` (`card`, `upi`, `netbanking`, `wallet` or `emi`; null for
   anything else), `paid_at`, and the payment entity added to
   `gateway_response` under `payment`.
5. **Order:** `confirmed`, `placed_at = now`. `cancelled_at` and
   `cancellation_reason` are cleared, because an order cancelled as
   "replaced by a newer checkout" can still be paid if the cancel lost
   the race (section 4.5).

After the commit, `OrderConfirmed` is queued to `customer_email`.

The paid amount is not re-checked (decided 2026-10-03): we set the link's
amount ourselves and `accept_partial` is false, so Razorpay can only
collect exactly that amount.

### 10.4 Paid when stock ran short (decided 2026-10-03)

There is no stock reservation, so the stock can run out between Pay and
the payment. The order is still confirmed, because the customer paid:

- **Stock** is deducted down to 0.
- **The order** gets a `review_reason`, for example "Paid when stock was
  short: Amber & Sandalwood (Small) ordered 3, had 1. Restock or refund."
- **An admin** restocks or refunds by hand (refunds are not automated).

`review_reason` is an admin-only column. It is never shown to customers,
and is null when there is nothing to check.

### 10.5 Confirmation email

`App\Mail\OrderConfirmed` is queued (`ShouldQueue`), so a queue worker
must run (`php artisan queue:work`; `setup.md`, section 5c). It is built
only from the order's snapshots:
- the order number;
- the items with quantity and amount, and the total ("Prices include
  GST");
- the delivery address;
- a "View your order" button linking to
  `FRONTEND_URL/account/orders/{order_number}`.

Locally `MAIL_MAILER=log`, so it is written to
`storage/logs/laravel.log`.

## 11. Scenarios: what the data looks like

One example throughout. Asha's cart holds 2 × Amber & Sandalwood (Small):
variant 14, ₹899.50 each, stock 10. The total is **1799.00**, and links
expire after 30 minutes. Only the columns that change are shown.
`gateway_response` is left out.

### 11.1 Paid normally

**10:00, Pay pressed** (`POST /customer/checkout` → 201)

| Table | Data |
|---|---|
| orders | `ORD-20261003-7K2Q9M`, status `pending`, total `1799.00`, placed_at null |
| order_items | Amber & Sandalwood / Small, qty 2, selling_unit_price `899.50`, total `1799.00` |
| payments | `PAY01K6…`, status `pending`, payment_session_id `plink_A`, payment_url `https://rzp.io/i/A`, expires_at 10:30, transaction_id null, method null |
| Stock / cart | Stock 10; cart 2 × Amber (unchanged) |

**10:05, paid by UPI** (webhook `payment_link.paid`)

| Table | Data |
|---|---|
| orders | status `confirmed`, placed_at 10:05 |
| payments | status `paid`, transaction_id `pay_X`, method `upi`, paid_at 10:05 |
| Stock / cart | Stock **8**; cart **empty** |
| Email | `OrderConfirmed` queued once |

The API now returns: result page `status: confirmed`, `payment.status:
paid`, `can_pay: false`, `payment_url: null`. The order appears in
`GET /customer/orders`.

### 11.2 Pay pressed twice (same cart and address)

No new rows. The second call answers **200** with the same
`order_number` and `payment_url`, and Razorpay is not called again.

### 11.3 Card declined, then paid by UPI

| When | Data |
|---|---|
| After the decline | Nothing changes. The order and payment stay `pending`, and `can_pay` is still true; the customer retries on the same Razorpay page. Razorpay's `payment.failed` is ignored. |
| After the UPI payment | Same as 11.1 at 10:05 |

### 11.4 Abandoned: link expires unpaid

**10:30, webhook `payment_link.expired`**

| Table | Data |
|---|---|
| orders | status `cancelled`, cancelled_at 10:30, cancellation_reason "The payment link expired.", placed_at null |
| payments | status `cancelled` |
| Stock / cart | Stock 10; cart 2 × Amber (unchanged) |

The API returns `cancelled` / `cancelled`, with `can_pay: false`. The
order is not in the order list. Pressing Pay again creates a new order.

If the webhook never arrives, both stay `pending`, but `can_pay` turns
false at 10:30.

### 11.5 Razorpay down when Pay is pressed

`POST /customer/checkout` answers **503**.

| Table | Data |
|---|---|
| orders | status `cancelled`, cancelled_at 10:00, cancellation_reason "The payment could not be started." |
| payments | status `failed`, failed_at 10:00, failure_reason "Razorpay refused the payment link (HTTP 401): …", payment_session_id null, payment_url null |
| Stock / cart | Unchanged |

Pressing Pay again creates a new order and payment.

### 11.6 Cart changed, then Pay again

At 10:00 order 1 is created with `plink_A`. The customer adds a third
Amber and presses Pay again at 10:10:

| Row | Data |
|---|---|
| Order 1 | status `cancelled`, cancellation_reason "Replaced by a newer checkout." (link A cancelled on Razorpay) |
| Payment 1 | status `cancelled` |
| Order 2 | status `pending`, total `2698.50` |
| Payment 2 | status `pending`, `plink_B`, expires 10:40 |

Razorpay then sends `payment_link.cancelled` for A. Payment 1 is no
longer pending, so nothing changes.

### 11.7 The old tab pays at the same moment

As in 11.6, but link A was paid just before the cancel. Razorpay refuses
the cancel ("already paid"); this is logged, and order 1 stays `pending`.
The webhook then confirms order 1:

| Row | Data |
|---|---|
| Order 1 | `confirmed`, placed_at set; cancelled_at and cancellation_reason cleared if they had been set |
| Payment 1 | `paid` |
| Stock / cart | Stock 10 → 8; 2 Ambers leave the cart (1 left) |
| Order 2 / payment 2 | Still `pending` with link B. If that is paid too, it becomes a second confirmed order. Both were paid, so both are real. |

### 11.8 Paid when stock ran short

Between 10:00 and 10:05 another customer buys 9, so stock is 1 when the
payment arrives.

| Table | Data |
|---|---|
| orders | `confirmed`, placed_at 10:05, review_reason "Paid when stock was short: Amber & Sandalwood (Small) ordered 2, had 1. Restock or refund." |
| payments | `paid` |
| Stock / cart | Stock **0** (never negative); cart empty |
| Email | Sent as normal |

### 11.9 Razorpay sends the same webhook twice

The payment is already `paid`, so nothing changes: stock stays 8, the
cart is not touched again, and no second email is sent. The answer is 200.

### 11.10 Link cancelled by hand in the Razorpay dashboard

Webhook `payment_link.cancelled`: like 11.4, but with the reason "The
payment link was cancelled."

### 11.11 Items added to the cart after Pay

The cart at payment time is 3 × Amber + 1 Lavender; the order was for
2 × Amber. After the webhook, the cart is 1 × Amber + 1 Lavender.

### 11.12 Summary

| Scenario | orders.status | payments.status | placed_at | In order list | can_pay | Stock | Cart | Email |
|---|---|---|---|---|---|---|---|---|
| Waiting for payment | pending | pending | null | No | true (until expiry) | unchanged | unchanged | — |
| Paid | confirmed | paid | set | Yes | false | − qty | − paid qty | once |
| Paid, stock short | confirmed (+ review_reason) | paid | set | Yes | false | 0 | − paid qty | once |
| Link expired | cancelled ("expired") | cancelled | null | No | false | unchanged | unchanged | — |
| Cancelled in dashboard | cancelled ("was cancelled") | cancelled | null | No | false | unchanged | unchanged | — |
| Replaced by newer checkout | cancelled ("Replaced…") | cancelled | null | No | false | unchanged | unchanged | — |
| Razorpay down at Pay | cancelled ("could not be started") | failed | null | No | false | unchanged | unchanged | — |

## 12. Not built yet

- **Admin order management:** list and detail, processing and completed
  status changes, tracking, and showing `review_reason`.
- **Refunds:** done by hand in the Razorpay dashboard for now.
- **Expired pending orders:** when Razorpay sends `payment_link.expired`
  they are cancelled. If that event is missed, the order stays `pending`
  with `can_pay` false; a scheduled cleanup could cancel those.
