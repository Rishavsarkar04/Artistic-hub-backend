# Artistic Hub — Backend

Laravel 13 API for the Artistic Hub candle store (admin panel and customer storefront).

**Getting started:** follow [docs/setup.md](docs/setup.md). It covers installing, the MySQL database,
creating the first admin, running the API, and everyday commands.

Quick start once MySQL is set up:

```sh
composer install
cp .env.example .env && php artisan key:generate   # then fill in DB_PASSWORD
php artisan migrate --seed
php artisan admin:create
php artisan serve                                  # API: http://localhost:8000/api/v1, docs: /docs/api
```

## Documentation

| Document | What it covers |
|---|---|
| [docs/setup.md](docs/setup.md) | Setup and everyday commands |
| [docs/backend-srs.md](docs/backend-srs.md) | Backend requirements and API contract |
| [docs/backend-architecture.md](docs/backend-architecture.md) | Code organization and patterns |
| [docs/database/er-diagram.md](docs/database/er-diagram.md) | Database schema |
| [docs/candle-ecommerce-prd.md](docs/candle-ecommerce-prd.md) | Product requirements |
| [docs/api/openapi.json](docs/api/openapi.json) | Generated API spec (read by the frontend) |
