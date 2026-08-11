<?php

declare(strict_types=1);

namespace Sediment\Tests\Unit;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Sediment\Analyzer\Scanner;
use Sediment\Manifest\Grader;
use Sediment\Manifest\Manifest;

/**
 * Holds every manifest Sediment produces to the published contract in
 * schema/manifest.schema.json.
 *
 * The schema is the API (0.8, "Contract"): a consumer builds against the file,
 * not against the analyzer. This test is what keeps the file true — an output
 * change that misses the schema, or a schema change that misses the output,
 * fails here before it ships.
 */
final class ManifestSchemaTest extends TestCase
{
    public function test_manifests_built_from_fixtures_validate_against_the_published_schema(): void
    {
        foreach (['clean-plugin', 'partial-plugin', 'messy-plugin'] as $fixture) {
            $path = dirname(__DIR__) . '/fixtures/' . $fixture;
            if (!is_dir($path)) {
                continue;
            }

            $scan = (new Scanner())->scan($path);
            $grade = (new Grader())->grade($scan['findings'], $scan['cleanup']);
            $manifest = Manifest::build($scan, $grade, $path, gmdate('Y-m-d\TH:i:s\Z'));

            // Round-tripped through the real encoder so the types validated are
            // the types a consumer will actually decode.
            $this->assertValid(json_decode(Manifest::toJson($manifest)), $fixture);
        }
    }

    public function test_every_committed_golden_manifest_validates_against_the_published_schema(): void
    {
        $goldens = (array) glob(dirname(__DIR__) . '/Golden/manifests/*.json');
        self::assertNotSame([], $goldens, 'no golden manifests found to validate');

        foreach ($goldens as $file) {
            $manifest = json_decode((string) file_get_contents((string) $file));
            self::assertIsObject($manifest);

            // Golden expectations are stored normalised: the two fields that
            // legitimately differ between runs are stripped. Restore stand-ins
            // so the rest of the document is held to the full contract.
            $manifest->plugin->scanned_at = '1970-01-01T00:00:00Z';
            $manifest->plugin->analyzer_version = '0.0.0';

            $this->assertValid($manifest, basename((string) $file));
        }
    }

    private function assertValid(mixed $manifest, string $label): void
    {
        $schema = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/schema/manifest.schema.json'));
        self::assertIsObject($schema, 'schema/manifest.schema.json is not valid JSON');

        $result = (new Validator())->validate($manifest, $schema);

        self::assertTrue(
            $result->isValid(),
            sprintf(
                "%s does not match schema/manifest.schema.json:\n%s",
                $label,
                json_encode(
                    $result->hasError() ? (new ErrorFormatter())->format($result->error()) : [],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                ),
            ),
        );
    }
}
