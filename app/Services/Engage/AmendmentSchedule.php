<?php

namespace App\Services\Engage;

use App\Models\Amendment;
use App\Models\Contract;
use App\Models\ContractVersion;
use App\Models\Engagement;
use App\Services\Pdf\PdfPageAppender;
use App\Support\Engage\Actor;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Turns accepted changes into a new version of the document: the previous version, untouched, followed by a
 * "Schedule of agreed amendments" that lists each change, who proposed it and who accepted it. The earlier text is
 * never edited in place; the schedule is what the parties agreed to read together with it.
 */
class AmendmentSchedule
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly AuditTrail $audit,
        private readonly EngagementStateMachine $states,
    ) {}

    public function createRevision(Contract $contract, Actor $actor, int $userId): ContractVersion
    {
        $engagement = Engagement::withoutGlobalScopes()->findOrFail($contract->engagement_id);
        $this->documents->assertEditable($contract, $engagement);

        $amendments = Amendment::with(['participant', 'reviewer'])->where('contract_id', $contract->id)
            ->where('status', 'accepted')->whereNull('applied_in_version_id')->orderBy('id')->get();
        if ($amendments->isEmpty()) {
            throw new InvalidArgumentException('There are no accepted changes waiting to go into a new version.');
        }

        $base = $contract->currentVersionRecord();
        if (! $base || $base->mime !== 'application/pdf') {
            throw new InvalidArgumentException('Only PDF documents can be revised automatically. Upload the revised file as a new version instead.');
        }

        $schedule = Pdf::loadView('pdf.amendment-schedule', [
            'contract' => $contract,
            'engagement' => $engagement,
            'amendments' => $amendments,
            'base' => $base,
            'number' => $base->version_number + 1,
            'generatedAt' => now(),
        ])->setPaper('a4', 'portrait')->output();

        try {
            $merged = (new PdfPageAppender)->appendPagesFrom($this->documents->versionFile($base), $schedule);
        } catch (Throwable $e) {
            report($e);
            throw new InvalidArgumentException('This PDF could not be combined with the schedule automatically. Download the schedule from the history, add it to your document and upload that as a new version.');
        }

        return DB::transaction(function () use ($contract, $merged, $amendments, $actor, $userId, $engagement) {
            $version = $this->documents->addVersion($contract, $merged, 'Agreed amendments added as a schedule ('.$amendments->count().')', $actor, $userId);
            Amendment::whereIn('id', $amendments->pluck('id'))->update(['applied_in_version_id' => $version->id]);
            $this->states->sync($engagement, $actor);

            return $version;
        });
    }
}
