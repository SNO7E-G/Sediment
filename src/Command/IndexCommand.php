<?php

declare(strict_types=1);

namespace Sediment\Command;

use Sediment\Analyzer\Finding;
use Sediment\Analyzer\WordPressCore;
use Sediment\Manifest\Manifest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `sediment index <manifests-dir>` — build the Index artifacts from a
 * directory of manifests: the reverse lookup from artifact key to the plugins
 * that create it, aggregate statistics, and a QA report.
 *
 * The reverse lookup is the artifact the whole project points at: it is what
 * turns a stray `smk_last_sync_ts` row into "that belongs to a plugin you
 * removed in 2019", with source lines behind it. The QA report enforces the
 * one promise the dataset must never break — no WordPress core artifact is
 * ever attributed to a plugin as removable — and the command fails when a
 * manifest violates it, so a bad dataset cannot be built silently.
 */
#[AsCommand(
    name: 'index',
    description: 'Build Index artifacts from a directory of manifests: reverse lookup, stats, QA report.',
)]
final class IndexCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('manifests', InputArgument::REQUIRED, 'Directory of manifest JSON files (as written by batch)');
        $this->addOption('out', 'o', InputOption::VALUE_REQUIRED, 'Where to write the Index artifacts', 'sediment-index');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $directory = rtrim((string) $input->getArgument('manifests'), "/\\");
        $out = rtrim((string) $input->getOption('out'), "/\\");

        $files = (array) glob($directory . '/*.json');
        if ($files === []) {
            $io->error("No manifests found in {$directory}");

            return Command::FAILURE;
        }

        if (!is_dir($out) && !@mkdir($out, 0777, true) && !is_dir($out)) {
            $io->error("Could not create output directory: {$out}");

            return Command::FAILURE;
        }

        $groupToType = array_flip(Manifest::TYPE_KEYS);

        /** @var array<string, array<string, true>> $lookup "type:key" => set of slugs */
        $lookup = [];
        $grades = [];
        $coverage = ['write_calls' => 0, 'resolved' => 0];
        $artifacts = ['total' => 0, 'cleaned' => 0, 'left_by_type' => []];
        $cleanup = ['with_uninstall_path' => 0, 'conditional' => 0];
        $qa = [
            'core_artifacts_in_creates' => [],
            'unexpected_schema_version' => [],
            'unreadable' => [],
            'zero_files_scanned' => [],
        ];

        foreach ($files as $file) {
            $manifest = json_decode((string) file_get_contents((string) $file), true);
            if (!is_array($manifest) || !isset($manifest['creates'], $manifest['coverage'], $manifest['grade'])) {
                // Every manifest the analyzer writes has all three; one missing
                // any of them is truncated or hand-edited, not merely sparse.
                $qa['unreadable'][] = basename((string) $file);
                continue;
            }

            $slug = $manifest['plugin']['slug'] ?? basename((string) $file, '.json');

            // The Index is built against the frozen schema; a manifest from a
            // different major belongs to a different dataset.
            $schemaVersion = (string) ($manifest['schema_version'] ?? '');
            if (!str_starts_with($schemaVersion, '2.')) {
                $qa['unexpected_schema_version'][] = sprintf('%s: %s', $slug, $schemaVersion !== '' ? $schemaVersion : 'missing');
            }

            if (($manifest['coverage']['files_scanned'] ?? 0) === 0) {
                $qa['zero_files_scanned'][] = $slug;
            }

            $grades[$manifest['grade']] = ($grades[$manifest['grade']] ?? 0) + 1;
            $coverage['write_calls'] += $manifest['coverage']['write_calls_found'] ?? 0;
            $coverage['resolved'] += ($manifest['coverage']['verified'] ?? 0) + ($manifest['coverage']['resolved'] ?? 0);

            if (($manifest['cleanup']['has_uninstall_php'] ?? false) || ($manifest['cleanup']['has_uninstall_hook'] ?? false)) {
                $cleanup['with_uninstall_path']++;
            }
            if ($manifest['cleanup']['conditional'] ?? false) {
                $cleanup['conditional']++;
            }

            foreach ($manifest['creates'] as $group => $items) {
                $type = $groupToType[$group] ?? null;
                if ($type === null) {
                    continue;
                }

                foreach ((array) $items as $item) {
                    $artifacts['total']++;
                    if ($item['cleaned'] ?? false) {
                        $artifacts['cleaned']++;
                    } else {
                        $artifacts['left_by_type'][$type] = ($artifacts['left_by_type'][$type] ?? 0) + 1;
                    }

                    $id = $type . ':' . $item['key'];
                    $lookup[$id][$slug] = true;

                    // The one promise the dataset must never break, re-checked
                    // here rather than trusted: no core artifact is attributed
                    // to a plugin as something removable.
                    if (WordPressCore::isCore(new Finding(
                        type: $type,
                        function: 'index',
                        key: (string) $item['key'],
                        confidence: (string) ($item['confidence'] ?? Finding::CONFIDENCE_VERIFIED),
                        file: 'index',
                        line: 1,
                    ))) {
                        $qa['core_artifacts_in_creates'][] = sprintf('%s: %s', $slug, $id);
                    }
                }
            }
        }

        ksort($lookup);
        foreach ($lookup as $id => $slugs) {
            ksort($slugs);
            $lookup[$id] = array_keys($slugs);
        }

        ksort($grades);
        ksort($artifacts['left_by_type']);

        $stats = [
            'plugins' => count($files),
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'analyzer_version' => \Sediment\Application::VERSION,
            'grades' => $grades,
            'coverage' => [
                'write_calls' => $coverage['write_calls'],
                'resolved' => $coverage['resolved'],
                'resolution_rate' => $coverage['write_calls'] > 0
                    ? round($coverage['resolved'] / $coverage['write_calls'], 3)
                    : 1.0,
            ],
            'artifacts' => [
                'total' => $artifacts['total'],
                'cleaned' => $artifacts['cleaned'],
                'left_behind' => $artifacts['total'] - $artifacts['cleaned'],
                'left_by_type' => $artifacts['left_by_type'],
            ],
            'cleanup' => $cleanup,
        ];

        // The lookup is for machines and can run to tens of megabytes, so it is
        // compact; stats and QA are for people first.
        file_put_contents($out . '/reverse-lookup.json', (string) json_encode($lookup, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        file_put_contents($out . '/stats.json', Manifest::toJson($stats));
        file_put_contents($out . '/qa.json', Manifest::toJson($qa));

        $io->title('Sediment index');
        $io->writeln(sprintf(' Indexed <info>%d</info> manifest(s): %d distinct artifacts into <comment>%s/</comment>.', count($files), count($lookup), OutputFormatter::escape($out)));

        // Every QA category is a build-stopper. A manifest with zero files
        // scanned is a pipeline mistake wearing an A grade — a WordPress
        // plugin without PHP does not exist — and letting it through would
        // inflate the published share of "clean" plugins with entries that
        // were never actually analyzed.
        $violations = count($qa['core_artifacts_in_creates'])
            + count($qa['unexpected_schema_version'])
            + count($qa['unreadable'])
            + count($qa['zero_files_scanned']);

        if ($violations > 0) {
            $io->error(sprintf('%d QA violation(s) — see qa.json. This dataset must not be published.', $violations));

            return Command::FAILURE;
        }

        $io->success('QA passed: zero core artifacts attributed, every manifest on the frozen schema, every plugin actually scanned.');

        return Command::SUCCESS;
    }
}
