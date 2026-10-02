<?php

declare(strict_types=1);

namespace PhpGraph\Builder;

/**
 * What the bus detection found in application code, and what it could not link.
 */
final readonly class BusStats
{
    /**
     * @param array<string, int> $handlers               confidence => handlers linked to their message
     * @param array<string, int> $sends                  confidence => sends linked to their message
     * @param int                $untypedSends           sends to a bus whose message type is unknown
     * @param list<string>       $messagesWithoutHandler sent messages no project handler handles (first ones)
     * @param list<string>       $messagesNeverSent      handled messages the project never sends (first ones)
     * @param int                $contracts              message classes sent by one service and handled by another
     */
    public function __construct(
        public array $handlers = [],
        public array $sends = [],
        public int $untypedSends = 0,
        public int $messagesWithoutHandlerCount = 0,
        public array $messagesWithoutHandler = [],
        public int $messagesNeverSentCount = 0,
        public array $messagesNeverSent = [],
        public int $contracts = 0,
    ) {
    }

    /**
     * @param array<mixed> $data as written by toArray()
     */
    public static function fromArray(array $data): self
    {
        $counts = static fn (string $key): array => array_filter(\is_array($data[$key] ?? null) ? $data[$key] : [], 'is_int');
        $names = static fn (string $key): array => array_values(array_filter(\is_array($data[$key] ?? null) ? $data[$key] : [], 'is_string'));
        $int = static fn (string $key): int => \is_int($data[$key] ?? null) ? $data[$key] : 0;

        /** @var array<string, int> $handlers */
        $handlers = $counts('handlers');
        /** @var array<string, int> $sends */
        $sends = $counts('sends');

        return new self(
            $handlers,
            $sends,
            $int('untypedSends'),
            $int('messagesWithoutHandlerCount'),
            $names('messagesWithoutHandler'),
            $int('messagesNeverSentCount'),
            $names('messagesNeverSent'),
            $int('contracts'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'handlers' => $this->handlers,
            'sends' => $this->sends,
            'untypedSends' => $this->untypedSends,
            'messagesWithoutHandlerCount' => $this->messagesWithoutHandlerCount,
            'messagesWithoutHandler' => $this->messagesWithoutHandler,
            'messagesNeverSentCount' => $this->messagesNeverSentCount,
            'messagesNeverSent' => $this->messagesNeverSent,
            'contracts' => $this->contracts,
        ];
    }

    public function isEmpty(): bool
    {
        return $this->handlers === [] && $this->sends === [] && $this->untypedSends === 0;
    }
}
