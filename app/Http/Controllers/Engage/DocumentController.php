<?php

namespace App\Http\Controllers\Engage;

use App\Http\Controllers\Controller;
use App\Models\Amendment;
use App\Models\Contract;
use App\Models\ContractVersion;
use App\Models\DocumentComment;
use App\Models\Engagement;
use App\Models\EngagementApproval;
use App\Services\Engage\AmendmentSchedule;
use App\Services\Engage\AuditTrail;
use App\Services\Engage\DocumentService;
use App\Services\Engage\EngagementAccess;
use App\Services\Engage\EngagementStateMachine;
use App\Services\Engage\NegotiationService;
use App\Services\Engage\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class DocumentController extends Controller
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly NegotiationService $negotiation,
        private readonly AuditTrail $audit,
    ) {}

    private function access(Request $request, Engagement $engagement, string $ability = 'view'): EngagementAccess
    {
        $access = EngagementAccess::resolve($request, $engagement);
        abort_if($access === null, $request->user() ? 404 : 403);
        abort_unless($access->can($ability), 403, 'Your role does not allow that.');

        return $access;
    }

    private function owned(Engagement $engagement, Contract $document): Contract
    {
        abort_unless($document->engagement_id === $engagement->id, 404);

        return $document;
    }

    private function failed(Request $request, InvalidArgumentException $e): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['message' => $e->getMessage()], 422)
            : back()->withInput()->withErrors(['document' => $e->getMessage()]);
    }

    // ── Documents ────────────────────────────────────────────────────────────

    public function upload(Request $request, Engagement $engagement): RedirectResponse|JsonResponse
    {
        $access = $this->access($request, $engagement, 'manage_documents');
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.config('engage.max_upload_kb')],
            'title' => ['nullable', 'string', 'max:160'],
        ]);

        try {
            $title = trim($data['title'] ?? '') ?: pathinfo($request->file('file')->getClientOriginalName(), PATHINFO_FILENAME);
            $contract = $this->documents->upload($engagement, $request->file('file'), $title, $access->actor($request), $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return $this->failed($request, $e);
        }

        return redirect()->route('engagements.show', ['engagement' => $engagement, 'doc' => $contract->id])->with('status', 'Document added.');
    }

    public function newVersion(Request $request, Engagement $engagement, Contract $document): RedirectResponse|JsonResponse
    {
        $access = $this->access($request, $engagement, 'manage_documents');
        $this->owned($engagement, $document);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.config('engage.max_upload_kb')],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $v = $this->documents->addVersion($document, $request->file('file'), $data['reason'], $access->actor($request), $request->user()->id);
            if ($request->boolean('incorporates')) {
                Amendment::where('contract_id', $document->id)->where('status', 'accepted')->whereNull('applied_in_version_id')->update(['applied_in_version_id' => $v->id]);
                app(EngagementStateMachine::class)->sync($engagement, $access->actor($request));
            }
        } catch (InvalidArgumentException $e) {
            return $this->failed($request, $e);
        }

        return redirect()->route('engagements.show', ['engagement' => $engagement, 'doc' => $document->id])->with('status', 'Version '.$v->version_number.' is now the working version. Earlier versions are kept.');
    }

    /** Stream a version's file to someone who may see the engagement. Never cached, never indexed. */
    public function file(Request $request, Engagement $engagement, Contract $document): Response
    {
        $access = $this->access($request, $engagement, 'view');
        $this->owned($engagement, $document);

        $version = $request->integer('v')
            ? ContractVersion::where('contract_id', $document->id)->findOrFail($request->integer('v'))
            : $document->currentVersionRecord();
        abort_unless($version, 404);

        $bytes = $this->documents->versionFile($version);
        $download = $request->boolean('download');

        $key = 'engage.view.'.$engagement->id.'.'.($access->participant?->id ?? 'u'.$access->user?->id).'.'.$version->id.($download ? '.d' : '');
        if (Cache::add($key, 1, 3600)) {
            $this->audit->record($engagement, $download ? 'document.downloaded' : 'document.viewed', $access->actor($request), $version, ['version' => $version->version_number]);
        }

        $name = str($document->title)->slug()->limit(60, '').'-v'.$version->version_number.'.'.($version->mime === 'application/pdf' ? 'pdf' : (str_ends_with((string) $version->file_path, 'docx') ? 'docx' : 'doc'));

        return response($bytes, 200, [
            'Content-Type' => $version->mime ?: 'application/octet-stream',
            'Content-Length' => strlen($bytes),
            'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Robots-Tag' => 'noindex, nofollow',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'ETag' => '"'.$version->sha256.'"',
        ]);
    }

    public function compare(Request $request, Engagement $engagement, Contract $document)
    {
        $access = $this->access($request, $engagement, 'view');
        $this->owned($engagement, $document);
        $versions = ContractVersion::where('contract_id', $document->id)->orderBy('version_number')->get();
        abort_if($versions->count() < 2, 404);

        $b = $versions->firstWhere('id', $request->integer('b')) ?? $versions->last();
        $a = $versions->firstWhere('id', $request->integer('a')) ?? $versions->where('version_number', '<', $b->version_number)->last() ?? $versions->first();
        abort_if($a->id === $b->id, 422, 'Pick two different versions.');

        return view('engagements.compare', [
            'engagement' => $engagement, 'access' => $access, 'ws' => new Workspace($engagement->load('parties'), $access), 'tab' => 'agreement',
            'document' => $document, 'versions' => $versions, 'a' => $a, 'b' => $b,
            'urlA' => route('documents.file', [$engagement, $document, 'v' => $a->id]),
            'urlB' => route('documents.file', [$engagement, $document, 'v' => $b->id]),
        ]);
    }

    public function revise(Request $request, Engagement $engagement, Contract $document): JsonResponse
    {
        $access = $this->access($request, $engagement, 'manage_documents');
        $this->owned($engagement, $document);

        try {
            $version = app(AmendmentSchedule::class)->createRevision($document, $access->actor($request), $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['version' => $version->version_number]);
    }

    // ── State for the viewer ─────────────────────────────────────────────────

    public function state(Request $request, Engagement $engagement, Contract $document): JsonResponse
    {
        $access = $this->access($request, $engagement, 'view');
        $this->owned($engagement, $document);
        $ws = new Workspace($engagement->load('parties'), $access);
        $me = $access->participantRecord();

        $pen = fn (?int $partyId) => $ws->penFor($partyId);
        $mineId = $me?->id;

        $versions = ContractVersion::with('creator')->where('contract_id', $document->id)->orderBy('version_number')->get();
        $current = $versions->last();
        $viewing = $request->integer('v') ? $versions->firstWhere('id', $request->integer('v')) : $current;
        abort_unless($viewing, 404);

        $threads = DocumentComment::with(['replies.participant', 'participant'])
            ->where('contract_id', $document->id)->whereNull('parent_id')->orderBy('id')->get()
            ->filter(fn ($t) => $t->contract_version_id === $viewing->id || $t->status !== 'resolved')
            ->map(function (DocumentComment $t) use ($pen, $mineId, $versions) {
                $fmt = fn (DocumentComment $c) => [
                    'id' => $c->id, 'author' => $c->author_name, 'pen' => $pen($c->participant?->party_id), 'body' => $c->body,
                    'at' => $c->created_at->toIso8601String(), 'mine' => $c->participant_id !== null && $c->participant_id === $mineId,
                ];

                return $fmt($t) + [
                    'version_id' => $t->contract_version_id,
                    'version_number' => $versions->firstWhere('id', $t->contract_version_id)?->version_number,
                    'page' => $t->page, 'quote' => $t->quote, 'kind' => $t->kind, 'status' => $t->status,
                    'rect' => $t->x !== null ? ['x' => $t->x, 'y' => $t->y, 'w' => $t->w, 'h' => $t->h] : null,
                    'replies' => $t->replies->map($fmt)->values(),
                ];
            })->values();

        $amendments = Amendment::with('participant')->where('contract_id', $document->id)->orderBy('id')->get()->map(fn (Amendment $a) => [
            'id' => $a->id, 'type' => $a->type, 'type_label' => $a->typeLabel(), 'clause_ref' => $a->clause_ref, 'page' => $a->page,
            'original_text' => $a->original_text, 'proposed_text' => $a->proposed_text, 'explanation' => $a->explanation,
            'status' => $a->status, 'author' => $a->author_name, 'pen' => $pen($a->participant?->party_id), 'at' => $a->created_at->toIso8601String(),
            'base_version' => $versions->firstWhere('id', $a->base_version_id)?->version_number,
            'applied_in' => $versions->firstWhere('id', $a->applied_in_version_id)?->version_number,
            'review_note' => $a->review_note,
            'can_review' => $a->status === 'proposed' && $access->can('review_amendment') && $a->participant_id !== $mineId,
            'can_withdraw' => $a->status === 'proposed' && ($a->participant_id === $mineId || $access->can('manage_documents')),
        ])->values();

        $approvals = EngagementApproval::with('participant')->where('contract_version_id', $current->id)->get()->map(fn ($a) => [
            'id' => $a->id, 'name' => $a->participant->name, 'pen' => $pen($a->participant->party_id), 'status' => $a->status,
            'note' => $a->note, 'decided_at' => $a->decided_at?->toIso8601String(), 'mine' => $a->participant_id === $mineId,
        ])->values();

        $approvers = $engagement->participants()->whereNull('revoked_at')->whereNull('declined_at')->get()->filter(fn ($p) => $p->hasCapability('approve'))->count();
        $openItems = $threads->where('status', 'open')->count() + $amendments->where('status', 'proposed')->count();
        $unapplied = $amendments->where('status', 'accepted')->whereNull('applied_in')->count();

        return response()->json([
            'contract' => [
                'id' => $document->id, 'title' => $document->title, 'pages' => $document->page_count, 'locked' => $document->isLocked(),
                'is_pdf' => $document->isPdf(), 'status' => $document->status,
            ],
            'versions' => $versions->map(fn (ContractVersion $v) => [
                'id' => $v->id, 'number' => $v->version_number, 'kind' => $v->kind, 'label' => $v->label, 'notes' => $v->change_notes,
                'sha256' => $v->sha256, 'size' => $v->size, 'pages' => $v->page_count, 'at' => $v->created_at->toIso8601String(), 'by' => $v->creator?->name,
            ])->values(),
            'viewing' => ['id' => $viewing->id, 'number' => $viewing->version_number, 'is_current' => $viewing->id === $current->id],
            'file_url' => route('documents.file', ['engagement' => $engagement, 'document' => $document, 'v' => $viewing->id]),
            'threads' => $threads,
            'amendments' => $amendments,
            'approvals' => $approvals,
            'agreement' => [
                'agreed' => $engagement->agreed_version_id === $current->id,
                'agreed_at' => $engagement->agreed_at?->toIso8601String(),
                'approvers' => $approvers,
                'requested' => $approvals->isNotEmpty(),
                'open_items' => $openItems,
                'unapplied' => $unapplied,
                'can_request' => $access->can('request_approval') && $engagement->isOpen() && ! $document->isLocked() && $approvers > 0 && $approvals->isEmpty(),
                'can_record' => $access->can('request_approval') && $engagement->isOpen() && ! $document->isLocked() && $approvers === 0 && $engagement->agreed_version_id !== $current->id,
                'can_revise' => $access->can('manage_documents') && $unapplied > 0 && ! $document->isLocked(),
            ],
            'me' => [
                'participant_id' => $mineId, 'name' => $access->name(),
                'can' => [
                    'comment' => $access->can('comment'), 'propose' => $access->can('propose'), 'review' => $access->can('review_amendment'),
                    'manage' => $access->can('manage_documents'), 'resolve' => $access->can('comment'),
                ],
            ],
        ])->header('Cache-Control', 'private, no-store');
    }

    // ── Notes ────────────────────────────────────────────────────────────────

    public function comment(Request $request, Engagement $engagement, Contract $document): JsonResponse
    {
        $access = $this->access($request, $engagement, 'comment');
        $this->owned($engagement, $document);
        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
            'page' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'quote' => ['nullable', 'string', 'max:1200'],
            'x' => ['nullable', 'numeric'], 'y' => ['nullable', 'numeric'], 'w' => ['nullable', 'numeric'], 'h' => ['nullable', 'numeric'],
            'kind' => ['nullable', 'in:comment,question'],
        ]);

        $c = $this->negotiation->addComment($document, $access, $data['body'], $data);

        return response()->json(['id' => $c->id], 201);
    }

    public function reply(Request $request, Engagement $engagement, DocumentComment $comment): JsonResponse
    {
        $access = $this->access($request, $engagement, 'comment');
        abort_unless($comment->engagement_id === $engagement->id, 404);
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);

        $r = $this->negotiation->reply($comment, $access, $data['body']);

        return response()->json(['id' => $r->id], 201);
    }

    public function commentStatus(Request $request, Engagement $engagement, DocumentComment $comment): JsonResponse
    {
        $access = $this->access($request, $engagement, 'comment');
        abort_unless($comment->engagement_id === $engagement->id, 404);
        $data = $request->validate(['status' => ['required', 'in:open,discussed,resolved']]);

        $this->negotiation->setStatus($comment, $access, $data['status']);

        return response()->json(['ok' => true]);
    }

    // ── Proposed changes ─────────────────────────────────────────────────────

    public function propose(Request $request, Engagement $engagement, Contract $document): JsonResponse
    {
        $access = $this->access($request, $engagement, 'propose');
        $this->owned($engagement, $document);
        $data = $request->validate([
            'type' => ['required', 'in:replace,add,remove'],
            'clause_ref' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'original_text' => ['nullable', 'string', 'max:4000'],
            'proposed_text' => ['nullable', 'string', 'max:4000'],
            'explanation' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $a = $this->negotiation->propose($document, $access, $data);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['id' => $a->id], 201);
    }

    public function reviewAmendment(Request $request, Engagement $engagement, Amendment $amendment): JsonResponse
    {
        $access = $this->access($request, $engagement, 'review_amendment');
        abort_unless($amendment->engagement_id === $engagement->id, 404);
        $data = $request->validate(['decision' => ['required', 'in:accepted,rejected'], 'note' => ['nullable', 'string', 'max:500']]);

        try {
            $this->negotiation->review($amendment, $access, $data['decision'], $data['note'] ?? null);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function withdrawAmendment(Request $request, Engagement $engagement, Amendment $amendment): JsonResponse
    {
        $access = $this->access($request, $engagement, 'propose');
        abort_unless($amendment->engagement_id === $engagement->id, 404);

        try {
            $this->negotiation->withdraw($amendment, $access);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }

    // ── Approval ─────────────────────────────────────────────────────────────

    public function requestApproval(Request $request, Engagement $engagement, Contract $document): JsonResponse
    {
        $access = $this->access($request, $engagement, 'request_approval');
        $this->owned($engagement, $document);

        try {
            $this->negotiation->requestApproval($document, $access);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function decideApproval(Request $request, Engagement $engagement, EngagementApproval $approval): JsonResponse
    {
        $access = $this->access($request, $engagement, 'approve');
        abort_unless($approval->engagement_id === $engagement->id, 404);
        $data = $request->validate(['decision' => ['required', 'in:approved,rejected'], 'note' => ['nullable', 'string', 'max:500']]);

        try {
            $this->negotiation->decideApproval($approval, $access, $data['decision'], $data['note'] ?? null);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function recordAgreement(Request $request, Engagement $engagement, Contract $document): JsonResponse
    {
        $access = $this->access($request, $engagement, 'request_approval');
        $this->owned($engagement, $document);

        try {
            $this->negotiation->recordAgreement($document, $access);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }
}
