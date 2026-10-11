<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Relation;
use PhpGraph\Tests\Support\TemporaryProject;
use PHPUnit\Framework\TestCase;

/**
 * A union is called on the member declaring the method; a closure passed to a method gets the parameters the method
 * documents it passes (`callable($this): mixed`); a trait used with `@use Trait<Arg>` binds its templates.
 */
final class UnionsAndClosuresTest extends TestCase
{
    use TemporaryProject;

    private const BUILDER = [
        'src/Conditionable.php' => 'namespace Lib; trait Conditionable {'
            . ' /** @template TWhenReturnType'
            . "\n * @param (callable(\$this, mixed): TWhenReturnType)|null \$callback"
            . "\n * @return \$this|TWhenReturnType */"
            . ' public function when($value, ?callable $callback = null) { return $this; } }',
        'src/Builder.php' => 'namespace Lib; class Builder { use Conditionable; public function where(string $column): static { return $this; } public function get(): array { return []; } }',
    ];

    public function testAUnionIsCalledOnTheMemberDeclaringTheMethod(): void
    {
        $result = $this->buildProject([
            'src/Animals.php' => 'namespace App; class Animal { public function eat(): void {} } class Cat extends Animal { public function purr(): void {} public function sleep(): void {} } class Dog extends Animal { public function bark(): void {} public function sleep(): void {} }',
            'src/Shelter.php' => 'namespace App; class Shelter {'
                . ' /** @return Cat|Dog|null */ public function adopt(): mixed { return null; }'
                . ' public function noise(): void { $this->adopt()->bark(); }'
                . ' public function feed(): void { $this->adopt()->eat(); }'
                . ' public function rest(): void { $this->adopt()->sleep(); } }',
        ]);
        $graph = $result->graph;

        self::assertTrue($this->hasEdge($graph, 'App\Shelter::noise', 'App\Dog::bark', Relation::Calls, Confidence::Inferred));
        self::assertTrue($this->hasEdge($graph, 'App\Shelter::feed', 'App\Animal::eat', Relation::Calls, Confidence::Inferred), 'both members inherit the same method');
        self::assertFalse($this->hasEdge($graph, 'App\Shelter::rest', 'App\Cat::sleep', Relation::Calls), 'each member declares its own: no guess');
        self::assertSame(1, $result->calls->unknownReceiver);
    }

    public function testANativeUnionOfAReturnOrAParameter(): void
    {
        $result = $this->buildProject([
            'src/Identity.php' => 'namespace App; class Identity { public function userId(): int { return 1; } } class Denied { public function render(): string { return ""; } }',
            'src/Resolver.php' => 'namespace App; class Resolver {'
                . ' public function resolve(): Denied|Identity|string { return ""; }'
                . ' public function owner(): int { return $this->resolve()->userId(); }'
                . ' public function show(Denied|Identity $outcome): string { return $outcome->render(); } }',
        ]);

        self::assertTrue($this->hasEdge($result->graph, 'App\Resolver::owner', 'App\Identity::userId', Relation::Calls, Confidence::Inferred));
        self::assertTrue($this->hasEdge($result->graph, 'App\Resolver::show', 'App\Denied::render', Relation::Calls, Confidence::Inferred));
    }

    public function testAPropertyDocumentedAsAUnionOfAProxyAndWhatItForwardsTo(): void
    {
        $result = $this->buildProject([
            'src/Proxy.php' => 'namespace Lib; final class Proxy { public function __call(string $m, array $a): mixed { return null; } }',
            'src/Gateway.php' => 'namespace App; interface Gateway { public function pay(): void; }',
            'src/Workflow.php' => 'namespace App; use Lib\Proxy; class Workflow { /** @var Proxy|Gateway */ private Proxy $gateway; public function run(): void { $this->gateway->pay(); } }',
        ]);

        self::assertTrue($this->hasEdge($result->graph, 'App\Workflow::run', 'App\Gateway::pay', Relation::Calls, Confidence::Inferred));
    }

    public function testAMixedMemberLeavesTheUnionUnknown(): void
    {
        $result = $this->buildProject([
            'src/Identity.php' => 'namespace App; class Identity { public function userId(): int { return 1; } }',
            'src/Resolver.php' => 'namespace App; class Resolver { /** @return Identity|mixed */ public function resolve() { return null; } public function owner(): int { return $this->resolve()->userId(); } }',
        ]);

        self::assertFalse($this->hasEdge($result->graph, 'App\Resolver::owner', 'App\Identity::userId', Relation::Calls, Confidence::Inferred));
    }

    public function testAClosureParameterIsWhatTheMethodDocumentsItPasses(): void
    {
        $result = $this->buildProject(self::BUILDER + [
            'src/Hotels.php' => 'namespace App; class Hotels { public function search(\Lib\Builder $query, ?string $city): array {'
                . ' return $query->when($city, fn ($query) => $query->where("city"))->when($city, callback: function ($q) { $q->where("name"); })->get(); } }',
        ]);
        $graph = $result->graph;

        self::assertTrue($this->hasEdge($graph, 'App\Hotels::search', 'Lib\Builder::where', Relation::Calls, Confidence::Inferred), '`$this` in the callable: the receiver');
        self::assertTrue($this->hasEdge($graph, 'App\Hotels::search', 'Lib\Builder::get', Relation::Calls, Confidence::Inferred), 'when() returns $this: the template of the method is left out');
        self::assertSame(0, $result->calls->unknownReceiver, 'by position and by name');
    }

    public function testATraitUsedWithArgumentsBindsItsTemplates(): void
    {
        $result = $this->buildProject([
            'src/Factory.php' => 'namespace Lib; abstract class Factory { public function count(int $n): static { return $this; } }',
            'src/HasFactory.php' => 'namespace Lib; /** @template TFactory of Factory */ trait HasFactory { /** @return TFactory */ public static function factory() { return null; } }',
            'src/HotelFactory.php' => 'namespace App; class HotelFactory extends \Lib\Factory { public function luxury(): static { return $this; } }',
            'src/Hotel.php' => 'namespace App; class Hotel { /** @use \Lib\HasFactory<HotelFactory> */ use \Lib\HasFactory; }',
            'src/Seeder.php' => 'namespace App; class Seeder { public function run(): void { Hotel::factory()->count(3)->luxury(); } }',
        ]);

        self::assertTrue($this->hasEdge($result->graph, 'App\Seeder::run', 'App\HotelFactory::luxury', Relation::Calls, Confidence::Inferred));
    }
}
