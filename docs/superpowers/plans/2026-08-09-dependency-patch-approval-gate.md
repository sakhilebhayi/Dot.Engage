# Dependency-Patch Approval Gate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give Dot.Engage its first Level 2 autonomy process: a weekly `composer audit`/`npm
audit` scan that proposes a patch command per package manager, reviewed on an operator-gated
Livewire screen where Approve queues the real patch command and Reject requires a reason —
never applying a dependency update without an explicit human decision.

**Architecture:** Reuses Dot.Press's proven `DependencyPatchProposal` model, `ScanDependencyAdvisories`
command, and `ApplyDependencyPatchJob` nearly verbatim (read directly from
`~/Dot/Dot.Press` during planning). The review UI is new: a Livewire component
(`App\Livewire\Operator\DependencyPatchQueue`) instead of Press's Vue/Inertia page, matching
Dot.Engage's own Livewire-only frontend convention (`app/Livewire/Video/*`, `app/Livewire/Chat/*`,
`app/Livewire/Contracts/*`). Because a Livewire component's action methods (`wire:click` calls) go
through Livewire's own AJAX update endpoint and do **not** automatically inherit the page route's
middleware, the component authorizes itself internally (`mount()` and every action), on top of the
route-level `operator` middleware protecting the page's initial load — this repo's own
`CLAUDE.md` states the same requirement: "Validate and authorize in actions as you would in HTTP
requests."

**Tech Stack:** Laravel 11/12-style bootstrap (`bootstrap/app.php`), Livewire 3.6, PHPUnit,
SQLite in-memory for tests (`phpunit.xml`), Symfony Process for shelling out to `composer
audit`/`npm audit`.

## Global Constraints

- `is_platform_operator` is a `boolean` column on `users`, default `false`, **excluded from
  `$fillable`** (matches the identical pattern already used in ChartSense, Dot.Ehail, Dot.Emall,
  Dot.Files, Dot.Forms, Dot.Press, Dot.Sheet, Dot.Tutor).
- The scan never auto-applies a patch. `ApplyDependencyPatchJob` only ever runs after an
  operator's explicit Approve action.
- `dependency:scan` is scheduled `->weekly()->withoutOverlapping()` — every other scheduled
  command already in this repo's `routes/console.php` uses `->withoutOverlapping()`, and this is a
  deliberate, minor improvement over Dot.Press's original schedule line (which lacks it).
- `ApplyDependencyPatchJob::handle()` must always resolve the proposal to a terminal `applied` or
  `failed` status — never left stuck at `approved`, even if the process throws.
- The Livewire component authorizes both in `mount()` (blocks a non-operator's initial page load)
  and inside every action method (`approve()`, `reject()`) independently — the second check is
  real defense-in-depth, not redundant: `mount()` runs once per component instantiation, so a
  user whose `is_platform_operator` flag is revoked *after* the page has already loaded would
  otherwise still be able to fire a Livewire update request.
- No mail/notification channel is added by this plan — the review screen is the only surface.

---

## Task 1: `is_platform_operator` flag on `users`

**Files:**
- Create: `database/migrations/xxxx_xx_xx_xxxxxx_add_is_platform_operator_to_users_table.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/PlatformOperatorFlagTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks (this is the first task).
- Produces: `users.is_platform_operator` (boolean, default `false`), accessible as
  `$user->is_platform_operator` (cast to `bool`), never settable via mass assignment. Later tasks
  (3, 5) read this column directly and via `EnsurePlatformOperator` middleware.

- [ ] **Step 1: Generate the migration**

Run:
```bash
php artisan make:migration add_is_platform_operator_to_users_table --no-interaction
```

- [ ] **Step 2: Write the migration**

Open the generated file (it will be named
`database/migrations/<timestamp>_add_is_platform_operator_to_users_table.php`) and replace its
contents with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Deliberately NOT named "is_admin" -- a generic admin flag name is
            // an obvious mass-assignment target to probe for. Scoped naming
            // plus exclusion from $fillable (see User model) means no request
            // payload can ever set this, regardless of field name. Matches the
            // identical column already proven in ChartSense/Dot.Ehail/Dot.Emall/
            // Dot.Files/Dot.Forms/Dot.Press/Dot.Sheet/Dot.Tutor's approval-gate
            // work.
            $table->boolean('is_platform_operator')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_platform_operator');
        });
    }
};
```

- [ ] **Step 3: Write the failing test**

Create `tests/Feature/PlatformOperatorFlagTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformOperatorFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_platform_operator_defaults_to_false(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->fresh()->is_platform_operator);
    }

    public function test_is_platform_operator_cannot_be_set_via_mass_assignment(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'is_platform_operator' => true,
        ]);

        $this->assertFalse($user->fresh()->is_platform_operator);
    }

    public function test_is_platform_operator_casts_to_boolean(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['is_platform_operator' => true])->save();

        $this->assertIsBool($user->fresh()->is_platform_operator);
        $this->assertTrue($user->fresh()->is_platform_operator);
    }
}
```

- [ ] **Step 4: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/PlatformOperatorFlagTest.php`
Expected: FAIL — the `is_platform_operator` column/attribute doesn't exist yet.

- [ ] **Step 5: Add the cast to `User`**

Read `app/Models/User.php` first. Its `casts()` method currently reads:

```php
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
```

Replace with:

```php
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_operator' => 'boolean',
        ];
    }
```

Do **not** add `is_platform_operator` to `$fillable` — its absence there is what makes the
mass-assignment test in Step 3 pass.

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/PlatformOperatorFlagTest.php`
Expected: 3 passed.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations app/Models/User.php tests/Feature/PlatformOperatorFlagTest.php
git commit -m "feat(engage): add is_platform_operator flag to users"
```

---

## Task 2: `dependency_patch_proposals` table + `DependencyPatchProposal` model

**Files:**
- Create: `database/migrations/xxxx_xx_xx_xxxxxx_create_dependency_patch_proposals_table.php`
- Create: `app/Models/DependencyPatchProposal.php`
- Test: `tests/Unit/DependencyPatchProposalModelTest.php`

**Interfaces:**
- Consumes: `App\Models\User` (Task 1) for the `reviewer()` relation.
- Produces: `App\Models\DependencyPatchProposal` with fillable fields `manager`, `advisories`
  (array cast), `risk_summary`, `proposed_command`, `status` (defaults `'pending_approval'` at
  the DB level), `rejected_reason`, `reviewed_by`, `reviewed_at` (datetime cast), `applied_log`,
  `applied_at` (datetime cast), and a `reviewer(): BelongsTo` relation to `User`. Tasks 3, 4, and
  5 all create/read/update this model.

- [ ] **Step 1: Generate the migration**

Run:
```bash
php artisan make:migration create_dependency_patch_proposals_table --create=dependency_patch_proposals --no-interaction
```

- [ ] **Step 2: Write the migration**

Open the generated file and replace its contents with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dependency_patch_proposals', function (Blueprint $table) {
            $table->id();
            $table->string('manager');
            $table->json('advisories');
            $table->text('risk_summary');
            $table->string('proposed_command');
            $table->string('status')->default('pending_approval');
            $table->text('rejected_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('applied_log')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dependency_patch_proposals');
    }
};
```

- [ ] **Step 3: Write the failing test**

Create `tests/Unit/DependencyPatchProposalModelTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\DependencyPatchProposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DependencyPatchProposalModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_advisories_cast_to_array_and_status_defaults_to_pending(): void
    {
        $reviewer = User::factory()->create();

        $proposal = DependencyPatchProposal::create([
            'manager' => 'composer',
            'advisories' => [
                ['package' => 'league/commonmark', 'severity' => 'moderate', 'title' => 'DoS via crafted input', 'identifier' => 'GHSA-abcd'],
            ],
            'risk_summary' => '1 advisory: 1 moderate',
            'proposed_command' => 'composer update --with-dependencies',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        $fresh = $proposal->fresh();
        $this->assertSame('pending_approval', $fresh->status);
        $this->assertIsArray($fresh->advisories);
        $this->assertSame('league/commonmark', $fresh->advisories[0]['package']);
        $this->assertTrue($fresh->reviewer->is($reviewer));
    }
}
```

- [ ] **Step 4: Run the test to verify it fails**

Run: `php artisan test --compact tests/Unit/DependencyPatchProposalModelTest.php`
Expected: FAIL — `App\Models\DependencyPatchProposal` doesn't exist yet.

- [ ] **Step 5: Create the model**

Create `app/Models/DependencyPatchProposal.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DependencyPatchProposal extends Model
{
    protected $fillable = [
        'manager', 'advisories', 'risk_summary', 'proposed_command', 'status',
        'rejected_reason', 'reviewed_by', 'reviewed_at', 'applied_log', 'applied_at',
    ];

    protected $casts = [
        'advisories' => 'array',
        'reviewed_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --compact tests/Unit/DependencyPatchProposalModelTest.php`
Expected: 1 passed.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations app/Models/DependencyPatchProposal.php tests/Unit/DependencyPatchProposalModelTest.php
git commit -m "feat(engage): add DependencyPatchProposal model + migration"
```

---

## Task 3: `dependency:scan` command

**Files:**
- Create: `app/Console/Commands/ScanDependencyAdvisories.php`
- Modify: `routes/console.php`
- Test: `tests/Unit/ScanDependencyAdvisoriesTest.php`

**Interfaces:**
- Consumes: `App\Models\DependencyPatchProposal::create()` (Task 2).
- Produces: an `artisan dependency:scan` command, and `protected function runAudit(array
  $command): string` — overridden by an anonymous subclass in tests to inject fixture output
  instead of shelling out for real (Task 5 does not depend on this, but any future test touching
  this command must follow the same override pattern).

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/ScanDependencyAdvisoriesTest.php`:

```php
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
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Unit/ScanDependencyAdvisoriesTest.php`
Expected: FAIL — `App\Console\Commands\ScanDependencyAdvisories` doesn't exist yet.

- [ ] **Step 3: Generate and write the command**

Run:
```bash
php artisan make:command ScanDependencyAdvisories --no-interaction
```

Replace the generated `app/Console/Commands/ScanDependencyAdvisories.php` with:

```php
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
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Unit/ScanDependencyAdvisoriesTest.php`
Expected: 4 passed.

- [ ] **Step 5: Schedule the command**

Read `routes/console.php` first. Add this import alongside the existing ones at the top:

```php
use App\Console\Commands\ScanDependencyAdvisories;
```

Add this block at the end of the file, after the `dotengage:team-activity-report` schedule entry,
under a new comment:

```php

// Scan composer/npm dependencies for security advisories weekly and propose a patch per
// manager for operator approval -- never applies automatically. See docs/superpowers/specs/
// 2026-08-09-dependency-patch-approval-gate-design.md.
Schedule::command(ScanDependencyAdvisories::class)
    ->weekly()
    ->withoutOverlapping();
```

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Console/Commands/ScanDependencyAdvisories.php routes/console.php tests/Unit/ScanDependencyAdvisoriesTest.php
git commit -m "feat(engage): add dependency:scan command + weekly schedule"
```

---

## Task 4: `ApplyDependencyPatchJob`

**Files:**
- Create: `app/Jobs/ApplyDependencyPatchJob.php`
- Test: `tests/Unit/ApplyDependencyPatchJobTest.php`

**Interfaces:**
- Consumes: `App\Models\DependencyPatchProposal` (Task 2), specifically its `proposed_command`
  and `update()`.
- Produces: `App\Jobs\ApplyDependencyPatchJob`, constructed as `new
  ApplyDependencyPatchJob(DependencyPatchProposal $proposal)`, implementing `ShouldQueue`. Task 5
  dispatches this via `ApplyDependencyPatchJob::dispatch($proposal)`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/ApplyDependencyPatchJobTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Jobs\ApplyDependencyPatchJob;
use App\Models\DependencyPatchProposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplyDependencyPatchJobTest extends TestCase
{
    use RefreshDatabase;

    private function proposal(string $command): DependencyPatchProposal
    {
        return DependencyPatchProposal::create([
            'manager' => 'composer',
            'advisories' => [['package' => 'league/commonmark', 'severity' => 'moderate', 'title' => 'x', 'identifier' => 'y']],
            'risk_summary' => '1 advisory: 1 moderate',
            'proposed_command' => $command,
            'status' => 'approved',
        ]);
    }

    public function test_a_successful_command_marks_the_proposal_applied(): void
    {
        $proposal = $this->proposal('echo patch-output-ok');

        (new ApplyDependencyPatchJob($proposal))->handle();

        $fresh = $proposal->fresh();
        $this->assertSame('applied', $fresh->status);
        $this->assertStringContainsString('patch-output-ok', $fresh->applied_log);
        $this->assertNotNull($fresh->applied_at);
    }

    public function test_a_failing_command_marks_the_proposal_failed(): void
    {
        // "false" is a real shell command that always exits 1 -- never a
        // real package-manager mutation.
        $proposal = $this->proposal('false');

        (new ApplyDependencyPatchJob($proposal))->handle();

        $fresh = $proposal->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertNotNull($fresh->applied_at);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Unit/ApplyDependencyPatchJobTest.php`
Expected: FAIL — `App\Jobs\ApplyDependencyPatchJob` doesn't exist yet.

- [ ] **Step 3: Generate and write the job**

Run:
```bash
php artisan make:job ApplyDependencyPatchJob --no-interaction
```

Replace the generated `app/Jobs/ApplyDependencyPatchJob.php` with:

```php
<?php

namespace App\Jobs;

use App\Models\DependencyPatchProposal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class ApplyDependencyPatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public DependencyPatchProposal $proposal) {}

    /**
     * Always resolves the proposal to 'applied' or 'failed' -- never
     * leaves it stuck 'approved' with no recorded outcome, even if the
     * process itself throws.
     */
    public function handle(): void
    {
        $process = Process::fromShellCommandline($this->proposal->proposed_command, base_path());
        $process->setTimeout(300);
        $output = '';

        try {
            $process->run();
            $output = $process->getOutput().$process->getErrorOutput();

            if (! $process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            $this->proposal->update([
                'status' => 'applied',
                'applied_log' => $output,
                'applied_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            $this->proposal->update([
                'status' => 'failed',
                'applied_log' => trim($output."\n".$exception->getMessage()),
                'applied_at' => now(),
            ]);
        }
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Unit/ApplyDependencyPatchJobTest.php`
Expected: 2 passed.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Jobs/ApplyDependencyPatchJob.php tests/Unit/ApplyDependencyPatchJobTest.php
git commit -m "feat(engage): add ApplyDependencyPatchJob"
```

---

## Task 5: Operator middleware + Livewire review screen + routes

**Files:**
- Create: `app/Http/Middleware/EnsurePlatformOperator.php`
- Modify: `bootstrap/app.php`
- Create: `app/Livewire/Operator/DependencyPatchQueue.php`
- Create: `resources/views/livewire/operator/dependency-patch-queue.blade.php`
- Create: `resources/views/operator/dependency-patches.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/DependencyPatchApprovalTest.php`

**Interfaces:**
- Consumes: `App\Models\DependencyPatchProposal` (Task 2), `App\Jobs\ApplyDependencyPatchJob`
  (Task 4), `$user->is_platform_operator` (Task 1).
- Produces: the `operator` middleware alias; the route
  `GET /operator/dependency-patches` named `operator.dependency-patches.index`; the Livewire
  component `App\Livewire\Operator\DependencyPatchQueue` with public actions `approve(int
  $proposalId): void` and `reject(int $proposalId): void` (reading its rejection reason from
  `$this->rejectReasons[$proposalId]`, a public `array $rejectReasons = []` property keyed by
  proposal ID so multiple pending rows never share one text field).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/DependencyPatchApprovalTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\ApplyDependencyPatchJob;
use App\Livewire\Operator\DependencyPatchQueue;
use App\Models\DependencyPatchProposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class DependencyPatchApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        $operator = User::factory()->create();
        $operator->forceFill(['is_platform_operator' => true])->save();

        return $operator;
    }

    private function pendingProposal(): DependencyPatchProposal
    {
        return DependencyPatchProposal::create([
            'manager' => 'composer',
            'advisories' => [['package' => 'league/commonmark', 'severity' => 'moderate', 'title' => 'x', 'identifier' => 'y']],
            'risk_summary' => '1 advisory: 1 moderate',
            'proposed_command' => 'composer update --with-dependencies',
        ]);
    }

    public function test_non_operator_is_refused_on_the_review_route(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('operator.dependency-patches.index'))
            ->assertForbidden();
    }

    public function test_operator_can_view_the_review_route(): void
    {
        $this->pendingProposal();

        $this->actingAs($this->operator())
            ->get(route('operator.dependency-patches.index'))
            ->assertOk();
    }

    public function test_non_operator_is_refused_by_the_component_itself(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(DependencyPatchQueue::class)->assertForbidden();
    }

    public function test_approving_dispatches_the_apply_job(): void
    {
        Queue::fake();
        $proposal = $this->pendingProposal();
        $operator = $this->operator();
        $this->actingAs($operator);

        Livewire::test(DependencyPatchQueue::class)
            ->call('approve', $proposal->id);

        $fresh = $proposal->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($operator->id, $fresh->reviewed_by);
        Queue::assertPushed(ApplyDependencyPatchJob::class, fn ($job) => $job->proposal->is($fresh));
    }

    public function test_rejecting_without_a_reason_is_blocked(): void
    {
        Queue::fake();
        $proposal = $this->pendingProposal();
        $this->actingAs($this->operator());

        Livewire::test(DependencyPatchQueue::class)
            ->call('reject', $proposal->id)
            ->assertHasErrors(["rejectReasons.{$proposal->id}" => 'required']);

        $this->assertSame('pending_approval', $proposal->fresh()->status);
        Queue::assertNotPushed(ApplyDependencyPatchJob::class);
    }

    public function test_rejecting_with_a_reason_marks_it_rejected(): void
    {
        Queue::fake();
        $proposal = $this->pendingProposal();
        $operator = $this->operator();
        $this->actingAs($operator);

        Livewire::test(DependencyPatchQueue::class)
            ->set("rejectReasons.{$proposal->id}", 'Will patch manually next release.')
            ->call('reject', $proposal->id);

        $fresh = $proposal->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame('Will patch manually next release.', $fresh->rejected_reason);
        $this->assertSame($operator->id, $fresh->reviewed_by);
        Queue::assertNotPushed(ApplyDependencyPatchJob::class);
    }

    public function test_approving_an_already_decided_proposal_is_refused(): void
    {
        Queue::fake();
        $proposal = $this->pendingProposal();
        $proposal->update(['status' => 'rejected', 'rejected_reason' => 'Already handled.']);
        $this->actingAs($this->operator());

        Livewire::test(DependencyPatchQueue::class)
            ->call('approve', $proposal->id);

        $this->assertSame('rejected', $proposal->fresh()->status);
        Queue::assertNotPushed(ApplyDependencyPatchJob::class);
    }

    public function test_rejecting_an_already_decided_proposal_is_refused(): void
    {
        $proposal = $this->pendingProposal();
        $operator = $this->operator();
        $proposal->update(['status' => 'approved', 'reviewed_by' => $operator->id, 'reviewed_at' => now()]);
        $this->actingAs($operator);

        Livewire::test(DependencyPatchQueue::class)
            ->set("rejectReasons.{$proposal->id}", 'Too late.')
            ->call('reject', $proposal->id);

        $this->assertSame('approved', $proposal->fresh()->status);
    }

    public function test_action_level_guard_blocks_a_revoked_operator_mid_session(): void
    {
        Queue::fake();
        $proposal = $this->pendingProposal();
        $operator = $this->operator();
        $this->actingAs($operator);

        $component = Livewire::test(DependencyPatchQueue::class);

        // Simulate the operator flag being revoked after the component
        // already mounted -- mount() only runs once, so only the
        // action-level guard inside approve() itself can catch this.
        $operator->forceFill(['is_platform_operator' => false])->save();

        $component->call('approve', $proposal->id)->assertForbidden();

        $this->assertSame('pending_approval', $proposal->fresh()->status);
        Queue::assertNotPushed(ApplyDependencyPatchJob::class);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/DependencyPatchApprovalTest.php`
Expected: FAIL — none of the middleware, route, or component exist yet.

- [ ] **Step 3: Create the middleware**

Run:
```bash
php artisan make:middleware EnsurePlatformOperator --no-interaction
```

Replace the generated `app/Http/Middleware/EnsurePlatformOperator.php` with:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->is_platform_operator, 403);

        return $next($request);
    }
}
```

- [ ] **Step 4: Alias the middleware**

Read `bootstrap/app.php` first. Its `withMiddleware()` closure is currently empty:

```php
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
```

Add the import at the top of the file, alongside the existing `use` statements:

```php
use App\Http\Middleware\EnsurePlatformOperator;
```

Replace the empty closure body with:

```php
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'operator' => EnsurePlatformOperator::class,
        ]);
    })
```

- [ ] **Step 5: Create the Livewire component**

Run:
```bash
php artisan make:livewire Operator/DependencyPatchQueue --no-interaction
```

Replace the generated `app/Livewire/Operator/DependencyPatchQueue.php` with:

```php
<?php

namespace App\Livewire\Operator;

use App\Jobs\ApplyDependencyPatchJob;
use App\Models\DependencyPatchProposal;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class DependencyPatchQueue extends Component
{
    /**
     * Rejection reason text per pending proposal ID, keyed so multiple
     * pending rows never share one shared text field.
     *
     * @var array<int, string>
     */
    public array $rejectReasons = [];

    public ?string $statusMessage = null;

    public function mount(): void
    {
        $this->authorizeOperator();
    }

    public function approve(int $proposalId): void
    {
        $this->authorizeOperator();

        $proposal = DependencyPatchProposal::findOrFail($proposalId);

        if ($proposal->status !== 'pending_approval') {
            $this->statusMessage = 'Only a pending proposal can be approved.';

            return;
        }

        $proposal->update([
            'status' => 'approved',
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        ApplyDependencyPatchJob::dispatch($proposal);

        $this->statusMessage = 'Patch approved and queued.';
    }

    public function reject(int $proposalId): void
    {
        $this->authorizeOperator();

        $proposal = DependencyPatchProposal::findOrFail($proposalId);

        if ($proposal->status !== 'pending_approval') {
            $this->statusMessage = 'Only a pending proposal can be rejected.';

            return;
        }

        $this->validate([
            "rejectReasons.{$proposalId}" => 'required|string',
        ]);

        $proposal->update([
            'status' => 'rejected',
            'rejected_reason' => $this->rejectReasons[$proposalId],
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        unset($this->rejectReasons[$proposalId]);
        $this->statusMessage = 'Proposal rejected.';
    }

    /**
     * Livewire's AJAX update requests (wire:click calls) do not
     * automatically inherit the page route's "operator" middleware --
     * only the initial page load goes through it. Every action re-checks
     * this directly, and mount() covers the initial render too.
     */
    private function authorizeOperator(): void
    {
        abort_unless(Auth::user()?->is_platform_operator, 403);
    }

    public function render()
    {
        return view('livewire.operator.dependency-patch-queue', [
            'pending' => DependencyPatchProposal::where('status', 'pending_approval')->with('reviewer')->latest()->get(),
            'reviewed' => DependencyPatchProposal::whereIn('status', ['approved', 'rejected', 'applied', 'failed'])
                ->with('reviewer')->latest()->limit(20)->get(),
        ]);
    }
}
```

- [ ] **Step 6: Create the component's view**

Create `resources/views/livewire/operator/dependency-patch-queue.blade.php`:

```blade
<div class="space-y-6">
    @if($statusMessage)
        <div class="rounded-md bg-blue-50 px-4 py-3 text-sm text-blue-800">{{ $statusMessage }}</div>
    @endif

    <div>
        <h3 class="text-sm font-semibold text-gray-900 mb-3">Pending Dependency Patches</h3>
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Manager</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Risk</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Proposed Command</th>
                        <th class="relative px-6 py-3"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($pending as $proposal)
                        <tr>
                            <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ ucfirst($proposal->manager) }}</td>
                            <td class="px-6 py-4 text-sm text-gray-500">{{ $proposal->risk_summary }}</td>
                            <td class="px-6 py-4 text-sm text-gray-500 font-mono">{{ $proposal->proposed_command }}</td>
                            <td class="px-6 py-4 text-right text-sm font-medium">
                                <div class="flex items-center justify-end gap-2 mb-2">
                                    <button wire:click="approve({{ $proposal->id }})"
                                            wire:confirm="Approve and queue this patch?"
                                            class="text-green-600 hover:text-green-900">Approve</button>
                                </div>
                                <div class="flex items-center justify-end gap-2">
                                    <input type="text" wire:model="rejectReasons.{{ $proposal->id }}"
                                           placeholder="Reason for rejecting…"
                                           class="w-48 rounded-md border-gray-300 shadow-sm text-xs">
                                    <button wire:click="reject({{ $proposal->id }})"
                                            class="text-red-600 hover:text-red-900">Reject</button>
                                </div>
                                @error("rejectReasons.{$proposal->id}")
                                    <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                                @enderror
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-4 text-sm text-gray-500 text-center">No pending proposals.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div>
        <h3 class="text-sm font-semibold text-gray-900 mb-3">Recent Decisions</h3>
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Manager</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reviewer</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Decided</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($reviewed as $proposal)
                        <tr>
                            <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ ucfirst($proposal->manager) }}</td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                    {{ $proposal->status === 'applied' ? 'bg-green-100 text-green-800' : ($proposal->status === 'failed' ? 'bg-red-100 text-red-800' : ($proposal->status === 'approved' ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-800')) }}">
                                    {{ ucfirst($proposal->status) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500">{{ $proposal->reviewer?->name ?? '—' }}</td>
                            <td class="px-6 py-4 text-sm text-gray-500">{{ $proposal->reviewed_at?->format('M d, Y') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-4 text-sm text-gray-500 text-center">No decisions yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
```

- [ ] **Step 7: Create the page wrapper view**

Create `resources/views/operator/dependency-patches.blade.php`:

```blade
<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800">Dependency Patches</h2></x-slot>
    <div class="py-6"><div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <livewire:operator.dependency-patch-queue />
    </div></div>
</x-app-layout>
```

- [ ] **Step 8: Add the route**

Read `routes/web.php` first. Inside the existing `Route::middleware(['auth:sanctum',
config('jetstream.auth_session'), 'verified'])->group(function () { ... })` block, after the
`// ── Notifications ──` section at the end, add:

```php

    // ── Operator: Dependency Patches ───────────────────────────────────────────
    // Livewire component handles approve/reject; this is a page-view route only.
    Route::middleware('operator')->prefix('operator')->name('operator.')->group(function () {
        Route::get('/dependency-patches', fn () => view('operator.dependency-patches'))
            ->name('dependency-patches.index');
    });
```

- [ ] **Step 9: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/DependencyPatchApprovalTest.php`
Expected: 9 passed.

- [ ] **Step 10: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Middleware/EnsurePlatformOperator.php bootstrap/app.php app/Livewire/Operator/DependencyPatchQueue.php resources/views/livewire/operator/dependency-patch-queue.blade.php resources/views/operator/dependency-patches.blade.php routes/web.php tests/Feature/DependencyPatchApprovalTest.php
git commit -m "feat(engage): add operator review screen for dependency patches"
```

---

## Task 6: Full regression

**Files:** none (verification only).

- [ ] **Step 1: Run the full test suite**

```bash
php artisan test --compact
```

Expected: all tests pass, including every pre-existing suite (video sessions, contracts, chat,
notifications, ecosystem auth) alongside the five new suites from this plan
(`PlatformOperatorFlagTest`, `DependencyPatchProposalModelTest`, `ScanDependencyAdvisoriesTest`,
`ApplyDependencyPatchJobTest`, `DependencyPatchApprovalTest`).

- [ ] **Step 2: Run Pint across the whole diff one final time**

```bash
vendor/bin/pint --dirty --format agent
```

If it reformats anything, `git add -A` and amend or add a small formatting commit.

- [ ] **Step 3: Report**

Summarize the final test count and confirm this plan's scope (Tasks 1-5) is fully implemented
against `docs/superpowers/specs/2026-08-09-dependency-patch-approval-gate-design.md`. No commits
in this task beyond an optional Pint formatting fix — this is verification-only.
