<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Relation;
use PhpGraph\Tests\Support\TemporaryProject;
use PHPUnit\Framework\TestCase;

/**
 * PrestaShop's autoloader declares `Context` as an empty subclass of `ContextCore` at run time: the code names
 * `Context`, the project declares `ContextCore`.
 */
final class PrestaShopClassesTest extends TestCase
{
    use TemporaryProject;

    private const CLASSES = [
        'classes/Context.php' => 'class ContextCore { /** @var Link */ public $link; /** @return Context */ public static function getContext() { return new Context(); } }',
        'classes/Link.php' => 'class LinkCore { public function getPageLink(): string { return ""; } }',
        'controllers/CartController.php' => 'class CartControllerCore { public function init(): void { Context::getContext()->link->getPageLink(); } }',
    ];

    public function testANameTheAutoloaderDeclaresIsItsCoreClass(): void
    {
        $graph = $this->buildProject([...self::CLASSES, 'composer.json' => '{"require": {"prestashop/autoload": "^1.0"}}'])->graph;

        self::assertTrue(
            $this->hasEdge($graph, 'CartControllerCore::init', 'LinkCore::getPageLink', Relation::Calls, Confidence::Inferred),
            'Context is ContextCore, its $link a LinkCore',
        );
        self::assertTrue($this->hasEdge($graph, 'ContextCore::getContext', 'ContextCore', Relation::Instantiates), 'new Context builds the core class');
    }

    public function testAClassTheProjectOverridesKeepsItsOwnName(): void
    {
        $graph = $this->buildProject([
            ...self::CLASSES,
            'override/classes/Link.php' => 'class Link extends LinkCore { public function getPageLink(): string { return "/"; } }',
            'composer.json' => '{"require": {"prestashop/autoload": "^1.0"}}',
        ])->graph;

        self::assertTrue($this->hasEdge($graph, 'CartControllerCore::init', 'Link::getPageLink', Relation::Calls, Confidence::Inferred), 'override/ declares Link: the code runs it');
    }

    public function testWithoutPrestaShopsAutoloaderACoreSuffixMeansNothing(): void
    {
        $graph = $this->buildProject(self::CLASSES)->graph;

        self::assertFalse(
            $this->hasEdge($graph, 'CartControllerCore::init', 'LinkCore::getPageLink', Relation::Calls, Confidence::Inferred),
            'Context is a class of no one: at best a guess from the method name',
        );
    }
}
