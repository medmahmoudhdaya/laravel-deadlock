# Laravel Deadlock

Laravel Deadlock is a Laravel package that helps teams manage technical debt intentionally by allowing developers to mark temporary workaround code with an expiration date and enforce it automatically.

Instead of relying on comments like `TODO` or forgotten tickets, Laravel Deadlock turns technical debt into explicit, time-boxed, enforceable rules.

---

## The Problem

In most Laravel projects, temporary code is unavoidable:

- legacy integrations
- rushed fixes
- temporary business rules
- compatibility hacks

These workarounds usually start with good intentions, but over time:

- deadlines are forgotten
- context is lost
- temporary code becomes permanent
- no one knows what must be cleaned up

Laravel Deadlock solves this by making technical debt explicit, visible, time-limited, and enforceable.

---

## The Idea

Instead of writing comments like:

```php
// TODO: remove this later
```

You explicitly mark the code:

```php
#[Workaround(
    description: 'Temporary bypass for legacy payment gateway',
    expires: '2025-03-01'
)]
```

Laravel Deadlock then:

- scans the codebase statically
- lists all workarounds and their status
- fails CI when a workaround expires
- blocks local execution of expired code
- never affects production

---

## Key Concepts

### Workaround

A workaround is any piece of code that is known to be temporary and must be removed or refactored before a specific date.

Laravel Deadlock represents workarounds using PHP 8 attributes.

### Expiration Date

Each workaround has a required expiration date in the following format:

```
YYYY-MM-DD
```

Once the date is reached:

- CI builds fail
- local execution is blocked
- the debt must be addressed intentionally

---

## Installation

```bash
composer require zidbih/laravel-deadlock
```

The package uses Laravel auto-discovery.
No manual service provider registration is required.

---

## Marking Workarounds

Import the attribute:

```php
use Zidbih\Deadlock\Attributes\Workaround;
```

---

## Automatic vs Explicit Enforcement

Laravel Deadlock distinguishes between controllers and all other classes.

This distinction is intentional and important.

### Controllers (Automatic)

Controller classes and controller methods are automatically discovered and enforced at runtime.

You only need to add the `#[Workaround]` attribute.
No additional runtime code is required.

### Other Classes (Explicit)

For non-controller classes (services, jobs, listeners, commands, etc.), runtime enforcement is explicit by design.

You must explicitly call the guard where enforcement should occur.
This avoids magic behavior and performance issues.

---

## Controllers (Automatic Runtime Enforcement)

### Class-level workaround

```php
use Zidbih\Deadlock\Attributes\Workaround;

#[Workaround(
    'Legacy user controller awaiting refactor',
    '2025-06-01'
)]
final class UserController extends Controller
{
    public function index()
    {
        // controller logic
    }
}
```

### Method-level workaround

```php
use Zidbih\Deadlock\Attributes\Workaround;

final class UserController extends Controller
{
    #[Workaround(
        'Temporary validation bypass',
        '2025-02-01'
    )]
    public function store()
    {
        // workaround logic
    }
}
```

---

## Services and Other Classes (Explicit Runtime Enforcement)

Import the guard:

```php
use Zidbih\Deadlock\Support\DeadlockGuard;
```

### Class-level workaround (recommended in constructor)

```php
use Zidbih\Deadlock\Attributes\Workaround;
use Zidbih\Deadlock\Support\DeadlockGuard;

#[Workaround(
    'Temporary legacy pricing service',
    '2025-01-01'
)]
final class PricingService
{
    public function __construct()
    {
        DeadlockGuard::check($this);
    }

    public function calculate(): int
    {
        return 42;
    }
}
```

### Method-level workaround (inside the method)

```php
use Zidbih\Deadlock\Attributes\Workaround;
use Zidbih\Deadlock\Support\DeadlockGuard;

final class PricingService
{
    #[Workaround(
        'Temporary calculation logic',
        '2025-02-01'
    )]
    public function calculate(): int
    {
        DeadlockGuard::check($this, __FUNCTION__);

        return 42;
    }
}
```

---

## Listing All Workarounds

```bash
php artisan deadlock:list
```

---

## CI / CD Enforcement

```bash
php artisan deadlock:check
```

This command fails the build if any workaround is expired.

---

## Production Safety

Laravel Deadlock never enforces anything in production.

---

## Supported Versions

- PHP 8.2+
- Laravel 10, 11, 12

---

## License

MIT
