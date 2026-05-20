# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

ERP Fineco — Laravel 13 ERP system for employee management with role-based access control and modular architecture. Uses Filament 4.0 for admin panel, Tailwind CSS 4.0 for styling.

**Stack:** PHP 8.3+, Laravel 13, Filament 4.0, MySQL, Vite, Tailwind CSS 4.0

## Development Commands

```bash
# Full project setup (install deps, copy .env, generate key, migrate, seed)
composer setup

# Run all development servers (Laravel + Queue + Logs + Vite)
composer dev

# Run tests
composer test
php artisan test

# Create admin user
php artisan make:admin

# Check routes
php artisan route:list
```

## Architecture

### Authentication

The system uses a custom `employee` guard (not Laravel's default `web` guard). The `Employee` model extends `Authenticatable` directly.

**Config:** `config/auth.php` defines two guards:
- `web` → User model (unused)
- `employee` → Employee model (primary)

**Middleware:** Routes are protected with `auth:employee`

### Core Models

**Employee** (`app/Models/Employee.php`) — Main user entity
- Fields: full_name, position, email, phone, password, role_id, status
- Status enum: `pending`, `active`, `inactive`
- Relations: `role()` (BelongsTo), `modules()` (BelongsToMany)
- Key methods: `isAdmin()`, `isActive()`, `hasAccessToModule($name)`
- Uses SoftDeletes

**Role** (`app/Models/Role.php`) — User roles
- Two roles seeded: `admin` (full access), `employee` (module-based access)
- Relation: `employees()` (HasMany)

**Module** (`app/Models/Module.php`) — Feature modules for access control
- Seeded: employees, clients, tasks, documents, reports
- Relation: `employees()` (BelongsToMany)
- Admins automatically have access to all modules

### Database Schema

```
roles (id, name, display_name, description)
  ↓ hasMany
employees (id, full_name, position, email, phone, password, role_id, status, ...)
  ↓ belongsToMany (via employee_module)
modules (id, name, display_name, icon, route, sort_order, is_active)
```

Roles and modules are seeded in migrations (`database/migrations/2024_01_01_*`).

### Routes Structure

All employee routes require `auth:employee` middleware:

```
GET    /login              → AuthController@showLoginForm
POST   /login              → AuthController@login
POST   /logout             → AuthController@logout

GET    /employees          → EmployeeController@index
POST   /employees          → EmployeeController@store
GET    /employees/create   → EmployeeController@create
GET    /employees/{id}/edit → EmployeeController@edit
PUT    /employees/{id}     → EmployeeController@update
DELETE /employees/{id}     → EmployeeController@destroy
```

### Views Structure

```
resources/views/
├── auth/login.blade.php
├── employees/{index,create,edit}.blade.php
├── layouts/app.blade.php (main layout with sidebar)
└── emails/employee-invite.blade.php
```

Layout uses Tailwind CSS via CDN and Alpine.js for interactivity.

## Key Patterns

### Creating Employees

Employees are created with password directly (no invite flow). The `EmployeeController@store` validates password with confirmation and sets status to `active`.

### Access Control

```php
// Check if employee is admin
$employee->isAdmin()

// Check module access (admins always return true)
$employee->hasAccessToModule('employees')
```

### Filament Admin Panel

Configured at `/admin` via `app/Providers/Filament/AdminPanelProvider.php`. Resources need to be created in `app/Filament/Resources/`.

## Testing

Uses PHPUnit with SQLite in-memory database for tests.

```bash
composer test                    # Run with config clear
php artisan test --filter=Name   # Run specific test
```

Test files in `tests/Unit/` and `tests/Feature/`.
