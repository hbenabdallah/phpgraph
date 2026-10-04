<?php

declare(strict_types=1);

namespace PhpGraph\Extractor;

use PhpParser\Node as AstNode;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/**
 * Reads what a file's classes say of themselves, for an outline: kind and modifiers, the first sentence of their
 * docblock, public signatures, constants and enum cases, and what their methods check, throw and return early.
 * Names stay as written in the file: `?PathContext`, not the fully qualified name.
 */
final class SourceFacts
{
    private const TEXT = 90;

    private readonly Parser $parser;

    private readonly Standard $printer;

    /** @var array<string, string> constants of the class being read, for the values in conditions */
    private array $constants = [];

    /** @var list<Hint> */
    private array $hints = [];

    private string $method = '';

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->printer = new Standard();
    }

    /**
     * @return array<string, ClassFacts> class id => its facts
     */
    public function read(string $code): array
    {
        try {
            $statements = $this->parser->parse($code) ?? [];
        } catch (\PhpParser\Error) {
            return [];
        }
        $statements = (new NodeTraverser(new NameResolver(null, ['replaceNodes' => false])))->traverse($statements);

        $facts = [];
        foreach ((new NodeFinder())->findInstanceOf($statements, Stmt\ClassLike::class) as $class) {
            $name = $class->namespacedName?->toString();
            if ($name !== null) {
                $facts[$name] = $this->classFacts($name, $class);
            }
        }

        return $facts;
    }

    private function classFacts(string $id, Stmt\ClassLike $class): ClassFacts
    {
        $this->constants = [];
        foreach ($class->getConstants() as $group) {
            foreach ($group->consts as $constant) {
                $this->constants[$constant->name->toString()] = $this->text($constant->value, 40);
            }
        }
        $cases = [];
        foreach ($class->stmts as $statement) {
            if ($statement instanceof Stmt\EnumCase) {
                $cases[$statement->name->toString()] = $statement->expr === null ? '' : $this->text($statement->expr, 40);
            }
        }

        $modifiers = [];
        if ($class instanceof Stmt\Class_) {
            $modifiers = array_values(array_filter([
                $class->isAbstract() ? 'abstract' : null,
                $class->isFinal() ? 'final' : null,
                $class->isReadonly() ? 'readonly' : null,
            ]));
        }

        $signatures = [];
        $this->hints = [];
        foreach ($class->getMethods() as $method) {
            $this->method = $method->name->toString();
            if ($this->isShown($class, $method)) {
                $signatures[] = $this->signature($method);
            }
            $this->block($method->stmts ?? [], null, []);
            $this->comparisons($method);
        }

        return new ClassFacts(
            $id,
            match (true) {
                $class instanceof Stmt\Interface_ => 'interface',
                $class instanceof Stmt\Trait_ => 'trait',
                $class instanceof Stmt\Enum_ => 'enum' . ($class->scalarType === null ? '' : ': ' . $class->scalarType->toString()),
                default => 'class',
            },
            $modifiers,
            $this->summary($class->getDocComment()?->getText()),
            $signatures,
            $this->constants,
            $cases,
            $this->hints,
        );
    }

    /**
     * Public methods, and the abstract protected hooks of a template method. A constructor only when it promotes
     * public properties: the data of a value object, not its services.
     */
    private function isShown(Stmt\ClassLike $class, Stmt\ClassMethod $method): bool
    {
        if (strtolower($method->name->toString()) === '__construct') {
            return array_filter($method->params, static fn (AstNode\Param $param): bool => ($param->flags & \PhpParser\Modifiers::PUBLIC) !== 0) !== [];
        }

        return $method->isPublic() || ($method->isAbstract() && $method->isProtected()) || $class instanceof Stmt\Interface_;
    }

    private function signature(Stmt\ClassMethod $method): string
    {
        $parameters = [];
        foreach ($method->params as $param) {
            $name = $param->var instanceof Expr\Variable && \is_string($param->var->name) ? $param->var->name : '';
            $type = $this->type($param->type);
            // A parameter named after its type says nothing more: `Notification`, not `Notification $notification`.
            $named = $type === null || strcasecmp(ltrim($type, '?'), $name) !== 0 || $param->variadic || $param->default !== null;
            $parameters[] = trim(implode(' ', array_filter([
                $type,
                // An optional parameter, its default left out: `$path?`.
                $named ? ($param->variadic ? '...' : '') . '$' . $name . ($param->default === null ? '' : '?') : null,
            ])));
        }
        $return = $this->documentedReturn($method->getDocComment()?->getText()) ?? $this->type($method->returnType);

        return ($method->isStatic() ? 'static ' : '') . ($method->isProtected() ? 'protected ' : '')
            . $method->name->toString() . '(' . implode(', ', $parameters) . ')' . ($return === null ? '' : ': ' . $return);
    }

    /**
     * A documented collection says more than `array`: `list<Violation>`.
     */
    private function documentedReturn(?string $doc): ?string
    {
        return $doc !== null && preg_match('/@return\s+(\S*<[^\s]+>|\S+\[\])/', $doc, $match) === 1 ? $match[1] : null;
    }

    private function type(?AstNode $type): ?string
    {
        return match (true) {
            $type === null => null,
            $type instanceof AstNode\Identifier, $type instanceof Name => $type->toString(),
            $type instanceof AstNode\NullableType => '?' . $this->type($type->type),
            $type instanceof AstNode\UnionType => implode('|', array_map(fn (AstNode $inner): string => (string) $this->type($inner), $type->types)),
            $type instanceof AstNode\IntersectionType => implode('&', array_map(fn (AstNode $inner): string => (string) $this->type($inner), $type->types)),
            default => null,
        };
    }

    /**
     * The first sentence of a docblock, its tags left out.
     */
    private function summary(?string $doc): ?string
    {
        if ($doc === null) {
            return null;
        }
        $lines = [];
        foreach (preg_split('/\R/', $doc) ?: [] as $line) {
            $line = trim(preg_replace('#^\s*(/\*\*|\*/|\*)#', '', $line) ?? '');
            if (str_starts_with($line, '@')) {
                break;
            }
            $lines[] = $line;
        }
        $text = trim(preg_replace('/\s+/', ' ', implode(' ', $lines)) ?? '');
        if ($text === '') {
            return null;
        }
        $sentence = preg_match('/^(.+?[.!?])(\s|$)/', $text, $match) === 1 ? $match[1] : $text;

        return $this->shorten($sentence, 140);
    }

    /**
     * Reads a block in order: a throw and an early return with the conditions guarding them (the `if` around, the
     * `continue` before them, the loop they run in), a value chosen between constants, a loop bounded by a constant.
     *
     * @param array<Stmt> $statements
     * @param list<string> $unless conditions skipping the rest of the block (`if ($x) { continue; }`)
     */
    private function block(array $statements, ?string $loop, array $unless): void
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\If_) {
                $condition = $this->text($statement->cond);
                $body = $statement->stmts;
                if ($statement->elseifs === [] && $statement->else === null && \count($body) === 1) {
                    $only = $body[0];
                    if ($only instanceof Stmt\Continue_) {
                        $unless[] = $condition;
                        continue;
                    }
                    if ($only instanceof Stmt\Return_) {
                        $this->hint($statement, \sprintf('returns %s if %s', $this->returned($only->expr), $condition), $loop);
                        continue;
                    }
                }
                foreach ($body as $index => $inner) {
                    if ($inner instanceof Stmt\Expression && $inner->expr instanceof Expr\Throw_) {
                        $this->hint($inner, $this->thrown($inner->expr) . ' if ' . $condition, $loop);
                        unset($body[$index]);
                    }
                }
                $this->block($body, $loop, []);
                foreach ($statement->elseifs as $elseif) {
                    $this->block($elseif->stmts, $loop, []);
                }
                $this->block($statement->else->stmts ?? [], $loop, []);
                continue;
            }
            if ($statement instanceof Stmt\Expression && $statement->expr instanceof Expr\Throw_) {
                $this->hint($statement, $this->thrown($statement->expr) . ($unless === [] ? '' : ' unless ' . implode(' or ', $unless)), $loop);
                continue;
            }
            if ($statement instanceof Stmt\Expression && $statement->expr instanceof Expr\Assign && $statement->expr->expr instanceof Expr\Ternary) {
                $ternary = $statement->expr->expr;
                if ($ternary->if !== null && $this->isConstant($ternary->if) && $this->isConstant($ternary->else)) {
                    $this->hint($statement, \sprintf(
                        '%s = %s if %s, else %s',
                        $this->text($statement->expr->var, 30),
                        $this->text($ternary->if, 50),
                        $this->text($ternary->cond),
                        $this->text($ternary->else, 50),
                    ), $loop);
                }
                continue;
            }
            if ($statement instanceof Stmt\Foreach_) {
                $this->block($statement->stmts, 'for each of ' . $this->text($statement->expr, 50), []);
                continue;
            }
            if ($statement instanceof Stmt\While_ || $statement instanceof Stmt\Do_ || $statement instanceof Stmt\For_) {
                $conditions = $statement instanceof Stmt\For_ ? $statement->cond : [$statement->cond];
                foreach ($conditions as $condition) {
                    if ($this->capped($condition)) {
                        $this->hint($statement, 'loops while ' . $this->text($condition), null);
                    }
                }
                $this->block($statement->stmts, $loop ?? 'in a loop', []);
                continue;
            }
            if ($statement instanceof Stmt\TryCatch) {
                $this->block($statement->stmts, $loop, $unless);
                foreach ($statement->catches as $catch) {
                    $this->block($catch->stmts, $loop, []);
                }
                continue;
            }
            if ($statement instanceof Stmt\Block) {
                $this->block($statement->stmts, $loop, $unless);
            }
        }
    }

    /**
     * `$before = $context->all(); ... if ($context->all() !== $before)`: the method compares the state before and
     * after what runs in between.
     */
    private function comparisons(Stmt\ClassMethod $method): void
    {
        $finder = new NodeFinder();
        $saved = [];
        foreach ($finder->findInstanceOf($method->stmts ?? [], Expr\Assign::class) as $assign) {
            if ($assign->var instanceof Expr\Variable && \is_string($assign->var->name)
                && ($assign->expr instanceof Expr\MethodCall || $assign->expr instanceof Expr\FuncCall || $assign->expr instanceof Expr\PropertyFetch)) {
                $saved[$assign->var->name] = $this->text($assign->expr, 200);
            }
        }
        if ($saved === []) {
            return;
        }
        foreach ($finder->findInstanceOf($method->stmts ?? [], Expr\BinaryOp::class) as $comparison) {
            foreach ([[$comparison->left, $comparison->right], [$comparison->right, $comparison->left]] as [$variable, $other]) {
                if ($variable instanceof Expr\Variable && \is_string($variable->name) && isset($saved[$variable->name])
                    && $this->text($other, 200) === $saved[$variable->name]) {
                    $this->hint($comparison, \sprintf('compares %s before and after', $this->shorten($saved[$variable->name], 60)), null);
                }
            }
        }
    }

    private function hint(AstNode $node, string $text, ?string $loop): void
    {
        $this->hints[] = new Hint($this->method, $node->getStartLine(), $text . ($loop === null ? '' : ', ' . $loop));
    }

    private function thrown(Expr\Throw_ $throw): string
    {
        $exception = $throw->expr;
        if (!$exception instanceof Expr\New_) {
            return 'throws ' . $this->text($exception, 40);
        }
        $class = $exception->class instanceof Name ? $exception->class->getLast() : 'an exception';
        $message = null;
        $first = $exception->args[0] ?? null;
        $value = $first instanceof AstNode\Arg ? $first->value : null;
        if ($value instanceof Expr\FuncCall && $value->name instanceof Name && \in_array(strtolower($value->name->toString()), ['sprintf', 'vsprintf'], true)) {
            $value = ($value->args[0] ?? null) instanceof AstNode\Arg ? $value->args[0]->value : null;
        }
        if ($value instanceof Scalar\String_) {
            $message = $this->shorten($value->value, 64);
        }

        return 'throws ' . $class . ($message === null ? '' : ' "' . $message . '"');
    }

    private function returned(?Expr $expr): string
    {
        return match (true) {
            $expr === null => 'early',
            $expr instanceof Expr\StaticCall && $expr->class instanceof Name && $expr->name instanceof AstNode\Identifier => $expr->class->toString() . '::' . $expr->name->toString() . '()',
            $expr instanceof Expr\MethodCall && $expr->name instanceof AstNode\Identifier => $this->text($expr->var, 30) . '->' . $expr->name->toString() . '()',
            $expr instanceof Expr\New_ && $expr->class instanceof Name => 'new ' . $expr->class->getLast(),
            default => $this->text($expr, 40),
        };
    }

    private function isConstant(Expr $expr): bool
    {
        return $expr instanceof Expr\ClassConstFetch || $expr instanceof Expr\ConstFetch || $expr instanceof Scalar;
    }

    /**
     * A loop condition comparing with a constant of the class: a cap.
     */
    private function capped(Expr $condition): bool
    {
        foreach ((new NodeFinder())->findInstanceOf([$condition], Expr\ClassConstFetch::class) as $fetch) {
            if ($fetch->class instanceof Name && \in_array(strtolower($fetch->class->toString()), ['self', 'static'], true)
                && $fetch->name instanceof AstNode\Identifier && isset($this->constants[$fetch->name->toString()])) {
                return true;
            }
        }

        return false;
    }

    /**
     * An expression as written, the value of the class's own constants added: `self::MAX_CYCLES (10)`.
     */
    private function text(Expr $expr, int $limit = self::TEXT): string
    {
        $text = $this->printer->prettyPrintExpr($expr);
        $text = preg_replace_callback('/\b(self|static)::([A-Z_][A-Z0-9_]*)\b(?!\()/', function (array $match): string {
            $value = $this->constants[$match[2]] ?? null;

            return $match[0] . ($value !== null && preg_match('/^-?\d+(\.\d+)?$/', $value) === 1 ? ' (' . $value . ')' : '');
        }, $text) ?? $text;

        return $this->shorten((string) preg_replace('/\s+/', ' ', $text), $limit);
    }

    /**
     * The start and the end of a long text: an exception message says the most in its last words.
     */
    private function shorten(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        $head = (int) floor($limit * 0.55);

        return mb_substr($text, 0, $head) . '…' . mb_substr($text, -($limit - $head - 1));
    }
}
