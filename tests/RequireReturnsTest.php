<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Relation;
use PhpGraph\Tests\Support\TemporaryProject;
use PHPUnit\Framework\TestCase;

/**
 * What a `require` returns types the calls on it: the value of the required file's `return`, found by its path written
 * from `__DIR__`, `dirname()` or a literal (`$app = require __DIR__ . '/../bootstrap/app.php'`).
 */
final class RequireReturnsTest extends TestCase
{
    use TemporaryProject;

    private const APPLICATION = 'namespace Framework; class Application {'
        . ' public static function configure(string $basePath): ApplicationBuilder { return new ApplicationBuilder(); }'
        . ' public function make(string $abstract): object { return new \stdClass(); }'
        . ' public function handleCommand(): int { return 0; } }'
        . ' class ApplicationBuilder { public function create(): Application { return new Application(); } }';

    public function testTheApplicationABootstrapFileReturnsTypesTheScriptsRequiringIt(): void
    {
        $result = $this->buildProject([
            'src/Application.php' => self::APPLICATION,
            'bootstrap/app.php' => '$app = new Framework\Application(); return $app;',
            'public/index.php' => '$app = require_once __DIR__ . \'/../bootstrap/app.php\'; $app->make(\'kernel\');',
        ]);

        self::assertTrue($this->hasEdge($result->graph, 'file:public/index.php', 'Framework\Application::make', Relation::Calls, Confidence::Inferred));
    }

    public function testAReturnedChainAndDirnameOfTheFile(): void
    {
        $result = $this->buildProject([
            'src/Application.php' => self::APPLICATION,
            'bootstrap/app.php' => 'use Framework\Application; return Application::configure(basePath: dirname(__DIR__))->create();',
            'tests/bootstrap.php' => '$app = require dirname(__FILE__, 2) . \'/bootstrap/app.php\'; $app->handleCommand();',
        ]);

        self::assertTrue($this->hasEdge($result->graph, 'file:tests/bootstrap.php', 'Framework\Application::handleCommand', Relation::Calls, Confidence::Inferred));
    }

    public function testARelativePathIsLookedForNextToTheScriptThenFromTheRoot(): void
    {
        $result = $this->buildProject([
            'src/Application.php' => self::APPLICATION,
            'legacy/config.php' => 'return new Framework\Application();',
            'legacy/page.php' => '$config = include \'config.php\'; $config->make(\'db\');',
            'admin/page.php' => '$config = include \'legacy/config.php\'; $config->handleCommand();',
        ]);

        self::assertTrue($this->hasEdge($result->graph, 'file:legacy/page.php', 'Framework\Application::make', Relation::Calls, Confidence::Inferred));
        self::assertTrue($this->hasEdge($result->graph, 'file:admin/page.php', 'Framework\Application::handleCommand', Relation::Calls, Confidence::Inferred));
    }

    public function testScriptsRequiringEachOtherOrAComputedPathStayUnknown(): void
    {
        $result = $this->buildProject([
            'src/Application.php' => self::APPLICATION,
            'a.php' => 'return require __DIR__ . \'/b.php\';',
            'b.php' => '$a = require __DIR__ . \'/a.php\'; $a->make(\'x\'); return $a;',
            'c.php' => '$app = require $root . \'/bootstrap/app.php\'; $app->make(\'x\');',
            'd.php' => '$app = require __DIR__ . \'/../outside.php\'; $app->make(\'x\');',
        ]);

        // No type, and no endless loop: the unique method name is only a guess.
        foreach (['file:b.php', 'file:c.php', 'file:d.php'] as $script) {
            self::assertFalse($this->hasEdge($result->graph, $script, 'Framework\Application::make', Relation::Calls, Confidence::Inferred), $script);
            self::assertTrue($this->hasEdge($result->graph, $script, 'Framework\Application::make', Relation::Calls, Confidence::Ambiguous), $script);
        }
    }
}
