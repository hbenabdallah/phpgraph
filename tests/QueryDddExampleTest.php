<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Builder\GraphBuilder;
use PhpGraph\Graph\Node;
use PhpGraph\Graph\NodeKind;
use PhpGraph\Presentation\TextPresenter;
use PhpGraph\Query\GraphQuery;
use PHPUnit\Framework\TestCase;

/**
 * Questions on php-ddd-example, from an agent's bench: every route about the topic, before anything else, the
 * subscribers of an event linked to it, in an answer short enough to stay in the agent's context.
 */
final class QueryDddExampleTest extends TestCase
{
    private static ?GraphQuery $query = null;

    private static function query(): GraphQuery
    {
        return self::$query ??= new GraphQuery((new GraphBuilder())->build(__DIR__ . '/Fixtures/ddd-example')->graph);
    }

    /**
     * @return list<string>
     */
    private static function seeds(string $question): array
    {
        $seeds = array_map(static fn (Node $node): string => $node->label, self::query()->subgraph($question)->seeds);
        sort($seeds);

        return $seeds;
    }

    public function testEveryRouteAboutTheTopicIsASeed(): void
    {
        $courses = ['GET /api/courses', 'GET /courses', 'GET /courses', 'GET /courses-counter', 'POST /courses', 'PUT /courses/{id}'];

        self::assertSame($courses, self::seeds('courses routes HTTP'), 'the path, routing file or controller names the topic');
        self::assertSame($courses, self::seeds('routes courses'));
        self::assertSame(['GET /courses-counter', 'PUT /courses/{id}'], self::seeds('mooc courses routes'));
        self::assertCount(13, self::seeds('all routes'));
    }

    public function testTheRoutesComeFirstAndNothingExternalCrowdsThem(): void
    {
        $nodes = self::query()->subgraph('courses routes HTTP')->nodes;
        $kinds = array_map(static fn (Node $node): NodeKind => $node->kind, $nodes);

        self::assertSame(array_fill(0, 6, NodeKind::Route), \array_slice($kinds, 0, 6));
        self::assertNotContains(NodeKind::External, $kinds);
        self::assertSame([], array_values(array_filter($nodes, static fn (Node $node): bool => str_contains($node->label, 'Uuid')
            || str_contains($node->label, 'Response::'))), 'no value object getter');
    }

    public function testTheAnswerIsShort(): void
    {
        $text = (new TextPresenter(self::query()))->query('courses routes HTTP');

        self::assertLessThan(2500, \strlen($text), $text);
        self::assertStringContainsString('  - CoursesPutController [class] apps/mooc/backend/src/Controller/Courses/CoursesPutController.php L12; methods __invoke() L14', $text, 'a method on its class line');
        self::assertStringNotContainsString('has_method', $text, 'implied by the class line');
        self::assertStringNotContainsString('(external)', $text);
    }

    public function testEdgesOfOneSourceAndRelationShareALine(): void
    {
        $text = (new TextPresenter(self::query()))->query('courses routes HTTP');

        self::assertSame(2, substr_count($text, 'GET /courses --handled_by--> '), 'two routes, one per application');
        self::assertStringContainsString('WebController --extends--> ApiController [EXTRACTED]', $text);
        self::assertStringContainsString('CoursesPostWebController::__invoke() --calls--> CoursesPostWebController::createCourse() at L', $text, 'call lines kept');
    }

    public function testTheSubscribersOfAnEventAreLinkedToIt(): void
    {
        $text = (new TextPresenter(self::query()))->query('course created event handler');

        self::assertStringContainsString('CourseCreatedDomainEvent --handled_by--> CreateBackofficeCourseOnCourseCreated::__invoke(); IncrementCoursesCounterOnCourseCreated::__invoke()', $text, 'the method standing for its class comes along');
        self::assertStringContainsString('IncrementCoursesCounterOnCourseCreated [class] src/Mooc/CoursesCounter/Application/Increment/IncrementCoursesCounterOnCourseCreated.php L13; methods __invoke() L22', $text);
        self::assertStringNotContainsString('IncrementCoursesCounterOnCourseCreated::__invoke() --', $text, 'only the link that brought it');
    }

    public function testEveryRouteIsLinkedToItsController(): void
    {
        $text = (new TextPresenter(self::query()))->query('all routes');

        self::assertSame(12, substr_count($text, ' --handled_by--> '), 'all but GET /api/courses, whose controller exists nowhere');
    }
}
