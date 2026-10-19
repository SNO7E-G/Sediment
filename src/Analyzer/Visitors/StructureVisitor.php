<?php

declare(strict_types=1);

namespace Sediment\Analyzer\Visitors;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use Sediment\Analyzer\Finding;
use Sediment\Analyzer\Resolution;

/**
 * Detects structural registrations (M4/M7): roles, capabilities, post types,
 * and taxonomies.
 *
 *  - add_role($role, $display_name, $capabilities) registers the role itself
 *    (arg 0), plus — when the capabilities array is a literal array — one
 *    'capability' finding per literal-string key. Non-literal keys are
 *    skipped rather than guessed.
 *  - register_post_type / register_taxonomy record the type/taxonomy name.
 *  - `$role->add_cap('my_cap')` grants a capability at runtime. Only the
 *    capability name is recorded; attributing which role received it would
 *    require tracking $role back to a get_role()/add_role() call, which is
 *    out of scope here.
 */
final class StructureVisitor extends AbstractDetectionVisitor
{
    protected function inspect(Node $node): void
    {
        if ($node instanceof FuncCall && $node->name instanceof Name) {
            $fn = strtolower($node->name->toString());

            // The structural registrations name their artifact type in the call
            // itself, so even a first-class callable can be recorded honestly.
            $type = match ($fn) {
                'add_role'           => 'role',
                'register_post_type' => 'post_type',
                'register_taxonomy'  => 'taxonomy',
                default              => null,
            };

            if ($type !== null && $this->recordFirstClassCallable($node, $type, $fn)) {
                return;
            }

            $this->inspectFuncCall($node);

            return;
        }

        if ($node instanceof MethodCall && $node->name instanceof Identifier) {
            if (strtolower((string) $node->name) === 'add_cap' && $this->recordFirstClassCallable($node, 'capability', 'add_cap')) {
                return;
            }

            $this->inspectMethodCall($node);
        }
    }

    private function inspectFuncCall(FuncCall $node): void
    {
        $fn = strtolower($node->name->toString());
        $args = $this->args($node);

        if ($fn === 'add_role') {
            $this->record($node, $fn, 'role', $args, 0, 'role');
            $this->recordCapabilities($node, $fn, $args);

            return;
        }

        if ($fn === 'register_post_type') {
            $this->record($node, $fn, 'post_type', $args, 0, 'post_type');

            return;
        }

        if ($fn === 'register_taxonomy') {
            $this->record($node, $fn, 'taxonomy', $args, 0, 'taxonomy');
        }
    }

    private function inspectMethodCall(MethodCall $node): void
    {
        if (strtolower((string) $node->name) !== 'add_cap') {
            return;
        }

        // WP_Role::add_cap($cap) names the capability first; WP_Roles::add_cap($role,
        // $cap) — wp_roles(), $wp_roles — names the role first. Reading the
        // wrong one reports `administrator` as a capability the plugin owns.
        $args = $this->args($node);
        $rolesObject = ($node->var instanceof FuncCall && $node->var->name instanceof Name && strtolower($node->var->name->toString()) === 'wp_roles')
            || ($node->var instanceof Variable && $node->var->name === 'wp_roles')
            || ($node->var instanceof ArrayDimFetch && $node->var->dim instanceof String_ && $node->var->dim->value === 'wp_roles')
            || $this->argValue($args, 1, 'cap') instanceof String_;

        $this->record($node, 'add_cap', 'capability', $args, $rolesObject ? 1 : 0, 'cap');
    }

    /**
     * @param list<\PhpParser\Node\Arg> $args
     */
    private function recordCapabilities(FuncCall $node, string $fn, array $args): void
    {
        $capsValue = $this->argValue($args, 2, 'capabilities');
        if (!$capsValue instanceof Array_) {
            return;
        }

        foreach ($capsValue->items as $item) {
            if ($item === null || !$item->key instanceof String_) {
                continue; // non-literal capability key — never guess
            }

            $this->findings[] = new Finding(
                type: 'capability',
                function: $fn,
                key: $item->key->value,
                confidence: Finding::CONFIDENCE_VERIFIED,
                file: $this->file,
                line: $node->getStartLine(),
            );
        }
    }

    /**
     * @param list<\PhpParser\Node\Arg> $args
     */
    private function record(Node $node, string $fn, string $type, array $args, int $keyIndex, string $keyParam): void
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
}
