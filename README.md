# sanmai/di-container

A straightforward PSR-11 dependency injection container with automatic constructor dependency resolution.

I designed the autowiring [initially for Infection's own use](https://github.com/infection/infection/pull/2118) with focus on simplicity and zero configuration, building on the existing implementation by [Maks Rafalko](https://github.com/maks-rafalko) and [Théo Fidry](https://github.com/theofidry).

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
        $container->get(LoggerInterface::class),
        $container->get(AnotherProvider::class)->getValue()
    ),
    DatabaseInterface::class => fn(Container $container) =>
        $container->get(DatabaseBuilder::class)->build(),
]);

// Set additional dependencies on the fly
$container->set(LoggerInterface::class, fn() => new FileLogger('debug.log'));

$service = $container->get(ServiceNeedingDatabase::class); // Auto-injects database
```

The order in which you define your services is not important, as dependencies are only resolved when they are requested.

## Builder Objects

Builder objects can encapsulate arbitrary complex construction logic. They can use dependency injection, which makes them cohesive, independently testable, and reusable.

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

When you implement the `Builder` interface, you can simply provide the builder class name instead of a closure. The container automatically detects builder classes and handles the instantiation and `build()` method call.

```php
// 1. Direct class name, if the class implements DIContainer\Builder interface
$container = new Container([
    DatabaseInterface::class => DatabaseBuilder::class,
]);

// 2. Explicit closure - what will the container do under the hood
$container = new Container([
    DatabaseInterface::class => fn(Container $container) => $container->get(DatabaseBuilder::class)->build(),
]);
```

For setting dependencies on the fly, there's a handy `set()` method that accepts both callables and builders.

## Design Philosophy

This container prioritizes simplicity, predictability, and architectural purity. It achieves this through:

- Predictable autowiring; there are no complex background scans or fragile naming conventions.
- Lack of surprises; the container will only resolve an interface if it can find **exactly one** registered factory or builder that produces a compatible implementation. It will never guess, ensuring the dependency graph is always clear, just as your day is worry-free.
- Constructor-only dependency injection; the container intentionally avoids complex features, such as property/method injection or support for variadic/composite types in constructors. This approach promotes cleaner, more testable class designs.

The container resolves interfaces using a straightforward rule: when a dependency is an interface, it looks for exactly one registered factory or a builder that produces a compatible object.

This approach allows you to wire dependencies without explicitly linking an implementation to an interface; the container connects them logically as long as the relationship is unambiguous.

The container omits circular dependency checks for simplicity, an issue that even the most minimal automatic test will immediately reveal.

## Testing

```bash
make -j -k
```
