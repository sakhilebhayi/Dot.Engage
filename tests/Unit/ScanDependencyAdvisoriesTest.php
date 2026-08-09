<?php

namespace Tests\Unit;

use App\Console\Commands\ScanDependencyAdvisories;
use App\Models\DependencyPatchProposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScanDependencyAdvisoriesTest extends TestCase
{
    use RefreshDatabase;

    public const COMPOSER_AUDIT_WITH_ADVISORY = <<<'JSON'
{
    "advisories": {
        "league/commonmark": [
            {
                "advisoryId": "GHSA-abcd-1234",
                "packageName": "league/commonmark",
                "title": "DoS via crafted Markdown input",
                "cve": "CVE-2026-12345",
                "affectedVersions": "<2.4.1",
                "severity": "moderate"
            }
        ]
    },
    "abandoned": []
}
JSON;

    public const COMPOSER_AUDIT_EMPTY = <<<'JSON'
{"advisories": {}, "abandoned": []}
JSON;

    public const NPM_AUDIT_WITH_ADVISORY = <<<'JSON'
{
    "vulnerabilities": {
        "ws": {
            "name": "ws",
            "severity": "high",
            "via": [
                {"title": "Uninitialized memory disclosure", "url": "https://github.com/advisories/GHSA-wxyz", "severity": "high"}
            ],
            "range": "<8.17.1",
            "fixAvailable": true
        }
    },
    "metadata": {"vulnerabilities": {"total": 1}}
}
JSON;

    public const NPM_AUDIT_EMPTY = <<<'JSON'
{"vulnerabilities": {}, "metadata": {"vulnerabilities": {"total": 0}}}
JSON;

    public function test_creates_one_proposal_per_manager_when_advisories_found(): void
    {
        $command = new class extends ScanDependencyAdvisories
        {
            protected function runAudit(array $command): string
            {
                return str_contains($command[0], 'composer')
                    ? ScanDependencyAdvisoriesTest::COMPOSER_AUDIT_WITH_ADVISORY
                    : ScanDependencyAdvisoriesTest::NPM_AUDIT_WITH_ADVISORY;
            }
        };

        $command->handle();

        $this->assertSame(2, DependencyPatchProposal::count());
        $composerProposal = DependencyPatchProposal::where('manager', 'composer')->first();
        $this->assertSame('pending_approval', $composerProposal->status);
        $this->assertSame('composer update --with-dependencies', $composerProposal->proposed_command);
        $this->assertStringContainsString('1 moderate', $composerProposal->risk_summary);
        $this->assertSame('league/commonmark', $composerProposal->advisories[0]['package']);

        $npmProposal = DependencyPatchProposal::where('manager', 'npm')->first();
        $this->assertSame('npm audit fix', $npmProposal->proposed_command);
        $this->assertStringContainsString('1 high', $npmProposal->risk_summary);
        $this->assertSame('ws', $npmProposal->advisories[0]['package']);
    }

    public function test_creates_no_proposal_when_no_advisories_found(): void
    {
        $command = new class extends ScanDependencyAdvisories
        {
            protected function runAudit(array $command): string
            {
                return str_contains($command[0], 'composer')
                    ? ScanDependencyAdvisoriesTest::COMPOSER_AUDIT_EMPTY
                    : ScanDependencyAdvisoriesTest::NPM_AUDIT_EMPTY;
            }
        };

        $command->handle();

        $this->assertSame(0, DependencyPatchProposal::count());
    }

    public function test_does_not_duplicate_while_one_is_still_pending(): void
    {
        $command = new class extends ScanDependencyAdvisories
        {
            protected function runAudit(array $command): string
            {
                return str_contains($command[0], 'composer')
                    ? ScanDependencyAdvisoriesTest::COMPOSER_AUDIT_WITH_ADVISORY
                    : ScanDependencyAdvisoriesTest::NPM_AUDIT_EMPTY;
            }
        };

        $command->handle();
        $command->handle();

        $this->assertSame(1, DependencyPatchProposal::where('manager', 'composer')->count());
    }

    public function test_a_manager_that_errors_does_not_abort_the_other_manager(): void
    {
        $command = new class extends ScanDependencyAdvisories
        {
            protected function runAudit(array $command): string
            {
                if (str_contains($command[0], 'composer')) {
                    throw new \RuntimeException('composer binary not found');
                }

                return ScanDependencyAdvisoriesTest::NPM_AUDIT_WITH_ADVISORY;
            }
        };

        $command->handle();

        $this->assertSame(0, DependencyPatchProposal::where('manager', 'composer')->count());
        $this->assertSame(1, DependencyPatchProposal::where('manager', 'npm')->count());
    }
}
