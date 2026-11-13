<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use JsonSchema\Validator;
use PhpGraph\Builder\GraphBuilder;
use PhpGraph\Presentation\TextPresenter;
use PhpGraph\Query\GraphQuery;
use PhpGraph\Tests\Support\Dig;
use PhpGraph\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The JSON of `outline` and `impact` matches the schemas published in docs/schemas/: what agents and scripts rely on.
 */
final class JsonSchemaTest extends TestCase
{
    use TemporaryProject;

    private const SCHEMAS = __DIR__ . '/../docs/schemas/';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function outlines(): iterable
    {
        yield 'a feature in words' => [__DIR__ . '/Fixtures/feature-outline', 'notification validation'];
        yield 'a class named' => [__DIR__ . '/Fixtures/feature-outline', 'PlaceOrder'];
        yield 'a method' => [__DIR__ . '/Fixtures/method-outline', 'ReservationCTRL::saveNewReservation'];
        yield 'a class of many methods' => [__DIR__ . '/Fixtures/method-outline', 'StockCTRL'];
    }

    #[DataProvider('outlines')]
    public function testTheOutlineJsonMatchesItsSchema(string $project, string $topic): void
    {
        $json = $this->presenter($project)->outline($topic, 'json');

        self::assertMatchesSchema('outline', $json);
    }

    public function testTheOutlineOfAFullCoreMatchesItsSchemaWithWhatItLeftOut(): void
    {
        $files = [];
        $parameters = [];
        for ($i = 0; $i < 50; ++$i) {
            $parameters[] = "\\App\\Billing\\Ledger{$i} \$l{$i}";
            $files["app/Billing/Ledger{$i}.php"] = "namespace App\\Billing; use App\\Polls\\PollOption; class Ledger{$i} { public function add(PollOption \$option): void { \$option->label(); } }";
        }
        $files['app/Polls/PollOption.php'] = 'namespace App\Polls; class PollOption { public function count(' . implode(', ', $parameters) . '): void {} public function label(): string { return ""; } }';
        $this->write($files);

        $json = $this->presenter($this->root)->outline('poll options', 'json');

        self::assertStringContainsString('"leftReasons"', $json, 'the case this test is for');
        self::assertMatchesSchema('outline', $json);
    }

    public function testAnEmptyMapOfTheOutlineIsAnObject(): void
    {
        $json = $this->presenter(__DIR__ . '/Fixtures/method-outline')->outline('ReservationCTRL::saveNewReservation', 'json');

        self::assertStringContainsString('"fromFamilies": {}', $json, 'a map read as a list when empty would break typed readers');
        self::assertStringNotContainsString('"constants": []', $json, 'a class without constants leaves the key out');
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function impacts(): iterable
    {
        yield 'a class' => ['App\Validation\Notification', null];
        yield 'a method' => ['App\Validation\Notification::add', null];
        yield 'one section' => ['App\Validation\Notification', 'tests'];
    }

    #[DataProvider('impacts')]
    public function testTheImpactJsonMatchesItsSchema(string $name, ?string $section): void
    {
        $json = $this->presenter(__DIR__ . '/Fixtures/feature-outline')->impact($name, 3, 0, $section, 'json');

        self::assertMatchesSchema('impact', $json);
    }

    private function presenter(string $project): TextPresenter
    {
        return new TextPresenter(new GraphQuery((new GraphBuilder())->build($project)->graph, null, $project));
    }

    private static function assertMatchesSchema(string $schema, string $json): void
    {
        $data = json_decode($json);
        self::assertIsObject($data, 'an answer, not an error: ' . $json);
        $validator = new Validator();
        $validator->validate($data, (object) ['$ref' => 'file://' . realpath(self::SCHEMAS . $schema . '.schema.json')]);

        $errors = array_map(
            static fn (mixed $error): string => Dig::text($error, 'property') . ': ' . Dig::text($error, 'message'),
            $validator->getErrors(),
        );
        self::assertSame([], $errors, 'the JSON says what docs/schemas/' . $schema . '.schema.json promises');
    }
}
