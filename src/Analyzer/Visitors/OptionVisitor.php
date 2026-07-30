<?php

declare(strict_types=1);

namespace Sediment\Analyzer\Visitors;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use Sediment\Analyzer\Finding;
use Sediment\Analyzer\Resolution;

/**
 * Detects option writes (M3): add_option, update_option, and their site
 * variants — capturing the key, its confidence, and the autoload flag.
 *
 * Autoload matters because an orphaned *autoloaded* option loads on every
 * request and is the whole basis of grade D, so it is captured explicitly:
 *  - add_option($key, $value, $deprecated, $autoload) — 4th arg, defaults to yes
 *  - update_option($key, $value, $autoload)           — 3rd arg, defaults to unknown
 *  - add_site_option / update_site_option             — network scope, not autoloaded
 *
 * register_setting is intentionally excluded: it registers a setting with the
 * Settings API but does not itself write an option row, so counting it would
 * manufacture false positives.
 */
final class OptionVisitor extends AbstractDetectionVisitor
{
    /**
     * function => autoload arg index (key is always arg 0 / 'option').
     *
     * The network variants take the network id first, so their key sits at a
     * different position and is handled separately below.
     */
    private const FUNCTIONS = [
        'add_option'         => 3,
        'update_option'      => 2,
        'add_site_option'    => null,
        'update_site_option' => null,
    ];

    /**
     * add_network_option($network_id, $option, $value) and its update twin — the
     * modern multisite API that add_site_option now delegates to. Network options
     * live in the sitemeta table and are never autoloaded.
     */
    private const NETWORK_FUNCTIONS = ['add_network_option', 'update_network_option'];

    private const AUTOLOAD_YES = 'yes';
    private const AUTOLOAD_NO = 'no';
    private const AUTOLOAD_UNKNOWN = 'unknown';

    protected function inspect(Node $node): void
    {
        if (!$node instanceof FuncCall || !$node->name instanceof Name) {
            return;
        }

        $function = strtolower($node->name->toString());
        if ($node->isFirstClassCallable()) {
            return;
        }

        $isNetwork = in_array($function, self::NETWORK_FUNCTIONS, true);
        if (!$isNetwork && !array_key_exists($function, self::FUNCTIONS)) {
            return;
        }

        $args = $node->getArgs();
        $keyValue = $this->argValue($args, $isNetwork ? 1 : 0, 'option');
        $resolution = $keyValue !== null ? $this->resolveKey($keyValue) : Resolution::dynamic();

        if ($isNetwork) {
            $this->findings[] = new Finding(
                type: 'option',
                function: $function,
                key: $resolution->key(),
                confidence: $resolution->confidence,
                file: $this->file,
                line: $node->getStartLine(),
                autoload: null, // network options are never autoloaded
                expression: $resolution->raw,
            );

            return;
        }

        $this->findings[] = new Finding(
            type: 'option',
            function: $function,
            key: $resolution->key(),
            confidence: $resolution->confidence,
            file: $this->file,
            line: $node->getStartLine(),
            autoload: $this->autoloadFor($function, self::FUNCTIONS[$function], $args),
            expression: $resolution->raw,
        );
    }

    /**
     * @param list<\PhpParser\Node\Arg> $args
     */
    private function autoloadFor(string $function, ?int $autoloadIndex, array $args): ?string
    {
        if ($autoloadIndex === null) {
            return null; // site/network options are not autoloaded
        }

        $value = $this->argValue($args, $autoloadIndex, 'autoload');

        if ($value === null) {
            // add_option defaults autoload to 'yes'; update_option leaves it unchanged.
            return $function === 'add_option' ? self::AUTOLOAD_YES : self::AUTOLOAD_UNKNOWN;
        }

        return $this->readAutoloadValue($value);
    }

    private function readAutoloadValue(Expr $value): string
    {
        if ($value instanceof ConstFetch) {
            return match (strtolower($value->name->toString())) {
                'true'  => self::AUTOLOAD_YES,
                'false' => self::AUTOLOAD_NO,
                default => self::AUTOLOAD_UNKNOWN,
            };
        }

        if ($value instanceof String_) {
            return match (strtolower($value->value)) {
                'no', 'off', 'false', 'auto-off' => self::AUTOLOAD_NO,
                'yes', 'on', 'true', 'auto', 'auto-on' => self::AUTOLOAD_YES,
                default => self::AUTOLOAD_UNKNOWN,
            };
        }

        return self::AUTOLOAD_UNKNOWN;
    }
}
