.PHONY: ci test prerequisites

# Use any most recent PHP version
PHP=$(shell which php)
PHPDBG=$(shell which phpdbg && echo -qrr || echo php)

# Default parallelism
JOBS=$(shell nproc)

# Default silencer if installed
SILENT=$(shell which chronic)

# PHP CS Fixer
PHP_CS_FIXER=vendor/bin/php-cs-fixer
PHP_CS_FIXER_ARGS=--cache-file=build/cache/.php_cs.cache --verbose
export PHP_CS_FIXER_IGNORE_ENV=1

# PHPUnit
PHPUNIT=vendor/bin/phpunit
PHPUNIT_COVERAGE_CLOVER=--coverage-clover=build/logs/clover.xml
PHPUNIT_GROUP=default
PHPUNIT_ARGS=--coverage-xml=build/logs/coverage-xml --log-junit=build/logs/junit.xml $(PHPUNIT_COVERAGE_CLOVER)
export XDEBUG_MODE=coverage

# PHPStan
PHPSTAN=vendor/bin/phpstan
PHPSTAN_ARGS_TESTS=analyse -c .phpstan.neon
PHPSTAN_ARGS_SRC=analyse -c .phpstan.src.neon
PHPSTAN_ARGS_FIXTURES=analyse -c .phpstan.fixtures.neon

# Composer
COMPOSER=$(PHP) $(shell which composer)

# Infection
INFECTION=vendor/bin/infection
MIN_MSI=100
MIN_COVERED_MSI=100
INFECTION_ARGS=--min-msi=$(MIN_MSI) --min-covered-msi=$(MIN_COVERED_MSI) --threads=$(JOBS) --coverage=build/logs --log-verbosity=default --show-mutations --no-interaction

all: test

##############################################################
# Continuous Integration                                     #
##############################################################

ci-test: SILENT=
ci-test: prerequisites
	$(SILENT) $(PHP) $(PHPUNIT) $(PHPUNIT_COVERAGE_CLOVER) --group=$(PHPUNIT_GROUP)

ci-analyze: SILENT=
ci-analyze: prerequisites ci-phpunit ci-infection ci-phpstan

ci-phpunit: ci-cs
	$(SILENT) $(PHP) $(PHPUNIT) $(PHPUNIT_ARGS)

ci-infection: ci-phpunit
	$(SILENT) $(PHP) $(INFECTION) $(INFECTION_ARGS)

ci-phpstan: ci-cs .phpstan.neon .phpstan.src.neon .phpstan.fixtures.neon
	$(SILENT) $(PHP) $(PHPSTAN) $(PHPSTAN_ARGS_SRC) --no-progress
	$(SILENT) $(PHP) $(PHPSTAN) $(PHPSTAN_ARGS_TESTS) --no-progress
	$(SILENT) $(PHP) $(PHPSTAN) $(PHPSTAN_ARGS_FIXTURES) --no-progress

ci-cs: prerequisites
	$(SILENT) $(PHP) $(PHP_CS_FIXER) $(PHP_CS_FIXER_ARGS) --dry-run --stop-on-violation fix

##############################################################
# Development Workflow                                       #
##############################################################

.PHONY: test
test: phpunit analyze infection composer-validate yamllint

.PHONY: composer-validate
composer-validate: test-prerequisites
	$(SILENT) $(COMPOSER) validate --strict
	$(SILENT) $(COMPOSER) normalize --diff --dry-run

.PHONY: test-prerequisites
test-prerequisites: prerequisites composer.lock

.PHONY: phpunit
phpunit: cs
	rm -fr build/logs/*
	$(SILENT) $(PHP) $(PHPUNIT) $(PHPUNIT_ARGS)

.PHONY: infection
infection: phpunit
	$(SILENT) $(PHP) $(INFECTION) $(INFECTION_ARGS)

.PHONY: analyze
analyze: phpstan

.PHONY: phpstan
phpstan: cs .phpstan.src.neon .phpstan.neon .phpstan.fixtures.neon
	$(SILENT) $(PHP) $(PHPSTAN) $(PHPSTAN_ARGS_SRC)
	$(SILENT) $(PHP) $(PHPSTAN) $(PHPSTAN_ARGS_TESTS)
	$(SILENT) $(PHP) $(PHPSTAN) $(PHPSTAN_ARGS_FIXTURES)

.PHONY: cs
cs: test-prerequisites
	$(SILENT) $(PHP) $(PHP_CS_FIXER) $(PHP_CS_FIXER_ARGS) --diff fix

##############################################################
# Benchmarking                                               #
##############################################################

PHPBENCH=vendor/bin/phpbench

GITHUB_OUTPUT ?= /dev/stdout

.PHONY: benchmark
benchmark: prerequisites benchmarks/Fixtures/A/FixtureA1.php
	$(SILENT) $(PHP) $(PHPBENCH) run --progress=none --report=aggregate | sed 's/+/|/g; 1d; $$d' >> $(GITHUB_OUTPUT)

benchmarks/Fixtures/A/FixtureA1.php:
	$(SILENT) $(PHP) benchmarks/generate-fixtures.php
	$(SILENT) $(COMPOSER) dump-autoload --optimize --dev

##############################################################
# Prerequisites Setup                                        #
##############################################################

# We need both vendor/autoload.php and composer.lock being up to date
.PHONY: prerequisites
prerequisites: report-php-version build/cache vendor/autoload.php composer.lock

# Do install if there's no 'vendor'
vendor/autoload.php:
	$(SILENT) $(COMPOSER) install --prefer-dist
	$(SILENT) $(COMPOSER) dump-autoload --optimize --dev

# If composer.lock is older than `composer.json`, do update,
# and touch composer.lock because composer not always does that
composer.lock: composer.json
	$(SILENT) $(COMPOSER) update && touch composer.lock
	$(SILENT) $(COMPOSER) dump-autoload --optimize --dev

build/cache:
	mkdir -p build/cache

.PHONY: report-php-version
report-php-version:
	# Using $(PHP)

.PHONY: yamllint
yamllint:
	@find .github/ -name \*.y*ml -print0 | xargs -r -n 1 -0 yamllint --no-warnings
	@find . -maxdepth 1 -name \*.y*ml -print0 | xargs -r -n 1 -0 yamllint --no-warnings

##############################################################
# Quick development testing procedure                        #
##############################################################

PHP_VERSIONS=php8.2 php8.3 php8.4

.PHONY: quick
quick:
	make --no-print-directory -j test-all

.PHONY: test-all
test-all: $(PHP_VERSIONS)

.PHONY: $(PHP_VERSIONS)
$(PHP_VERSIONS): cs
	@if command -v $@; then make --no-print-directory PHP=$@ PHP_CS_FIXER=/bin/true phpunit; fi

.PHONY: docs
docs:
	mkdocs serve
