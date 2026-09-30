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

## Backend Requirements

The backend requirements are in `docs/backend-srs.md`. It overrides the PRD wherever they conflict, and its section 14 is the proposed API contract. Section 16 lists schema gaps to agree before building the affected feature, and section 17 lists where the frontend currently differs. Keep the SRS, the ER diagram, migrations, models and the API in sync.

## Backend Architecture

Code organization and design patterns are in `docs/backend-architecture.md`: thin controllers, Form Requests, policies, application services, API Resources, DTOs, and the Razorpay adapter. Read it before writing backend code. Two project rules from it:

- Inject concrete classes through constructors. Do not create interfaces for services or the payment gateway, and do not bind interfaces to classes in service providers for now.
- Customer and admin routes are separate: there are three API route files, loaded by `bootstrap/app.php` with no prefix or middleware (each file sets its own `api/v1` prefix, `api` middleware and `api.v1.` / `customer.v1.` / `admin.v1.` names in a per-version wrapper group; add a new version as another group in the same file): `routes/api.php` (shared routes with no user session: public catalog, tags, Razorpay webhook), `routes/api/customer.php` (`/auth/*` and customer routes), and admin routes (incl. `/admin/auth/*`) in `routes/api/admin.php`, each with its own `auth:sanctum` + `role:` middleware group, route-name prefix and controller namespace (section 2.1). Admins and customers sign in through separate endpoints.
- When one operation has several real implementations chosen at runtime by a stored key (slug, type, provider), use a strategy interface plus a simple factory class whose `match` maps the key to the strategy class (section 26.9). Only once at least two approved variants exist.

## Product Requirements

The product requirements are in `docs/candle-ecommerce-prd.md`. Read it before building a feature, and follow its section 0 ("Project alignment"): it records what is already decided and lists open conflicts between the PRD, the ER diagram and the frontend. Ask before building anything listed there as open. The frontend keeps an identical copy in `Artistic-hub-frontend/docs/`, so update both together.

## Database Schema

The ER diagram at @docs/database/er-diagram.md is the source of truth for the database schema. Follow it when you write or change migrations, Eloquent models, relationships, factories, or seeders. If a schema change is needed, update the diagram in the same change.
