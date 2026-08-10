# AGENTS.md

This library is a PSR-11 dependency injection container. It resolves dependencies with reflection. It has few source files, no configuration format, and no compilation step.

@README.md

README.md specifies the behavior. This file explains you what README.md does not.

## Commands

Run all the CI steps:
```bash
make -j -k
```

Run one test:
```bash
vendor/bin/phpunit tests/ContainerTest.php --filter testItBuildsSimpleObjects
```

Correct the code style with PHP CS Fixer:
```bash
make cs
```

Run PHPBench with OPcache and JIT:
```bash
make benchmark
```

## Source

| File | Content |
|------|---------|
| `src/Container.php` | All the logic: registration, resolution, autowiring, and introspection |
| `src/Builder.php` | `Builder<T>` declares one method, `build(): T`. The template is covariant. |
| `src/Exception.php` | `extends InvalidArgumentException implements NotFoundExceptionInterface` |

## Internal Invariants

The container retains its state in internal arrays. The unit tests cover these rules thoroughly:

- **The container keeps each service ID in one array only.** The arrays are `values` for the resolved services, `prebuilt` for the injected instances, `factories`, `builders`, and `implementations` (update this file when adding a new array). `bind()` and `inject()` call `remove()` first, and thus an ID cannot be in two arrays. `getIterator()` follows this rule: it joins four arrays and expects no duplicate keys.
- **The iterator ignores `values`.** The constructor puts the container into `values[ContainerInterface::class]`, and `get()` puts each resolved service there. The iterator yields the registrations only. As a result, you get the same sequence before and after you resolve a service.
- **`bind()` examines `is_callable()` before `Builder`.** A callable object that also implements `Builder` becomes a factory. This keeps the behavior of the initial code. The `CallableBuilder` fixture exists to tests this rule.
- **`inject()` examines the type before it calls `remove()`.** If the type is not correct, `inject()` throws an exception and the container does not change.
- **`assertType()` does not examine non-class IDs.** It ignores an ID that contains a dot, and an ID that does not contain a backslash. This lets `bind('app.repository', ...)` operate.
- **`get()` examines `implementations[$id] !== $id`.** Without this examination, `get()` calls itself forever when you register a class as its own implementation.
- **`getIterator()` returns `Traversable`, not `Generator`.** Tools scan containers with reflection for accessors that have the form `getFoo(): ConcreteType`. An interface as the return type keeps this method out of such scans.
- **`has()` answers from the registrations only.** It reports the registrations and the cached values. It does not try to autowire the service.

## Limits by Design

These are not defects. Each one has a test.

- The container does not resolve composite types, such as unions and intersections. If the parameter is optional, the container uses the default value. If the parameter is necessary, the container cannot make the service.
- The container ignores variadic parameters and gives no values for them.
- The container does not inject built-in types, such as `int` and `string`. If the parameter is optional, the container uses the default value.
- The container resolves an interface only if one registration makes a compatible type. If no registration makes one, the container uses the default value. If two or more registrations make one, the container cannot resolve the parameter.
- The container does not find circular dependencies, yet. The first test of such a service shows the error.

## Quality Gates

- **Infection must report 100%**: `--min-msi=100 --min-covered-msi=100 --with-uncovered`. Each new branch, `unset()`, and comparison must have a test that kills its mutant. If a mutant stays alive, first look for code that does nothing. Then look for a missing test.
- **PHPStan** runs three times: `level: max` on `src` with `.phpstan.src.neon`, `level: max` on `tests/Fixtures` with `.phpstan.fixtures.neon`, and `level: 2` on `src` and `tests` together with `.phpstan.neon`. The fixtures have the maximum level because they use the generic annotations of the library.
- **PHPUnit** runs with `requireCoverageMetadata`, `failOnRisky`, and a random order. Each new test class must have `#[CoversClass]`.
- **CI examines more configurations than a local run.** CI runs the tests with PHP 8.2, 8.3, 8.4, 8.5, and `latest`. It runs them one more time with `psr/container` v1 in the place of v2. A local run uses one PHP version. Thus only CI finds an error that occurs with v1 alone, or with PHP 8.5 alone.
- The CI test job removes phpstan, infection, and php-cs-fixer before it installs the dependencies. Do not make a test that needs a tool other than PHPUnit.

## Benchmarks

`benchmarks/generate-fixtures.php` makes the fixtures, and Git ignores them.

| Fixture | Shape | Code path |
|---------|-------|-----------|
| A | A chain of 100 classes | autowiring, and the cache on a second `get()` |
| B | 1000 independent classes | autowiring with no recursion |
| C | A chain of 500 classes | deep recursion |
| D | A builder with 20 parallel dependencies | the builder |
| E | 20 interfaces and one consumer of all of them | `providersForType()` |

`providersForType()` runs one time for each interface parameter that the container cannot resolve directly, and it examines all the registrations. Thus a change to this function is visible in the results. Fixture E is necessary because no other benchmark uses this function.

A busy machine makes more noise than most changes make difference. Run the variants in turn (A/B/A/B), attach the process to one core, and change one file only between the variants.

When you add a benchmark, add a row for it to the table in `benchmarks/README.md`. That file is not internal: the benchmark workflow adds it to a comment on each pull request that changes a `.php` file, below the results. If you omit the row, the comment shows a measurement with no explanation.

## Conventions

- Do not use emoji, in the code or in the commit messages.
- Put the data providers above the tests that use them.
- Keep the fixtures in `tests/Fixtures/`. Use an existing fixture before you make a new one.
- Each PHP file starts with the BSD header from `LICENSE`. Do not write the header manually: `make cs` adds it.
- `make cs` also applies Yoda conditions, strict comparisons, and `use function` imports for the global functions. Write the code in this style, or let the tool correct it.
