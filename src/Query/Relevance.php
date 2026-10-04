<?php

declare(strict_types=1);

namespace PhpGraph\Query;

use PhpGraph\Graph\Graph;
use PhpGraph\Graph\Node;
use PhpGraph\Graph\NodeKind;

/**
 * How much a node is about a question, from the words of its name: deterministic, no model.
 *
 * - Names are split into words (`StockEligibilityResolver`: stock, eligibility, resolver), and both sides reduced to
 *   a crude stem: validated, validation and validator meet at "valid", prices and price at "price".
 * - A rare word weighs more than a common one: in an estimate application, "estimate" names hundreds of classes,
 *   "eligibility" a few.
 * - A word of the node's own name counts most, then its class (for a method), then its namespace.
 */
final class Relevance
{
    private const NAME = 3.0;

    private const OWNER = 2.0;

    private const NAMESPACE = 1.0;

    /** @var array<string, array<string, float>> node id => stem => weight */
    private array $words = [];

    /** @var array<string, int> stem => nodes naming it */
    private array $frequency = [];

    private int $count = 0;

    public function __construct(Graph $graph)
    {
        foreach ($graph->nodes() as $node) {
            if ($node->kind === NodeKind::File || $node->kind === NodeKind::External) {
                continue;
            }
            $words = $this->wordsOf($node);
            $this->words[$node->id] = $words;
            ++$this->count;
            foreach ($words as $stem => $weight) {
                if ($weight >= self::OWNER) {
                    $this->frequency[$stem] = ($this->frequency[$stem] ?? 0) + 1;
                }
            }
        }
    }

    /**
     * @return list<string> the stems of a text's words, without repeats
     */
    public static function stems(string $text): array
    {
        $text = preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', ' ', $text) ?? $text;
        $stems = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (mb_strlen($word) >= 3 && !ctype_digit($word)) {
                $stems[self::stem($word)] = true;
            }
        }

        return array_map('strval', array_keys($stems));
    }

    /**
     * A crude English stem, enough for validated, validation, validator and validate to meet (`validat`), or create,
     * creation and created (`creat`): plural and past forms, then -ation and -ator as -ate, then a final e.
     */
    public static function stem(string $word): string
    {
        $cut = static fn (string $word, string $suffix, string $by = ''): ?string => str_ends_with($word, $suffix) && \strlen($word) - \strlen($suffix) >= 3
            ? substr($word, 0, -\strlen($suffix)) . $by
            : null;

        $word = $cut($word, 'ies', 'y') ?? $cut($word, 'sses', 'ss') ?? (str_ends_with($word, 'ss') ? $word : $cut($word, 's'))
            ?? $cut($word, 'ed') ?? $cut($word, 'ing') ?? $cut($word, 'er') ?? $word;
        $word = $cut($word, 'ization', 'ize') ?? $cut($word, 'ation', 'ate') ?? $cut($word, 'ator', 'ate')
            ?? $cut($word, 'ments') ?? $cut($word, 'ment') ?? $cut($word, 'ity') ?? $word;

        return \strlen($word) > 4 && str_ends_with($word, 'e') ? substr($word, 0, -1) : $word;
    }

    /**
     * @param list<string> $terms   stems of the question
     * @param bool         $ownName only the words of its own name: a method of a class the question names does not
     *                              match through its class
     */
    public function score(Node $node, array $terms, bool $ownName = false): float
    {
        $words = $this->words[$node->id] ?? null;
        if ($words === null || $terms === []) {
            return 0.0;
        }

        $score = 0.0;
        $matched = 0;
        foreach ($terms as $term) {
            $weight = $words[$term] ?? 0.0;
            if ($ownName && $weight < self::NAME) {
                continue;
            }
            if ($weight > 0) {
                $score += $weight * log(1 + $this->count / (1 + ($this->frequency[$term] ?? 0)));
                ++$matched;
            }
        }

        // Naming more of the question's words beats naming one rare word: the coverage counts squared.
        return $score * ($matched / \count($terms)) ** 2;
    }

    /**
     * @param list<string> $terms
     *
     * @return list<string> the question's stems this node names
     */
    public function matched(Node $node, array $terms): array
    {
        $words = $this->words[$node->id] ?? [];

        return array_values(array_filter($terms, static fn (string $term): bool => isset($words[$term])));
    }

    /**
     * @return array<string, float> stem => weight
     */
    private function wordsOf(Node $node): array
    {
        $words = [];
        $add = static function (string $text, float $weight) use (&$words): void {
            foreach (self::stems($text) as $stem) {
                $words[$stem] = max($words[$stem] ?? 0.0, $weight);
            }
        };

        $id = str_contains($node->id, '@') ? substr($node->id, (int) strpos($node->id, '@') + 1) : $node->id;
        if ($node->kind === NodeKind::Route) {
            $add($node->label, self::NAME);
            $add((string) $node->file, self::NAMESPACE);

            return $words;
        }
        if ($node->kind === NodeKind::Channel) {
            $add($node->label, self::NAME);

            return $words;
        }

        [$owner, $member] = str_contains($id, '::') ? explode('::', $id, 2) : [$id, null];
        $segments = explode('\\', (string) $owner);
        $short = (string) array_pop($segments);
        if ($member !== null) {
            $add($member, self::NAME);
            $add($short, self::OWNER);
        } else {
            $add($short, self::NAME);
        }
        $add(implode(' ', $segments), self::NAMESPACE);

        return $words;
    }
}
