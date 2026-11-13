<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Relation;
use PhpGraph\Tests\Support\TemporaryProject;
use PHPUnit\Framework\TestCase;

/**
 * A Symfony container asked for a service by its id gives the class the project's configuration defines for it.
 */
final class ServiceIdsTest extends TestCase
{
    use TemporaryProject;

    private const SOURCES = [
        'src/Psr/ContainerInterface.php' => 'namespace Psr\Container; interface ContainerInterface { public function get(string $id): mixed; }',
        'src/Mailer.php' => 'namespace App; class Mailer { public function send(): void {} }',
        'src/Bag.php' => 'namespace App; class Bag { public function get(string $key): mixed { return null; } }',
        'src/Newsletter.php' => 'namespace App; use Psr\Container\ContainerInterface; class Newsletter {'
            . ' public function direct(ContainerInterface $container): void { $container->get("app.mailer")->send(); }'
            . ' public function aliased(ContainerInterface $container): void { $mailer = $container->get("app.mailer_alias"); $mailer->send(); }'
            . ' public function unknown(ContainerInterface $container): void { $container->get("framework.mailer")->send(); }'
            . ' public function bag(Bag $bag): void { $bag->get("app.mailer")->send(); } }',
        'config/services.yaml' => "services:\n    app.mailer:\n        class: App\\Mailer\n    app.mailer_alias: '@app.mailer'\n",
    ];

    public function testAServiceIdGivesTheClassTheConfigurationDefines(): void
    {
        $graph = $this->buildProject(self::SOURCES)->graph;

        self::assertTrue($this->hasEdge($graph, 'App\Newsletter::direct', 'App\Mailer::send', Relation::Calls, Confidence::Inferred), 'app.mailer is defined as App\Mailer');
        self::assertTrue($this->hasEdge($graph, 'App\Newsletter::aliased', 'App\Mailer::send', Relation::Calls, Confidence::Inferred), 'an alias is followed');
    }

    public function testAnIdTheProjectDoesNotDefineOrAReceiverThatIsNoContainerSaysNothing(): void
    {
        $result = $this->buildProject(self::SOURCES);

        self::assertFalse($this->hasEdge($result->graph, 'App\Newsletter::unknown', 'App\Mailer::send', Relation::Calls, Confidence::Inferred), 'framework.mailer is defined by no configuration of the project');
        self::assertFalse($this->hasEdge($result->graph, 'App\Newsletter::bag', 'App\Mailer::send', Relation::Calls, Confidence::Inferred), 'a Bag is no container: its key names no service');
    }
}
