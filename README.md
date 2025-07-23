# sanmai/di-container

A straightforward PSR-11 dependency injection container with automatic constructor dependency resolution.

I designed it [initially for Infection's own use](https://github.com/infection/infection/pull/2118) with focus on simplicity and zero configuration.

## Installation

```bash
composer require sanmai/di-container
```

## Features

- Automatically resolves class dependencies through reflection
- Objects are created once and reused
- Resolve interfaces to concrete implementations

## Quick Start

```php
use DIContainer\Container;

$container = new Container();

// Automatic resolution - no configuration needed
$service = $container->get(YourService::class);

// Use builder objects for complex construction, or construct the dependencies directly - your choice
$container = new Container([
    ComplexObject::class => fn(Container $container) => new ComplexObject(
        $container->get(SomeDependency::class),
        $container->get(AnotherProvider::class)->getValue()
    ),
    DatabaseInterface::class => fn(Container $container) =>
        $container->get(DatabaseBuilder::class)->build(),
]);

$service = $container->get(ServiceNeedingDatabase::class); // Auto-injects database
```

## Builder Objects

Builder objects encapsulate complex construction logic while leveraging dependency injection for their own dependencies:

```php
use DIContainer\Builder;

// Builder that accepts injectable dependencies
class DatabaseBuilder implements Builder
{
    public function __construct(
        private readonly ConfigProvider $config,
        private readonly Logger $logger
    ) {}

    public function build(): DatabaseInterface
    {
        return new MySQLDatabase(
            $this->config->getDatabaseHost(),
            $this->config->getDatabaseCredentials(),
            $this->logger
        );
    }
}
```

This pattern allows complex object construction while keeping configuration in dedicated, testable services.

## Testing

```bash
make -j -k
```
