# Checkout, Orders and Payments

How checkout works as built: from the cart to a Razorpay payment link, the
order and payment records it creates, how amounts are calculated, and what
the payment result page reads. Decisions are dated; requirements stay in
`backend-srs.md` (section 9), the schema in `database/er-diagram.md`.

Status (2026-10-03): review, checkout (payment link) and order details are
built. The Razorpay webhook that confirms payment is **not built yet**, so
every order stays `pending` even after a successful payment.

## 1. The flow

| # | Customer | Frontend calls | Backend |
|---|---|---|---|
| 1 | Opens the cart | `GET /customer/cart` | Items at current prices, `fare_breakup`, item issues |
| 2 | Picks an address | `GET /customer/addresses` | Saved addresses |
| 3 | Review page | `GET /customer/checkout/review?address_id=` | Runs every checkout check, changes nothing |
| 4 | Presses Pay | `POST /customer/checkout { address_id }` | Pending order + payment, Razorpay link, returns `payment_url` |
| 5 | Pays | Redirect the browser to `payment_url` | — (Razorpay's hosted page) |
| 6 | Payment succeeds | — | Webhook (to build): order confirmed, `placed_at` set, stock deducted once, paid quantities removed from the cart, one email |
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
| Routes | `routes/api/customer.php` (`checkout.review`, `checkout.store`, `orders.show`) |
| Checkout logic | `app/Services/Checkout/CheckoutService.php` (`review()`, `checkout()`) |
| Order details | `app/Services/Orders/CustomerOrderService.php` (`getOrder()`) |
| Payment provider contract | `app/Contracts/PaymentGateway.php` (interface, `#[Bind(RazorpayGateway::class)]`) |
| Razorpay calls | `app/Integrations/Razorpay/RazorpayGateway.php` (REST API via Laravel's HTTP client) |
| Order number | `app/Support/OrderNumber.php` |
| Money arithmetic | `app/Support/Money.php` (bcmath on strings, never floats) |
| Responses | `CheckoutReviewResource`, `CheckoutPaymentResource`, `CustomerOrderResource`, `OrderItemResource` |
| Errors | `app/Exceptions/Checkout/PaymentGatewayUnavailable.php` (503), `CheckoutInProgress.php` (409) |
| Settings | `config/services.php` → `razorpay` (see `setup.md`, section 5c) |
| Tests | `tests/Feature/Customer/CheckoutReviewTest.php`, `CheckoutTest.php`, `OrderTest.php`, `tests/Unit/OrderNumberTest.php` |

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

### 4.5 Older pending orders

When the purchase changed, the earlier pending order is left as it is.
Its link stays payable on Razorpay until it expires (at most 30 minutes),
so a customer with the old tab still open could pay both.

Open point for the webhook step: cancel older links when a new checkout
starts, or handle the late payment in the webhook.

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
| `cancelled` | The payment could not be started, or cancelled later |

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

Until the webhook is built, every order stays `pending`. The
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

Until the webhook is built, no order gets `placed_at`, so **the list is
always empty**. Tests set `placed_at` and the statuses directly to stand
in for the webhook.

## 10. Still to build

- **The webhook** (`payment_link.paid` / `payment.captured`, signature
  verified with `RAZORPAY_WEBHOOK_SECRET`). It should:
  - mark the payment `paid` (`transaction_id`, `method`, `paid_at`);
  - mark the order `confirmed` and set `placed_at`;
  - deduct stock once;
  - remove the paid quantities from the cart;
  - send one confirmation email.

  It must be idempotent: Razorpay retries webhooks.
- **Open decisions:**
  - A payment confirmed after the stock ran out: refund it, or accept the
    order anyway?
  - An older pending link paid after a newer checkout (section 4.5).
