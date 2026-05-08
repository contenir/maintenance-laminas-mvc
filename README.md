# contenir/maintenance-laminas-mvc

Laminas MVC adapter for [`contenir/maintenance`](https://github.com/contenir/maintenance).

When the admin (Contenir CMS) toggles maintenance mode, this adapter
short-circuits dispatch in the consuming Site with a 503 response — until
the flag is cleared.

## Install

```bash
composer require contenir/maintenance-laminas-mvc
```

## Wire-up

### 1. Register the module

```php
// config/modules.config.php
return [
    // ...other modules
    'Contenir\\Maintenance\\Laminas\\Mvc',
];
```

If you have `laminas/laminas-component-installer` installed, this happens
automatically.

### 2. Point at the shared state file

```php
// config/autoload/maintenance.local.php
return [
    'maintenance' => [
        // Same path the admin (Contenir CMS) is configured to write.
        'file' => '/var/www/shared/maintenance.local.php',
    ],
];
```

That's the minimum. With nothing else set, the adapter will return a
plain 503 page with the configured message whenever maintenance is
active.

### 3. (Optional) Bypass for operators

```php
// config/autoload/maintenance.local.php
return [
    'maintenance' => [
        'file'   => '/var/www/shared/maintenance.local.php',
        'bypass' => static function (\Laminas\Mvc\MvcEvent $event): bool {
            // Return true to let the request through despite maintenance mode.
            // E.g. allow authenticated super-admins:
            $auth = $event->getApplication()->getServiceManager()->get('auth');
            return $auth->hasIdentity() && $auth->getIdentity()->isSuperAdmin();
        },
    ],
];
```

### 4. (Optional) Customise the response body

```php
'maintenance' => [
    'body_template' => file_get_contents(__DIR__ . '/../templates/maintenance.html'),
    'retry_after'   => 1800, // seconds, sent as Retry-After header
],
```

`body_template` is a `sprintf` format string with a single `%s` for the
escaped message text. If you need anything more elaborate (full layout,
view helpers, translation), replace the `MaintenanceListener` service
with your own factory.

## Listener priority

The listener attaches at `MvcEvent::EVENT_DISPATCH` priority `10000` so
it runs before route → controller dispatch and before any other listener
that hasn't asked for higher priority. The exact value is exposed as
`Module::DISPATCH_PRIORITY` if you need to coordinate with another
listener.
