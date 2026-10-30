<?php

declare(strict_types=1);

namespace PhpGraph\Extractor;

/**
 * What a configuration helper injects into a service: `->args([tagged_iterator($prefix . '.rule')])`, `service($id)`.
 */
final readonly class ServiceArgumentFact
{
    /**
     * @param Template  $id      the service receiving it
     * @param ?Template $tag     the tag whose services it receives, null for one service
     * @param ?Template $service the service it receives, null for a tag
     * @param bool      $locator `tagged_locator()` rather than `tagged_iterator()`
     */
    public function __construct(
        public Template $id,
        public ?Template $tag,
        public ?Template $service,
        public bool $locator = false,
    ) {
    }
}
