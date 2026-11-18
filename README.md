# Alunta Laravel

Laravel integration for the Alunta PHP SDK: HTTP transport, configuration,
checkout, signed webhooks, subscription sync, invoice standing and reconciliation.
Requires PHP ^8.4 and Laravel ^13.35. The service provider is auto-discovered.

The application owns its migrations, routes, schedule, `services.alunta`
configuration and implementations of the contracts in `src/Contracts`. Installing
the package does not enable Alunta. Enable it only after the application has
configured credentials, webhook verification and its billing policies.

## Installation

Once the public repositories and release tags are available, add the SDK and
Laravel VCS repositories to the consuming project's **root** `composer.json`.
Composer does not load repository declarations from dependencies. Include the
Filament repository only when installing that integration:

```json
{
    "repositories": [
        {"type": "vcs", "url": "https://github.com/KasperStuck/alunta-php-sdk"},
        {"type": "vcs", "url": "https://github.com/KasperStuck/alunta-laravel"},
        {"type": "vcs", "url": "https://github.com/KasperStuck/alunta-filament"}
    ]
}
```

```bash
composer require 'kasperstuck/alunta-laravel:^0.1'
# Optional admin integration:
composer require 'kasperstuck/alunta-filament:^0.1'
```

The Laravel package depends on the SDK. The Filament package depends on both.
List every required repository in the root project, including these transitive
dependencies. For later updates, run `composer update 'kasperstuck/alunta-*'`.

No GitHub authentication is required for public repositories, locally, in CI or
in Docker builds. GitHub limits unauthenticated API requests. An optional GitHub
token supplied through the `COMPOSER_AUTH` environment variable avoids that
limit. Keep tokens out of Git. Packagist may be supported later; this installation
uses VCS repositories.

## Development

```bash
composer install --no-interaction
composer test
composer analyse
```

PHPStan uses Larastan at level 10. The dependency direction Filament → Laravel →
SDK comes from the Composer requirements and PHPStan level 10: analysis fails on
a class from a package that is not installed. The Pest architecture rule only
checks namespaces that are installed; it also excludes application code. Runtime
billing integration tests remain in the host application.

`tests/phpstan-bootstrap.php` supplies the minimal Laravel container for Larastan
and is excluded from release archives with the rest of `tests/`.

## Versioning

Releases use annotated SemVer tags. During 0.x, minor versions may break
compatibility; patch versions preserve it. This library commits no lockfile.

License: [MIT](LICENSE).
