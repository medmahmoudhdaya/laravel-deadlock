<div align="center">

# Laravel Deadlock

![Packagist Version](https://img.shields.io/packagist/v/zidbih/laravel-deadlock)
![Packagist Downloads](https://img.shields.io/packagist/dt/zidbih/laravel-deadlock)
![Packagist License](https://img.shields.io/packagist/l/zidbih/laravel-deadlock)

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
- Laravel: **10, 11, 12**

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

---

## CI/CD Enforcement

Run the check command in your pipeline:

```bash
php artisan deadlock:check
```

If an expired workaround is found, the command exits with **code 1**.

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
