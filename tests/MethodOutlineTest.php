<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Builder\GraphBuilder;
use PhpGraph\Graph\Graph;
use PhpGraph\Presentation\TextPresenter;
use PhpGraph\Query\GraphQuery;
use PhpGraph\Query\Result\Outline;
use PhpGraph\Tests\Support\TemporaryProject;
use PHPUnit\Framework\TestCase;

/**
 * The outline of one method: what it runs, a few calls deep, not every class around its class.
 */
final class MethodOutlineTest extends TestCase
{
    use TemporaryProject;

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

    public function testACallGuessedFromAMethodNameStaysAGuess(): void
    {
        $this->buildProject([
            'src/GenerateFromUrl.php' => 'namespace App; class GenerateFromUrl { public function __construct(private Palette $palette) {} public function handle($brand): void { $brand->collect("colors"); $this->palette->build(); } }',
            'src/Palette.php' => 'namespace App; class Palette { public function build(): void {} }',
            'src/Unrelated.php' => 'namespace App; class Unrelated { public function collect(): array { return []; } }',
        ]);
        $graph = (new GraphBuilder())->build($this->root)->graph;
        $text = (new TextPresenter(new GraphQuery($graph, null, $this->root)))->outline('App\GenerateFromUrl::handle');

        self::assertStringContainsString('Guessed calls (AMBIGUOUS', $text);
        self::assertStringContainsString('GenerateFromUrl::handle() → Unrelated::collect()', $text);
        self::assertStringContainsString('Unrelated (class; guessed)', $text);
        self::assertStringContainsString('Palette (class)', $text, 'a typed call is no guess');
        self::assertStringContainsString('GenerateFromUrl::handle() → Palette::build(); guessed: Unrelated::collect()', $text);
    }

    public function testACoreWithoutACommonNamespaceIsListedByFullNamespace(): void
    {
        $this->buildProject([
            'app/Polls/CastVote.php' => 'namespace App\Polls; class CastVote { public function __construct(private \Ballot $ballot) {} public function handle(): void { $this->ballot->count(); } }',
            'legacy/Ballot.php' => 'class Ballot { public function count(): void {} }',
        ]);
        $graph = (new GraphBuilder())->build($this->root)->graph;
        $text = (new TextPresenter(new GraphQuery($graph, null, $this->root)))->outline('App\Polls\CastVote::handle');

        self::assertStringContainsString("Core, by namespace:\n  App\\Polls\n", $text);
        self::assertStringContainsString("\n  (no namespace)\n", $text);
    }

    public function testAPestTestCountsAsATestNotAsWiring(): void
    {
        $this->buildProject([
            'app/Polls/CastVote.php' => 'namespace App\Polls; class CastVote { public function handle(): void {} }',
            'app/Polls/VoteController.php' => 'namespace App\Polls; class VoteController { public function store(CastVote $vote): void { $vote->handle(); } }',
            'tests/Feature/CastVoteTest.php' => 'use App\Polls\CastVote; it("counts the vote", function () { $vote = new CastVote(); $vote->handle(); });',
        ]);
        $graph = (new GraphBuilder())->build($this->root)->graph;
        $text = (new TextPresenter(new GraphQuery($graph, null, $this->root)))->outline('CastVote');

        self::assertStringNotContainsString('Wiring', $text);
        self::assertStringContainsString('Tests touching the core: 1 files in 1 modules', $text);
        self::assertStringContainsString('touching the most: CastVoteTest', $text);
    }

    public function testNothingJoinsThroughAClassUsedByMany(): void
    {
        $files = [
            'app/Polls/PollVote.php' => 'namespace App\Polls; use App\Models\Commit; class PollVote { public function cast(Commit $commit, PollOption $option): void { $commit->touch(); $option->count(); } }',
            'app/Polls/PollOption.php' => 'namespace App\Polls; class PollOption { public function count(): void {} }',
            'app/Models/Commit.php' => 'namespace App\Models; class Commit { public function touch(): void {} public function star(): void {} public function pin(): void {} }',
        ];
        for ($i = 0; $i < 20; ++$i) {
            $files["app/Actions/StarCommit{$i}.php"] = "namespace App\\Actions; use App\\Models\\Commit; class StarCommit{$i} { public function run(Commit \$commit): void { \$commit->star(); } }";
        }
        $this->buildProject($files);
        $query = new GraphQuery((new GraphBuilder())->build($this->root)->graph, null, $this->root);

        $outline = $query->outline('poll votes on commits');
        self::assertNotNull($outline);
        self::assertContains('App\Models\Commit', $outline->core, 'the feature uses it');
        self::assertNotContains('App\Actions\StarCommit0', $outline->core, 'an action on a commit is no part of the polls');
        self::assertSame(['touch(): void'], $outline->facts['App\Models\Commit']->signatures ?? null, 'what the feature uses of it only');
    }

    public function testAClassOffTheTopicJoinsThroughAClassAboutTheQuestionOnly(): void
    {
        $this->buildProject([
            'app/Polls/PollOption.php' => 'namespace App\Polls; use App\Billing\Ledger; class PollOption { public function count(Ledger $ledger): void { $ledger->record($this); } public function label(): string { return ""; } }',
            'app/Billing/Ledger.php' => 'namespace App\Billing; use App\Polls\PollOption; use App\Audit\Trail; class Ledger { public function record(PollOption $option, Trail $trail): void { $option->label(); $trail->log($this); } public function total(): int { return 0; } }',
            'app/Audit/Trail.php' => 'namespace App\Audit; use App\Billing\Ledger; class Trail { public function log(Ledger $ledger): void { $ledger->total(); } }',
        ]);
        $query = new GraphQuery((new GraphBuilder())->build($this->root)->graph, null, $this->root);

        $outline = $query->outline('poll options');
        self::assertNotNull($outline);
        self::assertContains('App\Billing\Ledger', $outline->core, 'uses the poll option, which uses it back');
        self::assertNotContains('App\Audit\Trail', $outline->core, 'linked to the ledger only');
    }

    public function testOfClassesEquallyLinkedTheOneAboutTheQuestionJoinsFirst(): void
    {
        $files = [];
        for ($i = 0; $i < 4; ++$i) {
            $files["app/Billing/Invoice{$i}.php"] = "namespace App\\Billing; class Invoice{$i} {}";
        }
        $this->buildProject($files + [
            'app/Polls/PollOption.php' => 'namespace App\Polls; use App\Billing\Ledger; class PollOption { public function count(Ledger $ledger): void { $ledger->record($this); } public function label(): string { return ""; } }',
            'app/Billing/Ledger.php' => 'namespace App\Billing; use App\Polls\PollOption; class Ledger { public function record(PollOption $option): void { $option->label(); } }',
            'app/Reports/Poll/Summary.php' => 'namespace App\Reports\Poll; use App\Polls\PollOption; class Summary { public function show(PollOption $option): string { return $option->label(); } }',
        ]);
        $query = new GraphQuery((new GraphBuilder())->build($this->root)->graph, null, $this->root);

        $outline = $query->outline('poll options');
        self::assertNotNull($outline);
        $ledger = array_search('App\Billing\Ledger', $outline->core, true);
        $summary = array_search('App\Reports\Poll\Summary', $outline->core, true);
        self::assertIsInt($ledger);
        self::assertIsInt($summary);
        self::assertLessThan($ledger, $summary, 'both score 3: the summary of polls is about the question, the ledger is not');
    }

    public function testAWordInTheNamespaceOfMostClassesIsNoTopic(): void
    {
        $files = [];
        for ($i = 0; $i < 4; ++$i) {
            $files["src/SalesOrder/Core/Rate{$i}.php"] = "namespace App\\SalesOrder\\Core; class Rate{$i} {}";
        }
        $this->buildProject($files + [
            'src/SalesOrder/Customer/CustomerOrder.php' => 'namespace App\SalesOrder\Customer; use App\SalesOrder\Core\Compliance; use App\SalesOrder\Core\OrderStatus; class CustomerOrder { public function check(Compliance $compliance, OrderStatus $status): void { $compliance->level(); $status->name(); } }',
            'src/SalesOrder/Core/Compliance.php' => 'namespace App\SalesOrder\Core; class Compliance { public function level(): int { return 0; } }',
            'src/SalesOrder/Core/OrderStatus.php' => 'namespace App\SalesOrder\Core; class OrderStatus { public function name(): string { return ""; } }',
        ]);
        $query = new GraphQuery((new GraphBuilder())->build($this->root)->graph, null, $this->root);

        $outline = $query->outline('customer order');
        self::assertNotNull($outline);
        self::assertNotContains('App\SalesOrder\Core\Compliance', $outline->core, 'of the SalesOrder namespace, like every class');
        self::assertContains('App\SalesOrder\Core\OrderStatus', $outline->core, 'its own name says order');
    }

    public function testTheModuleKeepsWhatTheSeedRunsNotTheSiblingsSharingAWidget(): void
    {
        $this->buildProject([
            'app/Form/ShippingMethodType.php' => 'namespace App\Form; class ShippingMethodType { public function build(AddButtonType $button): void { $button->add(); } }',
            'app/Form/AddButtonType.php' => 'namespace App\Form; class AddButtonType { public function add(): void {} }',
            'app/Form/PromotionType.php' => 'namespace App\Form; class PromotionType { public function build(AddButtonType $button, PromotionScopeType $scope): void { $button->add(); $scope->add(); } }',
            'app/Form/PromotionScopeType.php' => 'namespace App\Form; class PromotionScopeType { public function add(): void {} }',
            'app/Form/ShippingFormController.php' => 'namespace App\Form; class ShippingFormController { public function edit(ShippingMethodType $type, UploadRule $rule): void { $type->build(new AddButtonType()); $rule->check(); } }',
            'app/Form/UploadRule.php' => 'namespace App\Form; class UploadRule { public function check(): void {} }',
        ]);
        $query = new GraphQuery((new GraphBuilder())->build($this->root)->graph, null, $this->root);

        $outline = $query->outline('shipping method');
        self::assertNotNull($outline);
        self::assertContains('App\Form\AddButtonType', $outline->core, 'what the seed uses');
        self::assertContains('App\Form\UploadRule', $outline->core, 'what the controller of the seed uses');
        self::assertNotContains('App\Form\PromotionType', $outline->core, 'another form using the same button');
        self::assertNotContains('App\Form\PromotionScopeType', $outline->core);
    }

    public function testAMethodNamedAfterTheQuestionInAClassAboutSomethingElseOpensNoOutline(): void
    {
        $this->buildProject([
            'app/Uploads/ImageService.php' => 'namespace App\Uploads; class ImageService { public function save(string $data): void {} }',
            'app/Uploads/ImageUploadException.php' => 'namespace App\Uploads; class ImageUploadException extends \RuntimeException {}',
            'app/Pages/PageContent.php' => 'namespace App\Pages; use App\Uploads\ImageService; class PageContent { public function __construct(private ImageService $images, private MarkdownConverter $markdown) {} public function uploadImage(string $data): void { $this->images->save($data); } public function render(): string { return $this->markdown->convert(); } }',
            'app/Pages/MarkdownConverter.php' => 'namespace App\Pages; class MarkdownConverter { public function convert(): string { return ""; } }',
        ]);
        $query = new GraphQuery((new GraphBuilder())->build($this->root)->graph, null, $this->root);

        $outline = $query->outline('image upload');
        self::assertNotNull($outline);
        self::assertContains('App\Uploads\ImageService', $outline->seeds);
        self::assertNotContains('App\Pages\PageContent', $outline->seeds, 'uploadImage() uses the feature, the page content is not it');
        self::assertNotContains('App\Pages\MarkdownConverter', $outline->core);
    }

    public function testARouteNamedAfterTheQuestionOpensItThroughItsController(): void
    {
        $this->buildProject([
            'app/Http/Controllers/LogController.php' => 'namespace App\Http\Controllers; use App\Queries\LogCommitsQuery; class LogController { public function index(LogCommitsQuery $commits): array { return $commits->get(); } }',
            'app/Queries/LogCommitsQuery.php' => 'namespace App\Queries; class LogCommitsQuery { public function get(): array { return []; } }',
            'app/Services/PageMetadata.php' => 'namespace App\Services; class PageMetadata { public function home(): array { return []; } }',
            'routes/web.php' => 'use App\Http\Controllers\LogController; use Illuminate\Support\Facades\Route; Route::get("/", [LogController::class, "index"])->name("home");',
        ]);
        $query = new GraphQuery((new GraphBuilder())->build($this->root)->graph, null, $this->root);

        $outline = $query->outline('home feed');
        self::assertNotNull($outline);
        self::assertContains('App\Http\Controllers\LogController', $outline->seeds, 'GET / is named home');
        self::assertContains('App\Http\Controllers\LogController', $outline->core);
        self::assertNotContains('App\Services\PageMetadata', $outline->seeds, 'its home() builds the page\'s preview card: a use of the feature');
    }

    public function testAModelUsedByManyShowsItsAttributesNamingTheCore(): void
    {
        $files = [
            'app/Enums/Log.php' => 'namespace App\Enums; use App\Models\User; enum Log: string { case Following = "following"; public function forUser(User $user): string { return $user->handle(); } }',
            'app/Models/User.php' => 'namespace App\Models; use App\Enums\Log; /** @property-read Log $log @property-read Profile $profile @property-read Team $team */ class User { public function casts(): array { return ["log" => Log::class]; } public function handle(): string { return ""; } }',
            'app/Models/Team.php' => 'namespace App\Models; use App\Enums\Log; class Team { public function casts(): array { return ["log" => Log::class]; } public function name(): string { return ""; } }',
            'app/Models/Profile.php' => 'namespace App\Models; class Profile {}',
            'app/Http/Controllers/LogController.php' => 'namespace App\Http\Controllers; use App\Enums\Log; use App\Models\Team; use App\Models\User; class LogController { public function index(User $user, Team $team, Log $log): string { return $log->forUser($user) . $team->name(); } }',
            'routes/web.php' => 'use App\Http\Controllers\LogController; use Illuminate\Support\Facades\Route; Route::get("/", [LogController::class, "index"])->name("home");',
        ];
        for ($i = 0; $i < 20; ++$i) {
            $files["app/Actions/Action{$i}.php"] = "namespace App\\Actions; use App\\Models\\Team; use App\\Models\\User; class Action{$i} { public function run(User \$user, Team \$team): string { return \$user->handle() . \$team->name(); } }";
        }
        $this->buildProject($files);
        $query = new GraphQuery((new GraphBuilder())->build($this->root)->graph, null, $this->root);

        $outline = $query->outline('home log');
        self::assertNotNull($outline);
        self::assertContains('App\Models\User', $outline->core);
        self::assertContains('App\Models\Team', $outline->core);
        self::assertSame(['log'], array_keys($outline->facts['App\Models\User']->properties ?? []), 'the tab it stores, not its profile nor its team, another model used by many');
        self::assertStringContainsString("    User (class)\n      \$log: Log", (new TextPresenter($query))->outline('home log'));
    }

    public function testTwoClassesOfOneNameAreToldApartInTheLists(): void
    {
        $this->buildProject([
            'app/Http/Controllers/PollController.php' => 'namespace App\Http\Controllers; use App\Polls\PollOption; class PollController { public function show(PollOption $option): void { $option->count(); } }',
            'app/Http/Controllers/Api/PollController.php' => 'namespace App\Http\Controllers\Api; use App\Polls\PollOption; class PollController { public function show(PollOption $option): void { $option->count(); } }',
            'app/Polls/PollOption.php' => 'namespace App\Polls; class PollOption { public function count(): void {} }',
        ]);
        $text = (new TextPresenter(new GraphQuery((new GraphBuilder())->build($this->root)->graph, null, $this->root)))->outline('poll');

        self::assertStringContainsString('Api\PollController', $text);
        self::assertStringContainsString('Controllers\PollController', $text);
        self::assertStringNotContainsString('PollController, PollController', $text);
    }

    public function testAFullCoreSaysWhatItLeftOut(): void
    {
        $files = [];
        $parameters = [];
        $calls = [];
        for ($i = 0; $i < 50; ++$i) {
            $parameters[] = "\\App\\Billing\\Ledger{$i} \$l{$i}";
            $calls[] = "\$l{$i}->add(\$this);";
            $files["app/Billing/Ledger{$i}.php"] = "namespace App\\Billing; use App\\Polls\\PollOption; class Ledger{$i} { public function add(PollOption \$option): void { \$option->label(); } }";
        }
        $files['app/Polls/PollOption.php'] = 'namespace App\Polls; class PollOption { public function count(' . implode(', ', $parameters) . '): void { ' . implode(' ', $calls) . ' } public function label(): string { return ""; } }';
        $this->buildProject($files);
        $query = new GraphQuery((new GraphBuilder())->build($this->root)->graph, null, $this->root);

        $outline = $query->outline('poll options');
        self::assertNotNull($outline);
        self::assertCount(45, $outline->core);
        self::assertCount(6, $outline->left, 'the 50 ledgers qualified, 44 joined');
        self::assertStringContainsString('The core is full: 6 more classes qualified, left out: ', (new TextPresenter($query))->outline('poll options'));
    }

    public function testAParentClassUsedByManyStandsForNoneOfItsSubclasses(): void
    {
        $files = [
            'app/Polls/PollOption.php' => 'namespace App\Polls; class PollOption { public function count(): void {} }',
            'app/Polls/PollController.php' => 'namespace App\Polls; use App\Http\Controller; class PollController extends Controller { public function vote(PollOption $option): void { $this->tally($option); } }',
            'app/Http/Controller.php' => 'namespace App\Http; use App\Polls\PollOption; class Controller { protected function tally(PollOption $option): void { $option->count(); } }',
        ];
        for ($i = 0; $i < 20; ++$i) {
            $files["app/Http/Page{$i}Controller.php"] = "namespace App\\Http; class Page{$i}Controller extends Controller {}";
        }
        $this->buildProject($files);
        $query = new GraphQuery((new GraphBuilder())->build($this->root)->graph, null, $this->root);

        $outline = $query->outline('poll options');
        self::assertNotNull($outline);
        self::assertContains('App\Http\Controller', $outline->core);
        self::assertContains('App\Polls\PollController', $outline->core, 'the polls\' controller, not one of the 21 the base stands for');
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

    public function testAMethodCalledFromItsOwnClassOnlyIsNoEntryPoint(): void
    {
        $presenter = new TextPresenter(new GraphQuery(self::graph(), null, self::FIXTURE));

        $internal = $presenter->outline('A::interne');
        self::assertStringNotContainsString('entry point', $internal);
        self::assertStringContainsString('A::interne() is called only from its own class: publique().', $internal);
        self::assertStringContainsString('No application class calls A::publique(): an entry point (no route found either', $presenter->outline('A::publique'), 'nothing calls it');
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
