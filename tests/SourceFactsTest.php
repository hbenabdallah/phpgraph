<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Extractor\SourceFacts;
use PHPUnit\Framework\TestCase;

/**
 * What a class says of itself in its source, read for an outline.
 */
final class SourceFactsTest extends TestCase
{
    public function testThePropertiesNamingClassesAreReadFromCastsDocblocksAndTypes(): void
    {
        $facts = (new SourceFacts())->read('<?php namespace App\Models; use App\Enums\Log; use App\Enums\Theme; use Illuminate\Support\Collection;'
            . ' /** @property-read Log $log @property-read string $handle @property-read Collection<int, Commit>|null $commits */'
            . ' final class User { protected $casts = ["theme" => Theme::class, "email" => "string"]; public ?Profile $profile = null;'
            . ' public function __construct(private readonly \App\Clock $clock, string $name) {} }');

        self::assertSame([
            'theme' => ['type' => 'Theme', 'classes' => ['App\Enums\Theme']],
            'log' => ['type' => 'Log', 'classes' => ['App\Enums\Log']],
            'commits' => ['type' => 'Collection<int, Commit>|null', 'classes' => ['Illuminate\Support\Collection', 'App\Models\Commit']],
            'profile' => ['type' => '?Profile', 'classes' => ['App\Models\Profile']],
            'clock' => ['type' => 'App\Clock', 'classes' => ['App\Clock']],
        ], $facts['App\Models\User']->properties ?? null, 'not $handle, $email nor $name: no class');
    }
}
