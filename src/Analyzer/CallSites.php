<?php

declare(strict_types=1);

namespace Sediment\Analyzer;

/**
 * What literal values each parameter of each user-defined function actually
 * receives, harvested in pass 1.
 *
 * This is the answer to the corpus's clearest finding: large plugins funnel
 * their writes through a settings layer, so the call Sediment sees is
 * `update_option($key, ...)` inside `Options_Helper::set()`, and the key it
 * needs is at the *call sites* of that helper. Yoast SEO resolves at 62% and
 * Contact Form 7 at 50% for exactly this reason.
 *
 * Deliberately one hop. A wrapper around a wrapper is not followed: the win
 * drops off sharply while the chance of attributing a key to the wrong plugin
 * artifact climbs, and a wrong key in a tool that generates deletion code is
 * the one error that actually costs somebody their data.
 */
final class CallSites
{
    /**
     * A parameter seen with more than this many distinct literals is treated as
     * opaque. Nothing legitimate keys hundreds of artifacts through one wrapper
     * parameter, and the cap stops a pathological plugin from turning one call
     * into thousands of findings.
     */
    private const MAX_LITERALS = 100;

    /** @var array<string, array<int, string>> callee => parameter index => name */
    private array $parameters = [];

    /** @var array<string, array<int, array<string, true>>> callee => index => distinct literals */
    private array $literals = [];

    /** @var array<string, array<int, true>> callee => index => a call passed something unreadable */
    private array $opaque = [];

    /** @var array<string, true> callees referenced as callables, so calls exist that are not in the source */
    private array $externallyCallable = [];

    /**
     * Register a function's parameter names so a write keyed on `$key` can be
     * matched to the argument position callers fill.
     *
     * @param list<string> $names in declaration order
     */
    public function declareFunction(string $callee, array $names): void
    {
        $id = self::normalise($callee);

        foreach ($names as $index => $name) {
            $this->parameters[$id][$index] = $name;
        }
    }

    /**
     * Record one argument at one call site. A null literal means the argument
     * was not a readable literal, which is remembered rather than ignored: it is
     * the difference between "this parameter takes these three keys" and "this
     * parameter takes these three keys and something we cannot see".
     */
    public function recordCall(string $callee, int $index, ?string $literal): void
    {
        $id = self::normalise($callee);

        if ($literal === null) {
            $this->opaque[$id][$index] = true;

            return;
        }

        if (count($this->literals[$id][$index] ?? []) >= self::MAX_LITERALS) {
            $this->opaque[$id][$index] = true;

            return;
        }

        $this->literals[$id][$index][$literal] = true;
    }

    /**
     * Record that a function was referenced as a callable — passed to a hook,
     * written as `[$this, 'method']`, or made into a first-class callable. Its
     * visible call sites are then not the whole story: something else holds a
     * handle to it and can call it with arguments the source never shows.
     */
    public function markExternallyCallable(string $callee): void
    {
        $this->externallyCallable[self::normalise($callee)] = true;
    }

    /**
     * The literals a named parameter receives across every call site.
     *
     * `complete` is false when at least one call passed something unreadable —
     * or when the function was referenced as a callable, so calls exist that
     * the source does not show. Either way the caller keeps reporting the
     * unresolved write alongside the keys it did find instead of claiming full
     * coverage.
     *
     * @return array{literals: list<string>, complete: bool}|null null when nothing is known
     */
    public function forParameter(string $callee, string $parameter): ?array
    {
        $id = self::normalise($callee);

        $index = array_search($parameter, $this->parameters[$id] ?? [], true);
        if ($index === false) {
            return null;
        }

        $literals = array_keys($this->literals[$id][$index] ?? []);
        if ($literals === []) {
            return null;
        }

        sort($literals);

        return [
            'literals' => $literals,
            'complete' => !isset($this->opaque[$id][$index]) && !isset($this->externallyCallable[$id]),
        ];
    }

    /** Function and method names are case-insensitive in PHP; keys follow suit.
     *  A leading backslash is stripped so a '\Ns\func' callback string meets its
     *  'Ns\func' declaration. */
    private static function normalise(string $callee): string
    {
        return strtolower(ltrim($callee, '\\'));
    }
}
