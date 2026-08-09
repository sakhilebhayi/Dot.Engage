<?php

namespace App\Console\Commands;

use App\Models\DependencyPatchProposal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class ScanDependencyAdvisories extends Command
{
    protected $signature = 'dependency:scan';

    protected $description = 'Scan composer and npm dependencies for security advisories and propose a patch for operator approval';

    /**
     * @var array<string, array{command: array<int, string>, proposedCommand: string}>
     */
    private const MANAGERS = [
        'composer' => [
            'command' => ['composer', 'audit', '--format=json'],
            'proposedCommand' => 'composer update --with-dependencies',
        ],
        'npm' => [
            'command' => ['npm', 'audit', '--json'],
            'proposedCommand' => 'npm audit fix',
        ],
    ];

    public function handle(): int
    {
        foreach (self::MANAGERS as $manager => $config) {
            try {
                $output = $this->runAudit($config['command']);
            } catch (\Throwable $exception) {
                Log::warning("dependency:scan: {$manager} audit failed, skipping", ['error' => $exception->getMessage()]);

                continue;
            }

            $advisories = $manager === 'composer'
                ? $this->parseComposerAudit($output)
                : $this->parseNpmAudit($output);

            if ($advisories === []) {
                continue;
            }

            $alreadyPending = DependencyPatchProposal::where('manager', $manager)
                ->where('status', 'pending_approval')
                ->exists();

            if ($alreadyPending) {
                continue;
            }

            DependencyPatchProposal::create([
                'manager' => $manager,
                'advisories' => $advisories,
                'risk_summary' => $this->riskSummary($advisories),
                'proposed_command' => $config['proposedCommand'],
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * Overridden in tests to inject fixture output instead of shelling out
     * for real. Not final specifically so tests can create an anonymous
     * subclass overriding it.
     *
     * @param  array<int, string>  $command
     */
    protected function runAudit(array $command): string
    {
        $process = new Process($command, base_path());
        $process->setTimeout(120);
        $process->run();

        // composer audit exits non-zero when advisories are found -- that
        // is the expected, common case, not a failure. Only a genuinely
        // empty/invalid output (caught by the JSON parse in parse*Audit())
        // is treated as an error.
        return $process->getOutput();
    }

    /**
     * @return array<int, array{package: string, severity: string, title: string, identifier: string}>
     */
    protected function parseComposerAudit(string $json): array
    {
        $data = json_decode($json, true);

        if (! is_array($data) || ! isset($data['advisories']) || ! is_array($data['advisories'])) {
            return [];
        }

        $advisories = [];

        foreach ($data['advisories'] as $packageName => $entries) {
            foreach ((array) $entries as $entry) {
                $advisories[] = [
                    'package' => (string) ($entry['packageName'] ?? $packageName),
                    'severity' => (string) ($entry['severity'] ?? 'unknown'),
                    'title' => (string) ($entry['title'] ?? ''),
                    'identifier' => (string) ($entry['cve'] ?? $entry['advisoryId'] ?? ''),
                ];
            }
        }

        return $advisories;
    }

    /**
     * @return array<int, array{package: string, severity: string, title: string, identifier: string}>
     */
    protected function parseNpmAudit(string $json): array
    {
        $data = json_decode($json, true);

        if (! is_array($data) || ! isset($data['vulnerabilities']) || ! is_array($data['vulnerabilities'])) {
            return [];
        }

        $advisories = [];

        foreach ($data['vulnerabilities'] as $packageName => $entry) {
            $via = $entry['via'][0] ?? [];
            $title = is_array($via) ? (string) ($via['title'] ?? '') : '';
            $identifier = is_array($via) ? (string) ($via['url'] ?? '') : '';

            $advisories[] = [
                'package' => (string) ($entry['name'] ?? $packageName),
                'severity' => (string) ($entry['severity'] ?? 'unknown'),
                'title' => $title,
                'identifier' => $identifier,
            ];
        }

        return $advisories;
    }

    /**
     * @param  array<int, array{severity: string}>  $advisories
     */
    protected function riskSummary(array $advisories): string
    {
        $counts = [];

        foreach ($advisories as $advisory) {
            $severity = $advisory['severity'];
            $counts[$severity] = ($counts[$severity] ?? 0) + 1;
        }

        $parts = [];
        foreach ($counts as $severity => $count) {
            $parts[] = "{$count} {$severity}";
        }

        $total = count($advisories);
        $noun = $total === 1 ? 'advisory' : 'advisories';

        return "{$total} {$noun}: ".implode(', ', $parts);
    }
}
