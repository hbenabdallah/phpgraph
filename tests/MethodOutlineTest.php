<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Builder\GraphBuilder;
use PhpGraph\Graph\Graph;
use PhpGraph\Presentation\TextPresenter;
use PhpGraph\Query\GraphQuery;
use PhpGraph\Query\Result\Outline;
use PHPUnit\Framework\TestCase;

/**
 * The outline of one method: what it runs, a few calls deep, not every class around its class.
 */
final class MethodOutlineTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/Fixtures/method-outline';

    private static ?Graph $graph = null;

    public function testTheCoreIsWhatTheMethodCallsNotTheClassesReachedThroughAHub(): void
    {
        $outline = $this->outline();

        self::assertSame('ReservationCTRL::saveNewReservation', $outline->method);
        self::assertSame(['ReservationCTRL', 'ReservationDAO', 'DefaultActionCTRL'], $outline->core);
        self::assertSame(['Context::getDBconnection' => 21, 'SocieteDAO::getInstance' => 1], $outline->hubs, 'called by 20 classes and more, or a singleton\'s accessor: counted, not followed');
        self::assertSame(['ReservationCTRL::saveNewReservation' => 0, 'ReservationDAO::saveReservation' => 1, 'DefaultActionCTRL::addToForwardResponse' => 1, 'ReservationDAO::checkQuantity' => 2], $outline->depths);
    }

    public function testAParentClassShowsOnlyTheInheritedMethodsTheMethodCalls(): void
    {
        $outline = $this->outline();

        self::assertSame(['addToForwardResponse($key, $value)'], $outline->facts['DefaultActionCTRL']->signatures ?? null);
        self::assertSame([], $outline->facts['DefaultActionCTRL']->hints ?? null, 'exportCSV() throws, but the method never calls it');
        self::assertSame(['saveReservation($reservation)', 'checkQuantity($reservation)'], $outline->facts['ReservationDAO']->signatures ?? null, 'not purgeArchives()');
    }

    public function testBehaviourAndWiringKeepWhatTouchesTheMethodsItRuns(): void
    {
        $outline = $this->outline();

        self::assertSame(['ReservationDAO::checkQuantity L16'], array_map(static fn (array $hint): string => $hint[0] . '::' . $hint[1]->method . ' L' . $hint[1]->line, $outline->hints));
        self::assertSame(['batch/purge.php L4 ReservationDAO::saveReservation(…)'], array_map(static fn (array $call): string => $call['file'] . ' L' . $call['line'] . ' ' . $call['call'], $outline->wiring), 'not purgeArchives(), not getInstance()');
    }

    public function testTheTextSaysWhereTheOutlineStartsAndWhatItLeftOut(): void
    {
        $text = (new TextPresenter(new GraphQuery(self::graph(), null, self::FIXTURE)))->outline('ReservationCTRL::saveNewReservation()');

        self::assertStringContainsString('3 classes, from ReservationCTRL::saveNewReservation() and what it calls, 2 calls deep', $text);
        self::assertStringContainsString('ReservationCTRL::saveNewReservation() → ReservationDAO::saveReservation(), DefaultActionCTRL::addToForwardResponse()', $text);
        self::assertStringContainsString('Hubs it calls, not followed (application classes calling them): Context::getDBconnection() 21, SocieteDAO::getInstance() 1', $text);
        self::assertStringNotContainsString('exportCSV', $text);
        self::assertStringNotContainsString('Screen1', $text);
    }

    public function testAClassNamedExactlyIsTheOnlySeedAndItsSharedParentShowsWhatItUses(): void
    {
        $outline = (new GraphQuery(self::graph(), null, self::FIXTURE))->outline('ReservationCTRL');

        self::assertNotNull($outline);
        self::assertSame(['ReservationCTRL'], $outline->seeds, 'not every class whose name holds "reservation" or "ctrl"');
        self::assertSame('ReservationCTRL', $outline->core[0], 'the seed stays, though a member of the family of its parent');
        self::assertContains('DefaultActionCTRL', $outline->core);
        self::assertSame(['addToForwardResponse($key, $value)', 'exportCSV($rows)'], $outline->facts['DefaultActionCTRL']->signatures ?? null, 'the parent of 21 classes: the methods the core calls, not sendMail() nor testConnection()');
        self::assertNotContains('Context', $outline->core, 'reached through a hub only');
        self::assertNotContains('SocieteDAO', $outline->core, 'reached through getInstance() only');
    }

    public function testAClassNameStillGivesTheOutlineOfTheFeature(): void
    {
        $outline = (new GraphQuery(self::graph(), null, self::FIXTURE))->outline('ReservationDAO');

        self::assertNotNull($outline);
        self::assertNull($outline->method);
        self::assertContains('ReservationDAO', $outline->core);
        self::assertContains('purgeArchives($days)', $outline->facts['ReservationDAO']->signatures ?? []);
    }

    public function testAClassOfManyMethodsPointsToTheOutlineOfOneOfThem(): void
    {
        $query = new GraphQuery(self::graph(), null, self::FIXTURE);
        $outline = $query->outline('StockCTRL');

        self::assertNotNull($outline);
        self::assertSame(['class' => 'StockCTRL', 'methods' => 20, 'entries' => ['StockCTRL::moveStock' => ['routes' => 0, 'callers' => 1]]], $outline->large);
        self::assertNull($query->outline('ReservationCTRL')?->large, 'a few methods: the class outline is the feature');
        self::assertStringContainsString(
            'StockCTRL declares 20 methods, several features mixed: outline one of them for what it runs only, e.g. outline "StockCTRL::moveStock". Its entry points: moveStock() (called by 1 class).',
            (new TextPresenter($query))->outline('StockCTRL'),
        );
    }

    private function outline(): Outline
    {
        $outline = (new GraphQuery(self::graph(), null, self::FIXTURE))->outline('ReservationCTRL::saveNewReservation');
        self::assertNotNull($outline);

        return $outline;
    }

    private static function graph(): Graph
    {
        return self::$graph ??= (new GraphBuilder(readVendor: false))->build(self::FIXTURE)->graph;
    }
}
