<?php

declare(strict_types=1);

namespace Sediment\Analyzer\Visitors;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use Sediment\Analyzer\Finding;
use Sediment\Analyzer\Resolution;

/**
 * Detects metadata writes (M4): add_*_meta / update_*_meta for posts, users,
 * terms, and comments — one finding per call, keyed by the meta key (arg 1,
 * `meta_key`).
 *
 * register_meta($object_type, $meta_key, $args) is a special case: the type
 * of meta it registers is not fixed by the function name but by its first
 * argument. That argument is resolved like any other key, then mapped to one
 * of the four meta types. If it doesn't resolve to exactly one of the four
 * known literals ('post' | 'user' | 'term' | 'comment'), the object type is
 * unknowable, so nothing is emitted rather than guessing which meta table it
 * touches.
 */
final class MetaVisitor extends AbstractDetectionVisitor
{
    /** function => finding type, for the fixed-type add/update meta calls */
    private const FUNCTIONS = [
        'add_post_meta'       => 'post_meta',
        'update_post_meta'    => 'post_meta',
        'add_user_meta'       => 'user_meta',
        'update_user_meta'    => 'user_meta',
        'add_term_meta'       => 'term_meta',
        'update_term_meta'    => 'term_meta',
        'add_comment_meta'    => 'comment_meta',
        'update_comment_meta' => 'comment_meta',
    ];

    /** resolved object_type literal => finding type, for register_meta() */
    private const OBJECT_TYPES = [
        'post'    => 'post_meta',
        'user'    => 'user_meta',
        'term'    => 'term_meta',
        'comment' => 'comment_meta',
    ];

    protected function inspect(Node $node): void
    {
        if (!$node instanceof FuncCall || !$node->name instanceof Name || $node->isFirstClassCallable()) {
            return;
        }

        $fn = strtolower($node->name->toString());
        $args = $node->getArgs();

        if (isset(self::FUNCTIONS[$fn])) {
            $this->recordMeta($node, $fn, self::FUNCTIONS[$fn], $args, 1, 'meta_key');

            return;
        }

        if ($fn === 'register_meta') {
            $this->recordRegisterMeta($node, $fn, $args, 0, 1);

            return;
        }

        // add_metadata($meta_type, $object_id, $meta_key, ...) — the generic API
        // the typed helpers above delegate to. Its removal twin, delete_metadata,
        // was already credited as cleanup, so not detecting the create side left
        // the two halves out of step.
        if ($fn === 'add_metadata' || $fn === 'update_metadata') {
            $this->recordRegisterMeta($node, $fn, $args, 0, 2);
        }
    }

    /**
     * @param list<\PhpParser\Node\Arg> $args
     */
    private function recordMeta(Node $node, string $fn, string $type, array $args, int $keyIndex, string $keyParam): void
    {
        $keyValue = $this->argValue($args, $keyIndex, $keyParam);
        $resolution = $keyValue !== null ? $this->resolveFindingKey($keyValue, $node) : Resolution::dynamic();

        $this->findings[] = new Finding(
            type: $type,
            function: $fn,
            key: $resolution->key(),
            confidence: $resolution->confidence,
            file: $this->file,
            line: $node->getStartLine(),
            expression: $resolution->raw,
        );
    }

    /**
     * @param list<\PhpParser\Node\Arg> $args
     */
    private function recordRegisterMeta(Node $node, string $fn, array $args, int $typeIndex, int $keyIndex): void
    {
        $objectTypeValue = $this->argValue($args, $typeIndex, $typeIndex === 0 && $keyIndex === 1 ? 'object_type' : 'meta_type');
        if ($objectTypeValue === null) {
            return;
        }

        $objectType = $this->resolveKey($objectTypeValue);
        if (!$objectType->isResolved() || $objectType->value === null || !isset(self::OBJECT_TYPES[$objectType->value])) {
            return; // unknowable object type — never guess which meta table this touches
        }

        $this->recordMeta($node, $fn, self::OBJECT_TYPES[$objectType->value], $args, $keyIndex, 'meta_key');
    }
}
