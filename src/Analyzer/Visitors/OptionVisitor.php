<?php

declare(strict_types=1);

namespace Sediment\Analyzer\Visitors;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeVisitorAbstract;
use Sediment\Analyzer\Finding;

/**
 * Detects option writes (M3): add_option, update_option, and their site
 * variants, capturing the option key and source location.
 *
 * Spike behaviour: a literal string key is `verified`; anything else is
 * `dynamic`. Constant/class-const/property resolution (`resolved`) and prefix
 * extraction (`pattern`) arrive with the symbol-table pass (§6, day 3-5).
 *
 * `register_setting` is deliberately excluded: it registers a setting with the
 * Settings API but does not itself write an option row, so treating it as a
 * definite create would manufacture false positives (see spec Appendix / §7 note).
 */
final class OptionVisitor extends NodeVisitorAbstract
{
    /**
     * Option-writing functions mapped to the argument index holding the key.
     *
     * @var array<string, int>
     */
    private const FUNCTIONS = [
        'add_option'         => 0,
        'update_option'      => 0,
        'add_site_option'    => 0,
        'update_site_option' => 0,
    ];

    /** @var list<Finding> */
    private array $findings = [];

    public function __construct(private readonly string $file)
    {
    }

    public function enterNode(Node $node)
    {
        if (!$node instanceof FuncCall || !$node->name instanceof Name) {
            return null;
        }

        $function = strtolower($node->name->toString());
        if (!isset(self::FUNCTIONS[$function])) {
            return null;
        }

        $keyArgument = $node->getArgs()[self::FUNCTIONS[$function]] ?? null;

        if ($keyArgument !== null && $keyArgument->value instanceof String_) {
            $this->findings[] = new Finding(
                type: 'option',
                function: $function,
                key: $keyArgument->value->value,
                confidence: Finding::CONFIDENCE_VERIFIED,
                file: $this->file,
                line: $node->getStartLine(),
            );

            return null;
        }

        // Unresolved at spike level. The symbol-table pass will reclassify many
        // of these as `resolved` or `pattern`; for now they are honestly dynamic.
        $this->findings[] = new Finding(
            type: 'option',
            function: $function,
            key: null,
            confidence: Finding::CONFIDENCE_DYNAMIC,
            file: $this->file,
            line: $node->getStartLine(),
            expression: $keyArgument !== null ? '(unresolved expression)' : null,
        );

        return null;
    }

    /** @return list<Finding> */
    public function findings(): array
    {
        return $this->findings;
    }
}
