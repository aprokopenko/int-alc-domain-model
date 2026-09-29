<?php

declare(strict_types=1);

namespace Tests;

use DI\Container;
use PHPUnit\Framework\TestCase as BaseTestCase;

use function App\bootContainer;

/**
 * Builds the application's DI container against SQLite ':memory:', so every test
 * resolves the same repository/dispatcher/Action wiring as production, just
 * against a fresh, isolated database per test.
 */
abstract class TestCase extends BaseTestCase
{
    protected Container $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = bootContainer('sqlite::memory:');
    }
}
