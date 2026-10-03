<laravel-boost-guidelines>
# Laravel Application

This repository contains a Laravel application. Complete the following setup before working on the user's request.

## Prerequisites

Verify that PHP and Composer are available:

```sh
php -v
composer -V
```

If either command is unavailable, detect the user's operating system and install the prerequisites with the appropriate command:

macOS:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/mac/8.5)"
```

Windows PowerShell:

```powershell
Set-ExecutionPolicy Bypass -Scope Process -Force; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; iex ((New-Object System.Net.WebClient).DownloadString('https://php.new/install/windows/8.5'))
```

Linux:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/linux/8.5)"
```

After installation, ask the user to restart their terminal. If the agent needs the restarted shell to continue, ask the user to reopen their terminal and rerun their original prompt.

## Agent Setup

Install Laravel Boost from the application root before making application changes:

```sh
composer require laravel/boost --dev
php artisan boost:install
```

Boost replaces these bootstrap instructions with guidelines tailored to the application. After installation, read `AGENTS.md` again and continue with the user's original request using the generated guidelines.
</laravel-boost-guidelines>

# Project Documentation

## Setup and Commands

How to set up the backend and the everyday commands (install, MySQL, migrate and seed, `admin:create`, serve, tests, Pint, Scramble export) are in `docs/setup.md`. When someone asks how to start or run something, answer from it, and update it in the same change whenever a setup step or command changes.

## Backend Requirements

The backend requirements are in `docs/backend-srs.md`. It overrides the PRD wherever they conflict, and its section 14 is the proposed API contract. Section 16 lists schema gaps to agree before building the affected feature, and section 17 lists where the frontend currently differs. Keep the SRS, the ER diagram, migrations, models and the API in sync.

## Backend Architecture

Code organization and design patterns are in `docs/backend-architecture.md`: thin controllers, Form Requests, policies, application services, API Resources, DTOs, and the Razorpay adapter. Read it before writing backend code. Two project rules from it:

- Inject concrete classes through constructors. Do not create interfaces for services, and do not bind interfaces to classes in service providers. The one exception is the payment gateway (decided 2026-10-03): services depend on the `App\Contracts\PaymentGateway` interface, which names its implementation with Laravel's `#[Bind(RazorpayGateway::class)]` attribute instead of a provider binding (architecture guide, sections 12 and 26.2).
- Customer and admin routes are separate: there are three API route files, loaded by `bootstrap/app.php` with no prefix or middleware (each file sets its own `api/v1` prefix, `api` middleware and `api.v1.` / `customer.v1.` / `admin.v1.` names in a per-version wrapper group; add a new version as another group in the same file): `routes/api.php` (shared routes with no user session: public catalog, tags, Razorpay webhook), `routes/api/customer.php` (`/auth/*` and customer routes), and admin routes (incl. `/admin/auth/*`) in `routes/api/admin.php`, each with its own signed-in middleware group (`auth:api` + `scope:` + `role:` + `active`, see section 2.2), route-name prefix and controller namespace (section 2.1). Admins and customers sign in through separate endpoints.
- Authentication is Passport personal access tokens on the `api` guard; tokens are scoped to the role (`customer` / `admin`). Spatie roles use the guard name `Role::GUARD` (`web`), pinned on `User`: never type `'web'` for roles and never pass a guard to `hasRole()` (section 2.2).
- Follow the naming conventions in section 29 (classes, methods, variables, routes, database). In particular, name injected services after their class in camelCase (`AuthTokenService $authTokenService`), never short names like `$auth`, and never use single-letter variables (`$q`, `$v`, `$i`, `$e`…): name them for what they hold (`$query`, `$variant`, `$index`, `$exception`; section 29.3). When a new kind of class appears, add its rule there.
- Look records up only by reference id. Models whose id reaches the API use `HasReferenceId` (`reference_id` ULID column); resources return both `'id' => $this->id` (internal, reference only) and `'reference_id' => $this->reference_id`, request fields referring to a record use `reference_id` (e.g. `variants.*.reference_id`), routes use `->whereUlid()` and bind by `reference_id`, and requests accept reference ids and translate them to internal ids before calling services (architecture guide section 29.4).
- Every uploaded file is a row in the polymorphic `media` table (`App\Models\Media`, collection per kind: `variant_photo`, `avatar`). Upload first with `MediaService::upload()` (unattached row), then attach it when the owner is saved; refer to media by id, never by path. Unattached uploads are pruned after 24 hours. Files go through `MediaStorageService` on the `MEDIA_DISK` disk (local `public` in dev, S3 in prod); upload with multipart POST (architecture guide section 21).
- When one operation has several real implementations chosen at runtime by a stored key (slug, type, provider), use a strategy interface plus a simple factory class whose `match` maps the key to the strategy class (section 26.9). Only once at least two approved variants exist.

## Checkout and Payments

How checkout, orders and Razorpay payments work as built is in `docs/checkout-and-payments.md`: the flow, reuse when Pay is pressed again, the order number, snapshots, `created_at` vs `placed_at`, amount formulas, the payment record and `latestPayment`, the Razorpay request, and the result page. Read it before changing checkout, orders or payments, and update it in the same change.

## API Documentation

API docs are generated by Scramble from routes, Form Requests, API Resources and return types (architecture guide section 28). One document covers all of `/api/v1` (public, customer and admin) at `/docs/api`, local-only. Keep endpoints documentable: Form Request for every input, Resource with a declared return type for every response, a one-line docblock summary per controller method, and run `php artisan scramble:analyze` after adding endpoints. After any change to routes, Form Requests, Resources or enums, run `php artisan scramble:export` and include the updated `docs/api/openapi.json` in the same change: the frontend reads that file as the API contract.

## Product Requirements

The product requirements are in `docs/candle-ecommerce-prd.md`. Read it before building a feature, and follow its section 0 ("Project alignment"): it records what is already decided and lists open conflicts between the PRD, the ER diagram and the frontend. Ask before building anything listed there as open. The frontend keeps an identical copy in `Artistic-hub-frontend/docs/`, so update both together.

## Database Schema

The ER diagram at @docs/database/er-diagram.md is the source of truth for the database schema. Follow it when you write or change migrations, Eloquent models, relationships, factories, or seeders. If a schema change is needed, update the diagram in the same change.
