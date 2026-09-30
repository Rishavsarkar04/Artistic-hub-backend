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

## Product Requirements

The product requirements are in `docs/candle-ecommerce-prd.md`. Read it before building a feature, and follow its section 0 ("Project alignment"): it records what is already decided and lists open conflicts between the PRD, the ER diagram and the frontend. Ask before building anything listed there as open. The frontend keeps an identical copy in `Artistic-hub-frontend/docs/`, so update both together.

## Database Schema

The ER diagram at @docs/database/er-diagram.md is the source of truth for the database schema. Follow it when you write or change migrations, Eloquent models, relationships, factories, or seeders. If a schema change is needed, update the diagram in the same change.
