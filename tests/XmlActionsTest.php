<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Builder\ActionMapping;
use PhpGraph\Builder\BuildResult;
use PhpGraph\Builder\GraphBuilder;
use PhpGraph\Builder\SourceFiles;
use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Relation;
use PhpGraph\Presentation\TextPresenter;
use PhpGraph\Project\BuildOptions;
use PhpGraph\Project\ProjectConfig;
use PhpGraph\Project\ProjectSummary;
use PhpGraph\Query\GraphQuery;
use PHPUnit\Framework\TestCase;

/**
 * Actions declared in an XML file of the project's own making, mapped by phpgraph.yaml: a route per action code,
 * handled by its controller method, leading to other actions and templates, quoted by the screens.
 */
final class XmlActionsTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/Fixtures/xml-actions';

    private const SAVE = 'route:Framework/actions.xml#ACTION saveReservation';

    private const SHOW = 'route:Framework/actions.xml#ACTION showReservations';

    private static ?BuildResult $result = null;

    public function testEachActionIsARouteHandledByTheMethodItNames(): void
    {
        $graph = self::build()->graph;

        self::assertSame('ACTION saveReservation', $graph->node(self::SAVE)?->label);
        self::assertSame(6, $graph->node(self::SAVE)->line);
        $this->assertEdge(self::SAVE, 'ReservationCTRL::saveReservation', Relation::HandledBy, Confidence::Extracted);
        $this->assertEdge(self::SHOW, 'DefaultActionCTRL::showReservations', Relation::HandledBy, Confidence::Extracted, 'a method inherited from the parent controller');
        $this->assertEdge(self::SHOW, 'ReservationCTRL', Relation::References, Confidence::Extracted, 'the controller the action names', 'controller');
        $this->assertEdge('route:Framework/actions.xml#ACTION printReservation', 'ReservationCTRL', Relation::HandledBy, Confidence::Extracted, 'no such method: the controller');
    }

    public function testTargetsAndForwardsLeadToActionsAndTemplates(): void
    {
        $this->assertEdge(self::SAVE, self::SHOW, Relation::References, Confidence::Extracted, 'target, another action', 'target');
        $this->assertEdge(self::SAVE, 'file:_Stock/views/SO_080/ReservationError.html', Relation::References, Confidence::Extracted, '_Stock/SO_080/... is under _Stock/views', 'forward KO');
        $this->assertEdge(self::SHOW, 'file:_Stock/views/SO_080/Reservations.html', Relation::References, Confidence::Extracted, '', 'target');
    }

    public function testTheFilesQuotingACodeReferenceItsAction(): void
    {
        $this->assertEdge('file:_Stock/views/SO_080/Reservations.html', self::SAVE, Relation::References, Confidence::Ambiguous, 'value="saveReservation"');
        $this->assertEdge('file:js/reservation.js', self::SAVE, Relation::References, Confidence::Ambiguous, "'saveReservation&id='");
        self::assertNull(self::edge('file:_Stock/views/SO_080/ReservationError.html', self::SAVE), 'a template quoting no code');
        self::assertNull(self::edge('file:js/reservation.js', 'route:Framework/actions.xml#ACTION default'), "cursor = 'default': a plain word is no citation");
    }

    public function testCountsWhatIsLinkedAndListsTheRest(): void
    {
        $stats = self::build()->actions;

        self::assertSame([4, 3, 1, 1], [$stats->actions, $stats->linked, $stats->toClass, $stats->unlinkedCount]);
        self::assertSame(['printReservation: ReservationCTRL has no method printReservation()'], $stats->unlinked);
        self::assertSame([4, 3, 2, ['missing.html']], [$stats->forwards, $stats->forwardsLinked, $stats->templates, $stats->unresolvedTargets]);
        self::assertSame([2, 2], [$stats->citations, $stats->citingFiles]);
    }

    public function testOverviewQueryImpactAndOutlineStartFromTheAction(): void
    {
        $result = self::build();
        $presenter = new TextPresenter(new GraphQuery($result->graph, ProjectSummary::fromBuild($result, new BuildOptions(), []), self::FIXTURE));

        $overview = $presenter->overview();
        self::assertStringContainsString("## Actions\n", $overview);
        self::assertStringContainsString('- 4 actions: 3 handled by a controller method, 1 by a controller lacking the method.', $overview);
        self::assertStringNotContainsString('## Routes', $overview, 'the actions are not listed as HTTP routes');

        self::assertStringContainsString('Seeds: ACTION saveReservation', $presenter->query('saveReservation routes'));

        $impact = $presenter->impact('ReservationCTRL::saveReservation');
        self::assertStringContainsString('ACTION saveReservation [EXTRACTED] ← saveReservation()', $impact);
        self::assertStringContainsString('screens quoting it [AMBIGUOUS]: _Stock/views/SO_080/Reservations.html L2, js/reservation.js L2', $impact);

        self::assertStringContainsString('ACTION saveReservation → ReservationCTRL::saveReservation()', $presenter->outline('ReservationCTRL::saveReservation'));
        self::assertStringContainsString('ACTION showReservations → DefaultActionCTRL::showReservations()', $presenter->outline('ReservationCTRL'), 'an inherited method, the action of this controller');
    }

    public function testTheConfigurationIsCheckedAndTheRoutingFileFingerprinted(): void
    {
        self::assertSame('{dir}/control/{name}CTRL.php', ProjectConfig::load(self::FIXTURE)->actions[0]->controllerFile ?? null);
        self::assertSame('_Stock/control/ReservationCTRL.php', ActionMapping::fill('{dir}/control/{name}CTRL.php', '_Stock/Reservation'));

        try {
            ActionMapping::fromArray(['file' => 'actions.xml', 'controller_file' => '{name}.php', 'methods' => 'm'], 'phpgraph.yaml');
            self::fail('an unknown key is refused');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('unknown key "methods"', $exception->getMessage());
        }

        $mappings = ProjectConfig::load(self::FIXTURE)->actions;
        self::assertNotSame(SourceFiles::fingerprint(self::FIXTURE), SourceFiles::fingerprint(self::FIXTURE, [], $mappings), 'the XML file and the quoting files count');
    }

    private function assertEdge(string $source, string $target, Relation $relation, Confidence $confidence, string $message = '', ?string $via = null): void
    {
        $edge = self::edge($source, $target, $relation);
        self::assertNotNull($edge, $message !== '' ? $message : $source . ' → ' . $target);
        self::assertSame($confidence, $edge->confidence, $message);
        if ($via !== null) {
            self::assertSame($via, $edge->via(), $message);
        }
    }

    private static function edge(string $source, string $target, Relation $relation = Relation::References): ?\PhpGraph\Graph\Edge
    {
        foreach (self::build()->graph->edges() as $edge) {
            if ($edge->source === $source && $edge->target === $target && $edge->relation === $relation) {
                return $edge;
            }
        }

        return null;
    }

    private static function build(): BuildResult
    {
        return self::$result ??= (new GraphBuilder(readVendor: false))->build(self::FIXTURE, [], null, null, ProjectConfig::load(self::FIXTURE)->actions);
    }
}
