# Backend setup and everyday commands

How to get the Artistic Hub backend (Laravel 13 API) running on a new
machine, and the commands used day to day. Run every command from
`Artistic-hub-backend/` unless stated otherwise.

## 1. Requirements

| Tool | Version | Check |
|---|---|---|
| PHP | 8.3+ with `pdo_mysql` | `php -v`, `php -m \| grep pdo_mysql` |
| Composer | 2.x | `composer -V` |
| MySQL | 8.x, running | `systemctl is-active mysql` |
| Node.js | Only for the frontend (see section 9) | `node -v` |

## 2. Install dependencies

```sh
composer install
```

## 3. Environment file

```sh
cp .env.example .env
php artisan key:generate
```

`.env` is not committed. The defaults that matter:

```
APP_ENV=local          # API docs at /docs/api only open in local
APP_URL=http://localhost:8000
DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=artistic_hub
DB_USERNAME=           # fill in (step 4)
DB_PASSWORD=           # fill in (step 4)
```

## 4. Create the MySQL database and user

Once per machine. On Ubuntu, root usually signs in only through `sudo`:

```sh
sudo mysql
```

```sql
CREATE DATABASE artistic_hub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'artistic_hub'@'localhost' IDENTIFIED BY 'choose-a-password';
GRANT ALL PRIVILEGES ON artistic_hub.* TO 'artistic_hub'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Put the same user and password in `.env` (`DB_USERNAME`, `DB_PASSWORD`),
then check the connection:

```sh
php artisan db:show
```

## 5. Create the tables and roles

```sh
php artisan migrate --seed
```

- `migrate` creates every table (users, roles, customer profiles and
  addresses, Laravel's sessions/cache/jobs tables).
- `--seed` runs `RoleSeeder`, which creates the two roles: `admin` and
  `customer`. It is safe to run again: `php artisan db:seed`.

## 5a. Set up sign-in tokens (Passport)

Once per machine, after the tables exist:

```sh
php artisan passport:keys
php artisan passport:client --personal --provider=users --name="Artistic Hub personal access client"
```

- `passport:keys` writes the signing keys to `storage/oauth-private.key`
  and `storage/oauth-public.key`. They are not committed. In production,
  put them in `.env` as `PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY`.
- `passport:client --personal` creates the client every sign-in token is
  issued from. Run it again after `migrate:fresh`.
- Token lifetimes (minutes) can be changed in `.env`:
  `CUSTOMER_TOKEN_LIFETIME_MINUTES` (default 7 days) and
  `ADMIN_TOKEN_LIFETIME_MINUTES` (default 2 days).

## 5b. Uploaded images

**PHP upload limits.** PHP rejects large uploads before Laravel sees them.
The app accepts avatars up to 2 MB and up to 8 product photos of 5 MB in
one request, so set in `php.ini` (CLI and PHP-FPM; and the web server's
body limit, e.g. nginx `client_max_body_size 50M;`):

```ini
upload_max_filesize = 5M
post_max_size = 48M
max_file_uploads = 20
```

Check with `php -i | grep -E "upload_max_filesize|post_max_size"`. The PHP
defaults (2M / 8M) reject a single photo over 2 MB.

Avatars (and later product photos) are stored on the disk named by
`MEDIA_DISK` in `.env`.

**Local development** (`MEDIA_DISK=public`): files go to
`storage/app/public` and are served at `http://localhost:8000/storage/...`.
Create the link once:

```sh
php artisan storage:link
```

**Production** (`MEDIA_DISK=s3`): set `AWS_ACCESS_KEY_ID`,
`AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, and optionally
`AWS_URL` (a CloudFront/CDN URL). The app does not set per-object ACLs;
make the image folders publicly readable with a bucket policy (leave
"Object Ownership: bucket owner enforced" on, and allow this policy under
"Block public access"):

```json
{
  "Version": "2012-10-17",
  "Statement": [{
    "Sid": "PublicReadMedia",
    "Effect": "Allow",
    "Principal": "*",
    "Action": "s3:GetObject",
    "Resource": ["arn:aws:s3:::YOUR_BUCKET/avatars/*", "arn:aws:s3:::YOUR_BUCKET/variant-photos/*"]
  }]
}
```

The folders listed in `Resource` must match the folders of
`app/Enums/MediaCollection.php` (`directory()`); add both together.

**Unused uploads.** A photo uploaded in a product form that is never saved
is deleted, with its file, after `MEDIA_ORPHAN_HOURS` (default 24) by the
daily `model:prune` task. In production the Laravel scheduler must run
every minute (cron):

```
* * * * * cd /path/to/Artistic-hub-backend && php artisan schedule:run >> /dev/null 2>&1
```

Run it by hand any time with `php artisan model:prune --model="App\Models\Media"`.

The IAM user for `AWS_ACCESS_KEY_ID` needs `s3:PutObject`, `s3:GetObject`
and `s3:DeleteObject` on the bucket.

## 5c. Payments (Razorpay)

Checkout creates a Razorpay Payment Link and the frontend redirects the
customer to it. Locally, use **test mode** keys from the Razorpay dashboard
(Account & Settings → API Keys); live keys only in production. Never commit
them.

```env
RAZORPAY_KEY_ID=rzp_test_xxxxxxxx
RAZORPAY_KEY_SECRET=xxxxxxxx
RAZORPAY_WEBHOOK_SECRET=
RAZORPAY_CALLBACK_URL=http://localhost:5173/checkout/result
RAZORPAY_LINK_EXPIRY_MINUTES=30
```

- `RAZORPAY_CALLBACK_URL` is the frontend page Razorpay returns the
  customer to; the backend adds `?order=<order_number>`. It only shows the
  result: the order is confirmed by the webhook, never by this redirect.
- `FRONTEND_URL` (top of `.env`) is the storefront, used for the "View
  your order" link in the confirmation email.
- `RAZORPAY_LINK_EXPIRY_MINUTES` must be at least 15 (Razorpay's minimum).
- Without keys, `POST /customer/checkout` answers 503 and cancels the
  pending order. Tests never call Razorpay (they use `Http::fake`).

**Webhook (confirms payments).** In the Razorpay dashboard (test mode),
go to Accounts & Settings → Webhooks → Add New Webhook:
- **URL:** `https://<your-api-host>/api/v1/webhooks/razorpay`. Razorpay
  must be able to reach it. Locally, expose `php artisan serve` with a
  tunnel such as ngrok, or orders stay `pending`.
- **Secret:** any long random string. Put the same value in
  `RAZORPAY_WEBHOOK_SECRET`. Without it every webhook is refused (400).
- **Events:** `payment_link.paid`, `payment_link.expired`,
  `payment_link.cancelled`.

**Queue worker (emails).** The order confirmation and password reset emails are queued
(`QUEUE_CONNECTION=database`), so keep a worker running:

```sh
php artisan queue:work
```

With `MAIL_MAILER=log` the email is written to `storage/logs/laravel.log`.

## 6. Create the first admin

There is no admin sign-up page and no default admin password. Create one
from the terminal; it asks for name, email and password (hidden):

```sh
php artisan admin:create
```

The password needs at least 12 characters with letters and numbers.
Run it again for each extra admin.

## 7. Run the API

```sh
php artisan serve
```

- API base: `http://localhost:8000/api/v1` (admin routes under
  `/api/v1/admin`).
- API docs: <http://localhost:8000/docs/api> (JSON at `/docs/api.json`).
- Sign in: `POST /api/v1/admin/auth/login` (admins) or `POST /api/v1/auth/login`
  (customers) with `email` and `password`; use the returned `access_token`
  as `Authorization: Bearer <token>`.

Leave it running while the frontend is in use.

## 8. Everyday commands

| Task | Command |
|---|---|
| Run all tests | `php artisan test` |
| Run one test file | `php artisan test tests/Feature/Accounts/AccountModelsTest.php` |
| Format code (Pint) | `vendor/bin/pint` |
| List routes | `php artisan route:list --path=api` |
| New migration ran? Apply it | `php artisan migrate` |
| Start the database from scratch (deletes all data) | `php artisan migrate:fresh --seed`, then `php artisan passport:client --personal --provider=users` and `php artisan admin:create` |
| Explore data | `php artisan tinker` |
| Delete old unused uploads now | `php artisan model:prune --model="App\Models\Media"` |
| See scheduled tasks | `php artisan schedule:list` |
| Check what the docs could not infer | `php artisan scramble:analyze` |
| Update the API contract for the frontend | `php artisan scramble:export` (writes `docs/api/openapi.json`; commit it with the endpoint change) |
| Clear cached config/routes after `.env` changes | `php artisan optimize:clear` |

Tests use an in-memory SQLite database (`phpunit.xml`), so they never
touch the MySQL data.

## 9. Run the frontend against this API

In `Artistic-hub-frontend/`:

```sh
cp .env.example .env    # VITE_API_BASE_URL=http://localhost:8000/api/v1
npx -y pnpm@10.34.3 install
npm run dev
```

Restart `npm run dev` after changing `.env`. Note: most frontend screens
still use mock data until their endpoints are built.

## 10. Troubleshooting

| Problem | Fix |
|---|---|
| `Access denied for user` | Wrong `DB_USERNAME` / `DB_PASSWORD`, or the user was not created (step 4). |
| `Unknown database 'artistic_hub'` | Create it (step 4). |
| `No application encryption key` | `php artisan key:generate` |
| `/docs/api` returns 403 | `APP_ENV` is not `local`. |
| Upload fails with 413, or Laravel says the file "failed to upload" | PHP or web-server limits too low; see "PHP upload limits" in step 5b. |
| Avatar URL returns 404 locally | Run `php artisan storage:link`; check `APP_URL` matches the server address. |
| Upload fails with an S3 error | Check the `AWS_*` values and the IAM permissions (step 5b). |
| Roles missing after a reset | `php artisan db:seed` (or `migrate:fresh --seed`). |
| Sign-in fails with "Personal access client not found" | `php artisan passport:client --personal --provider=users` (step 5a). |
| Sign-in fails with "Key path … does not exist" | `php artisan passport:keys` (step 5a). |
| Every API call returns 401 | The `Authorization: Bearer <token>` header is missing, the token expired, or the user signed out. Sign in again. |
| Changes to `.env` not picked up | `php artisan optimize:clear`, then restart `php artisan serve`. |

## Where to read next

- `docs/backend-srs.md`: what the backend must do.
- `docs/backend-architecture.md`: how the code is organized.
- `docs/database/er-diagram.md`: the database schema.
- `CLAUDE.md`: project rules for Claude.
