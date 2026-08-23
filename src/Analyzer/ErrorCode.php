<?php

declare(strict_types=1);

namespace Sediment\Analyzer;

/**
 * The stable error-code vocabulary for a scan's `errors` entries (§14).
 *
 * A degraded file is recorded with a machine-readable code so downstream
 * tooling — the batch report, the Index QA gate, future CI consumers — can
 * branch on *why* without parsing prose. Codes are frozen like everything
 * else in the public contract: existing values never change meaning, new
 * values may be added.
 *
 * E_PARSE    — the file exists but PHP could not parse it.
 * E_IO       — the file could not be read at all (permissions, race).
 * E_SIZE     — the file exceeds Scanner::MAX_FILE_BYTES and was skipped.
 * E_INTERNAL — a detector or collector hit an unexpected node shape; the
 *              scan's never-fatal guarantee turned it into an entry instead
 *              of an exception.
 */
final class ErrorCode
{
    public const PARSE = 'E_PARSE';
    public const IO = 'E_IO';
    public const SIZE = 'E_SIZE';
    public const INTERNAL = 'E_INTERNAL';
}
