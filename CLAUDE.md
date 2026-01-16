# CLAUDE.md - AI Assistant Guide

This document provides guidance for AI assistants working with the Laravel Concurrent Limiter codebase.

## Project Overview

**Laravel Concurrent Limiter** is a lightweight Laravel middleware package that limits the number of concurrent requests per user (or IP when unauthenticated). It delays incoming requests until a slot is free or returns a 503 error if the wait exceeds a defined maximum time.

- **Package Name**: `patrocle/laravel-concurrent-limiter`
- **Namespace**: `Patrocle\LaravelConcurrentLimiter`
- **License**: MIT
- **PHP Support**: 8.2, 8.3, 8.4
- **Laravel Support**: 10.x, 11.x

## Repository Structure

```
├── src/                              # Source code
│   ├── LaravelConcurrentLimiter.php           # Core middleware class
│   └── LaravelConcurrentLimiterServiceProvider.php  # Service provider
├── tests/                            # Test suite (Pest)
│   ├── TestCase.php                 # Base test case
│   ├── ExampleTest.php              # Example tests
│   ├── ArchTest.php                 # Architecture tests
│   └── Pest.php                     # Pest configuration
├── config/                           # Package configuration
│   └── concurrent-limiter.php       # Config file
├── .github/workflows/               # CI/CD workflows
│   ├── run-tests.yml               # Test execution
│   ├── phpstan.yml                 # Static analysis
│   └── fix-php-code-style-issues.yml  # Code styling
├── composer.json                    # Dependencies
├── phpunit.xml.dist                # PHPUnit/Pest config
└── phpstan.neon.dist               # PHPStan config
```

## Key Files

| File | Purpose |
|------|---------|
| `src/LaravelConcurrentLimiter.php` | Main middleware - handles concurrent request limiting |
| `src/LaravelConcurrentLimiterServiceProvider.php` | Registers middleware alias `concurrent.limit` |
| `config/concurrent-limiter.php` | Package configuration (extensible) |
| `tests/TestCase.php` | Base test class using Orchestra Testbench |

## Development Commands

```bash
# Run tests
composer test

# Run tests with coverage
composer test-coverage

# Run static analysis (PHPStan level 5)
composer analyse

# Fix code style (Laravel Pint)
composer format
```

## Middleware Parameters

The middleware accepts three parameters:

1. **maxParallel** (default: 10) - Maximum concurrent requests allowed
2. **maxWaitTime** (default: 30) - Maximum seconds to wait for a slot
3. **prefix** (default: '') - Optional cache key prefix

Usage examples:
```php
// Route-level
Route::middleware('concurrent.limit:10,30,api')->group(...);

// Programmatic
Route::middleware(LaravelConcurrentLimiter::with(10, 30, 'api'))->group(...);
```

## Code Conventions

### PHP Style
- Follow PSR-12 coding standards
- Use Laravel Pint for formatting (run `composer format`)
- PHPStan level 5 compliance required
- No debugging functions (`dd`, `dump`, `ray`) in production code

### Testing
- Use Pest PHP framework
- Architecture tests verify no debugging functions exist
- Tests extend `Patrocle\LaravelConcurrentLimiter\Tests\TestCase`

### Cache Usage
- Uses Laravel's Cache facade for atomic operations
- Keys are SHA1 hashes of user ID or IP address
- Timer keys prevent stale counters

## Architecture Overview

### Request Flow
1. Request arrives at middleware
2. Atomic increment of cache counter for user/IP
3. Wait loop (100ms polling) until slot available or timeout
4. On timeout: decrement counter, return 503 JSON response
5. On success: process request, decrement counter in `finally` block

### Key Methods in LaravelConcurrentLimiter.php

- `handle()` - Main middleware method
- `resolveRequestSignature()` - Generates unique key (user ID or IP)
- `with()` - Static helper for middleware definition

## CI/CD Pipeline

### Automated Checks (on push)
1. **Tests** - Runs across PHP 8.3/8.4, Laravel 10/11, Ubuntu/Windows
2. **PHPStan** - Static analysis at level 5
3. **Laravel Pint** - Auto-fixes and commits style issues

### Dependabot
- Weekly checks for Composer and GitHub Actions updates
- Auto-merges minor and patch updates

## Common Tasks

### Adding a New Feature
1. Write tests in `tests/` using Pest
2. Implement in `src/`
3. Run `composer test` and `composer analyse`
4. Run `composer format` before committing

### Modifying Middleware Behavior
- Edit `src/LaravelConcurrentLimiter.php`
- The `handle()` method contains the main logic
- Ensure atomic cache operations are preserved

### Adding Configuration Options
1. Add to `config/concurrent-limiter.php`
2. Access via `config('concurrent-limiter.key')`
3. Update service provider if needed

## Testing Guidelines

```php
// Example test structure
it('limits concurrent requests', function () {
    // Setup
    // Action
    // Assertion
});

// Architecture test example
arch('no debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->not->toBeUsed();
```

## Dependencies

### Production
- `spatie/laravel-package-tools` - Package scaffolding
- `illuminate/contracts` - Laravel contracts

### Development
- `pestphp/pest` - Testing framework
- `larastan/larastan` - PHPStan for Laravel
- `laravel/pint` - Code styling
- `orchestra/testbench` - Laravel testing utilities

## Error Handling

The middleware returns a 503 JSON response when limits are exceeded:
```json
{
    "message": "Too many concurrent requests. Please try again later."
}
```

## Important Notes

- The middleware uses polling (100ms intervals) to check slot availability
- Cache counters are always decremented in `finally` blocks to prevent leaks
- Timer keys expire after `maxWaitTime + 5` seconds to clean up stale entries
- User ID takes precedence over IP for request identification
