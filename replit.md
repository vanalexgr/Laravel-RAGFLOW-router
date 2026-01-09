# Laravel Application

## Overview
This is a Laravel 12 PHP web application running on Replit.

## Project Structure
- `app/` - Application core code (Controllers, Models, Services)
- `bootstrap/` - Framework bootstrap files
- `config/` - Configuration files
- `database/` - Migrations, seeders, SQLite database
- `public/` - Web server entry point and assets
- `resources/` - Views, CSS, JavaScript
- `routes/` - Route definitions
- `storage/` - Logs, cache, compiled files
- `tests/` - PHPUnit tests
- `vendor/` - Composer dependencies

## Development Commands
- `php artisan serve --host=0.0.0.0 --port=5000` - Start development server
- `php artisan migrate` - Run database migrations
- `php artisan make:controller ControllerName` - Create a controller
- `php artisan make:model ModelName -m` - Create a model with migration
- `php artisan tinker` - Interactive PHP shell

## Database
Currently using SQLite at `database/database.sqlite`

## Recent Changes
- Initial Laravel 12 project setup
