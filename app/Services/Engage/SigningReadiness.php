<?php

namespace App\Services\Engage;

use App\Models\Amendment;
use App\Models\Contract;
use App\Models\DocumentComment;
use App\Models\Engagement;
use App\Models\EngagementApproval;
use App\Models\SigningEnvelope;

/**
 * The checklist that has to be true before a signing request may be sent. Each item says what it found, so a person can
 * see exactly what is missing instead of a button that will not press.
 */
class SigningReadiness
{
    /** @return list<array{key: string, label: string, ok: bool, detail: string}> */
    public function check(Engagement $engagement, Contract $contract, ?SigningEnvelope $draft = null): array
    {
        $version = $contract->currentVersionRecord();
        $people = $engagement->participants()->whereNull('revoked_at')->whereNull('declined_at')->get();
        $items = [];

        $invited = $people->filter(fn ($p) => $p->role->value !== 'owner');
        $items[] = $this->item('invited', 'Everyone required has been invited and has joined',
            $people->where('required', true)->whereNull('accepted_at')->isEmpty() && $invited->isNotEmpty(),
            $invited->isEmpty() ? 'Nobody outside the owner has been invited.' : $people->where('required', true)->whereNull('accepted_at')->count().' required '.str('person')->plural($people->where('required', true)->whereNull('accepted_at')->count()).' have not joined yet.');

        $items[] = $this->item('document', 'The agreement is a PDF that can be signed',
            $version !== null && $contract->isPdf(), $version === null ? 'No document yet.' : ($contract->isPdf() ? 'Version '.$version->version_number.' is ready.' : 'Only PDF documents can be signed. Upload a PDF version.'));

        $openThreads = DocumentComment::where('contract_id', $contract->id)->whereNull('parent_id')->where('status', 'open')->count();
        $items[] = $this->item('notes', 'Notes are resolved or marked as discussed', $openThreads === 0, $openThreads === 0 ? 'No open notes.' : $openThreads.' open '.str('note')->plural($openThreads).'.');

        $openChanges = Amendment::where('contract_id', $contract->id)->where('status', 'proposed')->count();
        $unapplied = Amendment::where('contract_id', $contract->id)->where('status', 'accepted')->whereNull('applied_in_version_id')->count();
        $items[] = $this->item('amendments', 'Proposed changes are reviewed and included in the version',
            $openChanges === 0 && $unapplied === 0,
            $openChanges ? $openChanges.' proposed '.str('change')->plural($openChanges).' still to review.' : ($unapplied ? $unapplied.' accepted '.str('change')->plural($unapplied).' not yet in a version.' : 'Nothing outstanding.'));

        $approvals = $version ? EngagementApproval::where('contract_version_id', $version->id)->get() : collect();
        $approversExist = $people->contains(fn ($p) => $p->hasCapability('approve'));
        $agreed = $version && $engagement->agreed_version_id === $version->id;
        $items[] = $this->item('approvals', 'Required approvals are complete',
            $agreed,
            $agreed ? ($approvals->isNotEmpty() ? $approvals->count().' of '.$approvals->count().' approved.' : 'Terms recorded as agreed.')
                : ($approversExist ? ($approvals->isEmpty() ? 'Approval has not been requested.' : $approvals->where('status', 'approved')->count().' of '.$approvals->count().' approved.') : 'Record that the terms are agreed.'));

        $signers = $draft?->signers()->where('status', '!=', 'reassigned')->get() ?? collect();
        $items[] = $this->item('signers', 'Signatories are assigned', $signers->isNotEmpty(), $signers->isEmpty() ? 'Choose who signs.' : $signers->count().' '.str('signatory')->plural($signers->count()).'.');

        $fields = $draft?->fields()->get() ?? collect();
        $missing = $signers->filter(fn ($s) => ! $fields->where('signer_id', $s->id)->where('type', 'signature')->count());
        $items[] = $this->item('fields', 'Every signatory has a signature field placed on the page',
            $signers->isNotEmpty() && $missing->isEmpty(),
            $signers->isEmpty() ? 'Assign signatories first.' : ($missing->isEmpty() ? $fields->count().' fields placed.' : $missing->map(fn ($s) => $s->participant->name)->join(', ').' still need a signature field.'));

        $orders = $signers->pluck('sign_order')->all();
        $orderOk = $draft?->mode !== 'sequential' || ($orders !== [] && count(array_unique($orders)) === count($orders));
        $items[] = $this->item('order', 'The signing order is set', $signers->isNotEmpty() && $orderOk,
            $draft?->mode === 'sequential' ? ($orderOk ? 'Signing in order 1 to '.count($orders).'.' : 'Each signatory needs a different position.') : 'Everyone can sign at the same time.');

        return $items;
    }

    public function passes(Engagement $engagement, Contract $contract, ?SigningEnvelope $draft = null): bool
    {
        return collect($this->check($engagement, $contract, $draft))->every(fn ($i) => $i['ok']);
    }

    /** @return array{key: string, label: string, ok: bool, detail: string} */
    private function item(string $key, string $label, bool $ok, string $detail): array
    {
        return compact('key', 'label', 'ok', 'detail');
    }
}
