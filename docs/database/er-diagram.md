# Database ER Diagram

Source of truth for the Artistic Hub database schema. Migrations in `database/migrations` and models in `app/Models` should match this diagram. If the schema changes, update this file in the same change.

## Notes

- Roles use `spatie/laravel-permission` tables (`roles`, `model_has_roles`). The two roles are `admin` and `customer`.
- `orders` and `order_items` store **snapshots** of customer, address, product, and price data from the time of the order. Never read these values back from the live product or profile tables.
- `order_items.product_variant_id` is nullable, so order history survives when a variant is deleted.
- `orders.tracking_number` is required before an order moves to `completed`.
- Each customer has at most one cart (`carts.customer_profile_id` is unique), and a variant appears once per cart (`cart_items` is unique on `cart_id` + `product_variant_id`); adding it again increases `quantity`. Cart items store **no prices**: checkout reads the current price, stock and active state and asks the customer to reconfirm if anything changed. Paying clears only the quantities that were paid for. Deleting a variant deletes its cart items (cascade); order items keep their snapshot with `product_variant_id` set to null.
- Money columns are `decimal`. `orders.total_amount = subtotal - discount_amount + shipping_amount`; `order_items.total_amount = subtotal - discount_amount`.

## Diagram

```mermaid
---
config:
  theme: mc
---
erDiagram
    direction TB

    USERS {
        bigint id PK
        string name
        string email UK
        timestamp email_verified_at
        string password
        string status "active, blocked, suspended, or pending"
        timestamp created_at
        timestamp updated_at
    }

    ROLES {
        bigint id PK
        string name UK "admin or customer"
        string guard_name
        timestamp created_at
        timestamp updated_at
    }

    MODEL_HAS_ROLES {
        bigint role_id PK, FK
        bigint model_id PK
        string model_type PK
    }

    CUSTOMER_PROFILES {
        bigint id PK
        bigint user_id FK, UK
        string phone
        date date_of_birth
        string gender
        string avatar_path
        text notes
        timestamp created_at
        timestamp updated_at
    }

    CUSTOMER_ADDRESSES {
        bigint id PK
        bigint customer_profile_id FK
        string label "home, work, or other"
        string recipient_name
        string phone
        string address_line_1
        string address_line_2
        string city
        string state
        string postal_code
        string country
        boolean is_default
        timestamp created_at
        timestamp updated_at
    }

    PRODUCTS {
        bigint id PK
        string name
        string slug UK
        text description
        boolean is_active
        timestamp created_at
        timestamp updated_at
    }

    PRODUCT_VARIANTS {
        bigint id PK
        bigint product_id FK
        string name
        string sku UK
        string slug UK
        decimal original_price
        decimal selling_price
        integer stock
        text description
        boolean is_active
        timestamp created_at
        timestamp updated_at
    }

    PRODUCT_VARIANT_PHOTOS {
        bigint id PK
        bigint product_variant_id FK
        string path
        integer sort_order
        timestamp created_at
        timestamp updated_at
    }

    TAGS {
        bigint id PK
        string name UK
        string slug UK
        timestamp created_at
        timestamp updated_at
    }

    PRODUCT_VARIANT_TAGS {
        bigint product_variant_id PK, FK
        bigint tag_id PK, FK
        timestamp created_at
        timestamp updated_at
    }

    CARTS {
        bigint id PK
        bigint customer_profile_id FK, UK "one cart per customer"
        timestamp created_at
        timestamp updated_at
    }

    CART_ITEMS {
        bigint id PK
        bigint cart_id FK "unique with product_variant_id"
        bigint product_variant_id FK
        integer quantity "at least 1"
        timestamp created_at
        timestamp updated_at
    }

    ORDERS {
        bigint id PK
        bigint customer_profile_id FK
        string order_number UK
        string status "pending, confirmed, processing, completed, cancelled"
        decimal subtotal "sum of item subtotals"
        decimal discount_amount "total item or order discount"
        decimal shipping_amount "delivery fare"
        decimal total_amount "subtotal - discount + shipping"
        string customer_name "customer snapshot"
        string customer_email "customer snapshot"
        string customer_phone "customer snapshot"
        string recipient_name "shipping snapshot"
        string recipient_phone "shipping snapshot"
        string address_line_1 "shipping snapshot"
        string address_line_2 "shipping snapshot"
        string city "shipping snapshot"
        string state "shipping snapshot"
        string postal_code "shipping snapshot"
        string country "shipping snapshot"
        string tracking_provider "nullable; e.g. Delhivery"
        string tracking_number "nullable; required when completed"
        text notes
        string cancellation_reason "nullable"
        timestamp confirmed_at "nullable"
        timestamp completed_at "nullable"
        timestamp cancelled_at "nullable"
        timestamp placed_at
        timestamp created_at
        timestamp updated_at
    }

    ORDER_ITEMS {
        bigint id PK
        bigint order_id FK
        bigint product_variant_id FK "nullable; null if variant is deleted"
        string product_name "snapshot"
        string variant_name "snapshot"
        string sku "snapshot"
        string variant_photo_path "snapshot; nullable"
        decimal original_unit_price "snapshot"
        decimal selling_unit_price "snapshot"
        integer quantity
        decimal subtotal "selling_unit_price x quantity"
        decimal discount_amount
        decimal total_amount "subtotal - discount"
        timestamp created_at
        timestamp updated_at
    }

    PAYMENTS {
        bigint id PK
        bigint order_id FK
        string payment_number UK
        string method "cod, card, upi, bank_transfer, wallet"
        string provider "razorpay, stripe, cash, etc.; nullable"
        string status "pending, processing, paid, failed, cancelled, refunded"
        decimal amount
        string currency
        string transaction_id UK "nullable"
        string payment_session_id UK "nullable"
        timestamp paid_at "nullable"
        timestamp failed_at "nullable"
        timestamp refunded_at "nullable"
        text failure_reason "nullable"
        json gateway_response "nullable"
        timestamp created_at
        timestamp updated_at
    }

    USERS ||--o| CUSTOMER_PROFILES : has
    CUSTOMER_PROFILES ||--o{ CUSTOMER_ADDRESSES : saves
    USERS ||--o{ MODEL_HAS_ROLES : assigned
    ROLES ||--o{ MODEL_HAS_ROLES : grants

    PRODUCTS ||--|{ PRODUCT_VARIANTS : has
    PRODUCT_VARIANTS ||--o{ PRODUCT_VARIANT_PHOTOS : has
    PRODUCT_VARIANTS ||--o{ PRODUCT_VARIANT_TAGS : tagged
    TAGS ||--o{ PRODUCT_VARIANT_TAGS : attached

    CUSTOMER_PROFILES ||--o| CARTS : keeps
    CARTS ||--o{ CART_ITEMS : contains
    PRODUCT_VARIANTS ||--o{ CART_ITEMS : added_as

    CUSTOMER_PROFILES ||--o{ ORDERS : places
    ORDERS ||--|{ ORDER_ITEMS : contains
    PRODUCT_VARIANTS o|--o{ ORDER_ITEMS : ordered_as
    ORDERS ||--o{ PAYMENTS : has
```
