<div align="center">

# Laravel Deadlock

![Packagist Version](https://img.shields.io/packagist/v/zidbih/laravel-deadlock)
![Packagist Downloads](https://img.shields.io/packagist/dt/zidbih/laravel-deadlock)
![Packagist License](https://img.shields.io/packagist/l/zidbih/laravel-deadlock)
[![codecov](https://codecov.io/gh/medmahmoudhdaya/laravel-deadlock/graph/badge.svg)](https://codecov.io/gh/medmahmoudhdaya/laravel-deadlock)


</div>

Have you ever shipped a temporary workaround and never came back to it?  
Laravel Deadlock makes those workarounds explicit and time-boxed.

Annotate classes or methods with an expiration date, then enforce them in local development and CI without affecting production.

**What it does**

- Scans the codebase for `#[Workaround]` attributes
- Lists workarounds and their status
- Fails CI when a workaround has expired
- Blocks local execution of expired code
- Never enforces in production

---

## Installation

```bash
composer require zidbih/laravel-deadlock
```

### Requirements

- PHP: **8.2+**
- Laravel: **10, 11, 12, 13**

### Compatibility

- Laravel **10, 11, 12**: PHP **8.2+**
- Laravel **13**: PHP **8.3+**

---

## Quick Start

Annotate a temporary workaround with a clear description and expiration date.

```php
use Zidbih\Deadlock\Attributes\Workaround;

#[Workaround(
    description: 'Temporary bypass for legacy payment gateway',
    expires: '2025-03-01'
)]
class PaymentService
{
    // ...
}
```

**Supported targets**  
`#[Workaround]` can be applied to **classes and methods**.  
Workarounds inside functions or other scopes are ignored.

### What happens when it expires?

- **Local Development**: Execution is blocked with an exception
- **CI/CD**: Pipelines fail when running the check command
- **Production**: No effect

---

## Enforcement Modes

### Controllers (Automatic)

Controllers are discovered automatically and enforced at runtime.  
Add the attribute; no additional calls are required.

```php
namespace App\Http\Controllers;

use Zidbih\Deadlock\Attributes\Workaround;

#[Workaround(description: 'Legacy controller awaiting refactor', expires: '2025-06-01')]
final class UserController extends Controller
{
    #[Workaround(description: 'Temporary validation bypass', expires: '2025-02-01')]
    public function store()
    {
        // ...
    }
}
```

### Services, Jobs, Commands (Explicit)

For non-controller classes, enforcement is explicit by design to avoid hidden runtime behavior.

#### Class-Level Enforcement

```php
namespace App\Services;

use Zidbih\Deadlock\Attributes\Workaround;
use Zidbih\Deadlock\Support\DeadlockGuard;

#[Workaround(description: 'Temporary legacy pricing service', expires: '2025-01-01')]
final class PricingService
{
    public function __construct()
    {
        DeadlockGuard::check($this);
    }
}
```

#### Method-Level Enforcement

```php
namespace App\Services;

use Zidbih\Deadlock\Attributes\Workaround;
use Zidbih\Deadlock\Support\DeadlockGuard;

final class PricingService
{
    #[Workaround(description: 'Temporary calculation logic', expires: '2025-02-01')]
    public function calculate()
    {
        DeadlockGuard::check($this, __FUNCTION__);

        return 42;
    }
}
```

---

## Artisan Commands

### List Workarounds

```bash
php artisan deadlock:list
```

Example output:

![List command output](docs/images/list-command-output.png)

### Filters

Show only expired workarounds:

```bash
php artisan deadlock:list --expired
```

Show only active workarounds:

```bash
php artisan deadlock:list --active
```

Show workarounds expiring in **7 days or less**:

```bash
php artisan deadlock:list --critical
```

### Stats

The list command now includes a summary line by default, showing totals at a glance.

### Extend Workarounds

`deadlock:extend` updates the `expires` date of an existing `#[Workaround]` attribute in your source code.

It supports three target modes:

- Extend the workaround on a class
- Extend the workaround on one method
- Extend every workaround declared on a class

#### Targeting

Use exactly one of these target options:

- `--class=App\Services\PricingService`
- `--controller=TestController`

`--controller` is a shortcut for classes under `App\Http\Controllers`.

Examples:

```bash
php artisan deadlock:extend --controller=TestController --days=7
php artisan deadlock:extend --controller=Admin\TestController --method=index --months=1
php artisan deadlock:extend --class=App\Services\PricingService --days=7
```

#### How Targeting Works

`--class` only:
- Targets the class-level `#[Workaround]` on that class

`--class` or `--controller` with `--method=...`:
- Targets only the workaround on that method

`--class` or `--controller` with `--all`:
- Targets the class-level workaround
- Targets every method-level workaround declared on that class

#### Date Options

You must provide either:

- `--days=N`
- `--months=N`
- `--date=YYYY-MM-DD`

You may combine `--days` and `--months` in the same command.

```bash
php artisan deadlock:extend --class=App\Services\PricingService --months=1 --days=7
```

`--date` is absolute and replaces the current expiry date directly.

```bash
php artisan deadlock:extend --class=App\Services\PricingService --date=2026-06-01
```

`--date` cannot be combined with `--days` or `--months`.

#### Examples

Extend a class-level workaround:

```bash
php artisan deadlock:extend --class=App\Services\PricingService --days=7
```

Extend a method-level workaround:

```bash
php artisan deadlock:extend --class=App\Services\PricingService --method=calculate --days=7
```

Extend every workaround on a class:

```bash
php artisan deadlock:extend --class=App\Services\PricingService --all --months=1 --days=7
```

Extend a controller workaround:

```bash
php artisan deadlock:extend --controller=TestController --days=7
```

Extend a nested controller method workaround:

```bash
php artisan deadlock:extend --controller=Admin\TestController --method=index --date=2026-06-01
```

#### Validation Rules

- Use exactly one of `--class` or `--controller`
- `--method` and `--all` cannot be used together
- Without `--method` or `--all`, the command updates only the class-level workaround
- `--days` and `--months` must be positive integers
- `--date` must use `YYYY-MM-DD`
- The target class must resolve to a real PHP file
- The targeted class or method must already have a `#[Workaround]`

---

## CI/CD Enforcement

Run the check command in your pipeline:

```bash
php artisan deadlock:check
```

If an expired workaround is found, the command exits with **code 1**.

For machine-readable output, use JSON mode:

```bash
php artisan deadlock:check --json
```

Example JSON output:

```json
{
  "success": false,
  "expired_count": 1,
  "expired": [
    {
      "description": "Temporary payment gateway workaround",
      "expires": "2025-02-10",
      "location": "PaymentService::process",
      "file": "/app/Services/PaymentService.php",
      "line": 18,
      "class": "PaymentService",
      "method": "process"
    }
  ]
}
```

Example failure output:

```
Expired workarounds detected:

- Temporary payment gateway workaround | expires: 2025-02-10 | PaymentService::process
- Legacy admin controller | expires: 2025-01-31 | AdminController
```

CI example:

```yaml
- name: Deadlock check
  run: php artisan deadlock:check
```

---

## Runtime Enforcement (Local Only)

When an expired workaround is accessed locally, a `WorkaroundExpiredException` is thrown with:

- Description
- Expiration date
- Exact code location

Example exception output:

![Expired workaround exception](docs/images/workaround-exception.png)

---

## Production Safety

Laravel Deadlock never enforces debt in production.

- Runtime exceptions only occur in **local** environments
- CI blocks merges before debt reaches production
- Live users are never affected

---

## Testing

The test suite uses **PHPUnit** and **Orchestra Testbench** for compatibility across supported Laravel versions.

---

## License

[MIT](LICENSE)

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md)
