---
name: mohimaa-backend
description: "Guidelines and conventions for developing the Laravel 12 / PHP 8.5 backend of MohimaaWarehouse. Activates when writing or modifying PHP code, Laravel controllers, models, resources, services, migrations, config, routing, or when the user mentions backend, DB, migrations, API, or service refactoring."
license: MIT
metadata:
  author: laravel
---

# MohimaaWarehouse Backend Development

Guidelines and conventions for the Mohimaa e-commerce and inventory platform backend.

## When to Apply

Activate this skill when:
- Writing or modifying PHP code (controllers, models, services, migrations, resources, form requests).
- Working on database schemas or query optimizations.
- Configuring middleware, routing, service providers, or console commands.
- Making architectural decisions or implementing backend features.

---

## Foundational Stack & Versions

- **PHP**: 8.5.0
- **Laravel Framework**: v12
- **Laravel Prompts**: v0
- **Laravel Sanctum**: v4
- **Laravel Telescope**: v5
- **Laravel Boost**: v2
- **Laravel MCP**: v0
- **Laravel Pail**: v1
- **Laravel Pint**: v1
- **Laravel Sail**: v1
- **Pest PHP**: v4
- **PHPUnit**: v12

---

## Conventions & Best Practices


- **Descriptive Naming**: Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- **Existing Patterns**: Check sibling files for the correct structure, approach, and naming conventions before writing new components.
- **Verification Scripts**: Do not create verification scripts or tinker when tests cover that functionality.
- **Application Structure**: Stick to the existing directory structure; do not create new base folders without approval.
- **Dependencies**: Do not change dependencies in `composer.json` without explicit approval.

---

## PHP Guidelines (PHP 8.5)

- **Control Structures**: Always use curly braces `{}` for control structures, even for single-line bodies.
- **Constructors**: Use PHP 8 constructor property promotion in `__construct()`. Do not allow empty constructors unless they are private.
  ```php
  public function __construct(
      public GitHub $github,
  ) {}
  ```
- **Type Declarations**: Always use explicit return type declarations for methods/functions, and appropriate type hints for parameters.
  ```php
  protected function isAccessible(User $user, ?string $path = null): bool
  {
      // ...
  }
  ```
- **Enums**: Key names in Enums should be `TitleCase` (e.g., `FavoritePerson`, `BestLake`, `Monthly`).
- **Comments & PHPDocs**:
  - Prefer PHPDoc blocks over inline comments.
  - Add useful array shape type definitions in PHPDocs when appropriate.
  - Do not use comments within the code itself unless the logic is exceptionally complex.

---

## Laravel 12 Architecture & Guidelines

### Streamlined File Structure
- **No Http Kernel / Console Kernel**: Middleware, exceptions, and routing are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- **Service Providers**: App-specific service providers are registered in `bootstrap/providers.php`.
- **Console Commands**: Commands under `app/Console/Commands/` are automatically available and do not require manual registration.

### Database & Eloquent
- **DB Transactions**: Whenever a feature or operation performs more than one database operation (e.g., updating models, inserting records, recording stock movement), you MUST wrap all database operations in a database transaction (`DB::transaction(function () { ... })`) inside the Service layer.
- **Relationships**: Always use proper Eloquent relationship methods with return type hints. Prefer relationships over raw queries/joins.
- **Querying**: Avoid using `DB::` raw queries; prefer `Model::query()`. Use eager loading to prevent N+1 query problems.
- **Native Eager Load Limit**: Laravel 12 natively supports limiting eagerly loaded records:
  ```php
  $query->latest()->limit(10);
  ```
- **Model Modifications**:
  - Casts must be set in a `casts()` method on the model rather than the `$casts` property.
  - When writing a migration to modify an existing column, include all previous attributes defined on the column to avoid losing them.
  - Always create factories and seeders when creating new models.

### Controllers, Services & API Architecture
- **Strict Ultra-Thin Controllers**: Controllers MUST NOT contain inline validation (`$request->validate()`), auth/client checks (`auth()->user()`), header/input extractions, database queries, transactions, or business/domain logic. Controllers are strictly HTTP forwarders that inject Service classes, pass the request to the Service, and return whatever the Service returns.
- **Service Layer Responsibility**: All business, domain, authentication checks, request processing, session recording, database logic, and resource transformations (`WishlistResource::collection(...)`) MUST reside inside dedicated Service classes under `app/Services/{Module}/{ServiceName}.php` (e.g. `WishlistService`, `ClientAddressService`, `OrderService`). Multi-operation DB writes inside Service classes must be wrapped in `DB::transaction(...)`.
- **Validation**: Always create Form Request classes under `app/Http/Requests/{Module}/` for request validation instead of inline validation in controllers. Include both validation rules and custom error messages. Use the application-wide validation format matching sibling requests.
- **API Response Formatting**: MUST ALWAYS use the global `responseSuccess($data, $message, $code)` and `responseError($message, $code, $exception, $data)` helper functions for all API responses returned by Services/Controllers. Do NOT use `response()->json([...])` directly.

### Authentication & URL Generation
- **Auth**: Use built-in Laravel features (gates, policies, Sanctum).
- **URL**: Prefer named routes and the `route()` function.
- **Queues**: Use queued jobs implementing the `ShouldQueue` interface for long-running operations.
- **Config**: Env variables MUST only be accessed in config files. Use `config('app.name')`, not `env('APP_NAME')` in codebase files.

---

## Laravel Boost Tools

This project uses Laravel Boost MCP server tools:
- `search-docs`: Search version-specific documentation for Laravel and ecosystem packages. Run multiple topic-based queries at once (e.g. `['rate limiting', 'routing rate limiting']`) without package names.
- `list-artisan-commands`: Check available Artisan commands and parameters. Run with `--no-interaction` when invoking.
- `get-absolute-url`: Use when sharing project URLs with the user.
- `tinker`: Run PHP snippets to query Eloquent or debug code.
- `database-query`: Execute read-only SQL queries.
- `database-schema`: Inspect table structures.
- `browser-logs`: View recent browser logs/errors.

---

## Code Formatting

If you modify any PHP files, format them using Laravel Pint before finalizing changes:
```bash
vendor/bin/pint --dirty --format agent
```
*(Do not use the `--test` flag.)*

---

## Testing Enforcement

- Every change must be programmatically tested. Write a new Pest test or update an existing one.
- Activate the `pest-testing` skill when writing or running tests.
- Run tests using:
  ```bash
  php artisan test --compact --filter=testName
  ```
