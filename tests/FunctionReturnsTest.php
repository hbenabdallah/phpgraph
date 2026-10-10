<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Relation;
use PhpGraph\Tests\Support\TemporaryProject;
use PHPUnit\Framework\TestCase;

/**
 * What a function returns types the calls on it: the project's functions, and the helpers of the packages, read from
 * their autoloaded files; a conditional `@return` is decided by the arguments of the call.
 */
final class FunctionReturnsTest extends TestCase
{
    use TemporaryProject;

    private const HELPERS = [
        'composer.json' => '{}',
        'vendor/composer/installed.json' => '{"packages": [{"name": "acme/framework", "install-path": "../acme/framework", "autoload": {"psr-4": {"Acme\\\\": "src/"}, "files": ["src/helpers.php"]}}]}',
        'vendor/acme/framework/src/ResponseFactory.php' => "<?php\nnamespace Acme;\nclass ResponseFactory { public function json(array \$data): JsonResponse { return new JsonResponse(); } }\n",
        'vendor/acme/framework/src/JsonResponse.php' => "<?php\nnamespace Acme;\nclass JsonResponse { public function setStatusCode(int \$code): static { return \$this; } }\n",
        'vendor/acme/framework/src/Response.php' => "<?php\nnamespace Acme;\nclass Response {}\n",
        'vendor/acme/framework/src/Application.php' => "<?php\nnamespace Acme;\nclass Application { public function version(): string { return ''; } }\n",
        'vendor/acme/framework/src/helpers.php' => "<?php\nuse Acme\\Application;\nuse Acme\\ResponseFactory;\nuse Acme\\Response;\n\nif (! function_exists('response')) {\n    /**\n     * @return (\$content is null ? ResponseFactory : Response)\n     */\n    function response(\$content = null, \$status = 200)\n    {\n    }\n}\n\nif (! function_exists('app')) {\n    /**\n     * @template TClass of object\n     * @param  string|class-string<TClass>|null  \$abstract\n     * @return (\$abstract is class-string<TClass> ? TClass : (\$abstract is null ? Application : mixed))\n     */\n    function app(\$abstract = null)\n    {\n    }\n}\n",
    ];

    public function testAProjectFunctionTypesTheCallsOnWhatItReturns(): void
    {
        $result = $this->buildProject([
            'src/Theme.php' => 'class WP_Theme { public function exists(): bool { return true; } }',
            'src/theme.php' => '/** @return WP_Theme Theme object. */ function wp_get_theme($stylesheet = "") { return new WP_Theme(); }',
            'src/health.php' => 'namespace Health; function check(): bool { return wp_get_theme()->exists(); }',
        ]);

        self::assertTrue($this->hasEdge($result->graph, 'Health\check', 'WP_Theme::exists', Relation::Calls, Confidence::Inferred), 'an unqualified call in a namespace falls back to the global function');
    }

    public function testAHelperOfAPackageIsReadAndItsConditionalReturnDecidedByTheArguments(): void
    {
        $result = $this->buildWithHelpers([
            'src/Mailer.php' => 'namespace App; class Mailer { public function send(): void {} }',
            'src/Controller.php' => 'namespace App; class Controller {'
                . ' public function index(): void { response()->json([])->setStatusCode(201); }'
                . ' public function mail(): void { app(Mailer::class)->send(); }'
                . ' public function version(): string { return app()->version(); } }',
        ]);

        self::assertSame(0, $result->calls->unknownReceiver, 'response() without content is the factory, app(Mailer::class) a Mailer, app() the application');
        self::assertTrue($this->hasEdge($result->graph, 'App\Controller::mail', 'App\Mailer::send', Relation::Calls, Confidence::Inferred));
    }

    public function testAConditionalReturnIsNotGuessedWhenTheArgumentIsUnknown(): void
    {
        $result = $this->buildWithHelpers([
            'src/Controller.php' => 'namespace App; class Controller { public function show(string $content): void { response($content)->send(); } }',
        ]);

        self::assertSame(0, $result->calls->unknownReceiver - 1, 'a variable may be null or not: the receiver stays unknown');
    }

    /**
     * @param array<string, string> $files
     */
    private function buildWithHelpers(array $files): \PhpGraph\Builder\BuildResult
    {
        foreach (self::HELPERS as $path => $content) {
            @mkdir(\dirname($this->root . '/' . $path), 0777, true);
            file_put_contents($this->root . '/' . $path, $content);
        }

        return $this->buildProject($files);
    }
}
