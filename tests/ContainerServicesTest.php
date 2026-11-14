<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Builder\ContainerServices;
use PhpGraph\Builder\ServiceMap;
use PHPUnit\Framework\TestCase;

final class ContainerServicesTest extends TestCase
{
    public function testAnAliasToAClassUndefinedInTheServiceTakesTheDefinitionOfTheRootCode(): void
    {
        $container = new ContainerServices(sys_get_temp_dir(), [], ServiceMap::single(), [
            'billing' => ['app.provider' => '@App\Provider'],
            ServiceMap::ROOT => ['app.provider' => '@App\Provider', 'App\Provider' => 'App\ConcreteProvider'],
        ]);

        self::assertSame('App\ConcreteProvider', $container->classOf('app.provider', 'billing'), 'the root code defines the class the alias names');
    }

    public function testAnAliasToAClassNoScopeDefinesIsThatClass(): void
    {
        $container = new ContainerServices(sys_get_temp_dir(), [], ServiceMap::single(), [
            'billing' => ['app.provider' => '@App\Provider'],
        ]);

        self::assertSame('App\Provider', $container->classOf('app.provider', 'billing'), 'the class is autowired from a resource: its id is its name');
    }
}
