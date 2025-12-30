# Laravel Deadlock

Laravel Deadlock is a Laravel package that helps teams manage technical debt intentionally by allowing developers to mark temporary workaround code with an expiration date and enforce it automatically.

Instead of relying on comments like `TODO` or forgotten tickets, Laravel Deadlock turns technical debt into explicit, time-boxed, enforceable rules.

---

## The Problem

In most Laravel projects, temporary code is unavoidable:

- Legacy integrations
- Rushed fixes
- Temporary business rules
- Compatibility hacks

These workarounds usually start with good intentions, but over time:

- Deadlines are forgotten
- Context is lost
- Temporary code becomes permanent
- No one knows what must be cleaned up

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

- Scans the codebase statically
- Lists all workarounds and their status
- Fails CI when a workaround expires
- Blocks local execution of expired code
- Never affects production

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
- Local execution is blocked
- The debt must be addressed intentionally

---

## Installation

```bash
composer require zidbih/laravel-deadlock
```

The package uses Laravel auto-discovery. No manual service provider registration is required.

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

You only need to add the `#[Workaround]` attribute. No additional runtime code is required.

### Other Classes (Explicit)

For non-controller classes (services, jobs, listeners, commands, etc.), runtime enforcement is explicit by design.

You must explicitly call the guard where enforcement should occur. This avoids magic behavior and performance issues.

---

## Controllers (Automatic Runtime Enforcement)

### Class-Level Workaround

```php
use Zidbih\Deadlock\Attributes\Workaround;

#[Workaround(
    description: 'Legacy user controller awaiting refactor',
    expires: '2025-06-01'
)]
final class UserController extends Controller
{
    public function index()
    {
        // controller logic
    }
}
```

### Method-Level Workaround

```php
use Zidbih\Deadlock\Attributes\Workaround;

final class UserController extends Controller
{
    #[Workaround(
        description: 'Temporary validation bypass',
        expires: '2025-02-01'
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

### Class-Level Workaround (Recommended in Constructor)

```php
use Zidbih\Deadlock\Attributes\Workaround;
use Zidbih\Deadlock\Support\DeadlockGuard;

#[Workaround(
    description: 'Temporary legacy pricing service',
    expires: '2025-01-01'
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

### Method-Level Workaround (Inside the Method)

```php
use Zidbih\Deadlock\Attributes\Workaround;
use Zidbih\Deadlock\Support\DeadlockGuard;

final class PricingService
{
    #[Workaround(
        description: 'Temporary calculation logic',
        expires: '2025-02-01'
    )]
    public function calculate(): int
    {
        DeadlockGuard::check($this, __FUNCTION__);

        return 42;
    }
}
```

---

## Command Reference

### List All Workarounds

```bash
php artisan deadlock:list
```

This command is informational. It scans the codebase and lists all detected workarounds with their current status.

#### Example Output

```
+---------+------------+------------------------------+-------------------------------------------+
| Status  | Expires    | Location                     | Description                               |
+---------+------------+------------------------------+-------------------------------------------+
| OK      | 2026-01-01 | UserController               | Legacy controller awaiting refactor       |
| OK      | 2026-01-01 | OrderService                 | Temporary pricing rules                   |
| EXPIRED | 2025-02-10 | PaymentService::process      | Temporary payment gateway workaround      |
| OK      | 2026-01-01 | UserController::store        | Temporary validation bypass               |
+---------+------------+------------------------------+-------------------------------------------+
```

#### Output Columns

- **Status**: `OK` means the workaround is still valid; `EXPIRED` means the expiration date has passed
- **Expires**: The deadline for removing or refactoring the workaround
- **Location**: The class name or `Class::method`
- **Description**: A human-readable explanation of why the workaround exists

This command does not modify any code or throw exceptions.

---

### CI/CD Enforcement

```bash
php artisan deadlock:check
```

This command is enforcement-focused and designed for CI/CD pipelines.

#### Case 1: No Expired Workarounds

```
No expired workarounds found.
```

**Exit code:** `0`  
**CI result:** PASS

---

#### Case 2: Expired Workarounds Detected

```
Expired workarounds detected:

    Temporary payment gateway workaround | expires: 2025-02-10 | PaymentService::process

    Legacy admin controller | expires: 2025-01-31 | AdminController
```

**Exit code:** `1`  
**CI result:** FAIL

This means:

- At least one workaround has passed its expiration date
- The build is intentionally blocked
- The technical debt must be addressed before merging

---

## Runtime Enforcement Example (Local Only)

When an expired workaround is executed locally (either in a controller or a guarded service), Laravel Deadlock throws an exception immediately.

### Example Exception Output

```
WorkaroundExpiredException

Expired workaround detected: "Temporary payment gateway workaround"
Expired on: 2025-02-10
Location: PaymentService::process
```

**Important notes:**

- This happens only in the `local` environment
- It never happens in production
- It provides immediate feedback to developers

---

## Testing

Laravel Deadlock includes automated tests covering its core runtime and CI enforcement behavior.

The test suite is built using PHPUnit and Orchestra Testbench to ensure correct behavior across supported Laravel versions.


## Production Safety

Laravel Deadlock never enforces anything in production.

---

## Requirements

- **PHP:** 8.2+
- **Laravel:** 10, 11, 12

---

## License

MIT