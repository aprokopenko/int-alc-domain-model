<?php

declare(strict_types=1);

namespace App;

use App\Domains\EarningLine\EarningLineRepository;
use App\Domains\EarningLine\Events\EarningLineRecalculationIgnored;
use App\Demo\Listeners\IgnoredRecalculationLogger;
use App\Demo\Persistence\SqliteEarningLineRepository;
use DI\Container;
use DI\ContainerBuilder;
use PDO;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function DI\autowire;

/**
 * Builds the application's DI container for one SQLite DSN and registers it as
 * the process-wide container (see container()). Actions, the repository and
 * everything else are resolved straight from the container (autowired by their
 * constructor's type hints) instead of through a hand-written factory:
 * bin/demo.php passes a file path, tests pass ':memory:'.
 */
function bootContainer(string $dsn): Container
{
    $builder = new ContainerBuilder();

    $builder->addDefinitions([
        PDO::class => function () use ($dsn): PDO {
            $pdo = new PDO($dsn);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            // SQLite defaults foreign key enforcement to OFF per connection; without
            // this, the `REFERENCES earning_lines(id)` in the schema is decorative only.
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec((string) file_get_contents(__DIR__ . '/../database/schema.sql'));

            return $pdo;
        },
        EarningLineRepository::class => autowire(SqliteEarningLineRepository::class),
        EventDispatcherInterface::class => function (): EventDispatcherInterface {
            $dispatcher = new EventDispatcher();
            $dispatcher->addListener(EarningLineRecalculationIgnored::class, new IgnoredRecalculationLogger());

            return $dispatcher;
        },
    ]);

    return container($builder->build());
}

/**
 * The process-wide DI container, resolvable from anywhere — akin to Laravel's
 * app() helper. bootContainer() registers it; call container() with no argument
 * anywhere else (an Action, a script, a test) to fetch it back rather than
 * threading a $container variable through every function signature.
 */
function container(?Container $container = null): Container
{
    static $instance = null;

    if ($container !== null) {
        $instance = $container;
    }

    return $instance ?? throw new RuntimeException(
        'The container has not been booted yet — call App\bootContainer($dsn) first.',
    );
}
