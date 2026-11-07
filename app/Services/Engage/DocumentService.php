<?php

namespace App\Services\Engage;

use App\Models\Contract;
use App\Models\ContractVersion;
use App\Models\Engagement;
use App\Support\Engage\Actor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Documents inside an engagement. A document is a Contract row; every file it ever had is a ContractVersion with
 * its own SHA-256, so what was agreed and what was signed can always be told apart from what came later.
 */
class DocumentService
{
    public const DISK = 'contracts';

    public function __construct(
        private readonly AuditTrail $audit,
        private readonly EngagementStateMachine $states,
        private readonly EngagementService $engagements,
        private readonly PdfInspector $pdf,
    ) {}

    /** Add a document (first version) to an engagement. */
    public function upload(Engagement $engagement, UploadedFile $file, string $title, Actor $actor, int $userId, ?string $description = null): Contract
    {
        $bytes = $this->readUpload($file);
        $facts = $this->inspect($bytes, $file->getClientOriginalName(), $file->getMimeType());
        $path = $this->store($bytes, $facts['extension']);

        $contract = DB::transaction(function () use ($engagement, $title, $description, $path, $facts, $userId, $bytes, $actor) {
            $contract = Contract::withoutGlobalScopes()->create([
                'team_id' => $engagement->team_id,
                'engagement_id' => $engagement->id,
                'created_by' => $userId,
                'title' => $title,
                'description' => $description,
                'file_path' => $path,
                'file_size' => strlen($bytes),
                'mime' => $facts['mime'],
                'sha256' => hash('sha256', $bytes),
                'page_count' => $facts['pages'],
                'current_version' => 1,
                'status' => 'pending',
                'expires_at' => $engagement->expires_at,
            ]);

            ContractVersion::create([
                'contract_id' => $contract->id,
                'created_by' => $userId,
                'version_number' => 1,
                'kind' => 'original',
                'file_path' => $path,
                'sha256' => hash('sha256', $bytes),
                'size' => strlen($bytes),
                'page_count' => $facts['pages'],
                'mime' => $facts['mime'],
                'stampable' => $facts['stampable'],
                'label' => 'First version',
                'change_notes' => 'Uploaded',
            ]);

            $this->audit->record($engagement, 'document.uploaded', $actor, $contract, [
                'name' => $title, 'sha256' => hash('sha256', $bytes), 'pages' => $facts['pages'], 'bytes' => strlen($bytes),
            ]);

            return $contract;
        });

        $this->states->sync($engagement, $actor);
        $this->engagements->notifyOthers($engagement, null, 'document_uploaded', 'A document was added: '.$title, route('engagements.show', $engagement), 'Open the agreement');

        return $contract;
    }

    /** Start a document from a file already on the contracts disk (a template). The file is copied, never shared. */
    public function uploadStored(Engagement $engagement, string $path, string $title, Actor $actor, int $userId): Contract
    {
        $bytes = (string) Storage::disk(self::DISK)->get($path);
        $file = tmpfile();
        fwrite($file, $bytes);
        $meta = stream_get_meta_data($file);
        $upload = new UploadedFile($meta['uri'], 'template.pdf', 'application/pdf', null, true);

        try {
            return $this->upload($engagement, $upload, $title, $actor, $userId);
        } finally {
            fclose($file);
        }
    }

    /**
     * Replace the working file with a new version. The earlier versions stay exactly as they were.
     * Refused once a signing request exists: a document that is out for signature is never changed under the signers.
     */
    public function addVersion(Contract $contract, UploadedFile|string $file, string $reason, Actor $actor, int $userId, string $kind = 'revision'): ContractVersion
    {
        $engagement = Engagement::withoutGlobalScopes()->findOrFail($contract->engagement_id);
        $this->assertEditable($contract, $engagement);

        $bytes = $file instanceof UploadedFile ? $this->readUpload($file) : $file;
        $facts = $this->inspect($bytes, $file instanceof UploadedFile ? $file->getClientOriginalName() : 'revision.pdf', $file instanceof UploadedFile ? $file->getMimeType() : 'application/pdf');
        $path = $this->store($bytes, $facts['extension']);

        $version = DB::transaction(function () use ($contract, $engagement, $reason, $actor, $userId, $bytes, $facts, $path, $kind) {
            $locked = Contract::withoutGlobalScopes()->whereKey($contract->id)->lockForUpdate()->first();
            $this->assertEditable($locked, $engagement);

            $number = ((int) ContractVersion::where('contract_id', $locked->id)->max('version_number')) + 1;

            $version = ContractVersion::create([
                'contract_id' => $locked->id,
                'created_by' => $userId,
                'version_number' => $number,
                'kind' => $kind,
                'file_path' => $path,
                'sha256' => hash('sha256', $bytes),
                'size' => strlen($bytes),
                'page_count' => $facts['pages'],
                'mime' => $facts['mime'],
                'stampable' => $facts['stampable'],
                'label' => 'Version '.$number,
                'change_notes' => mb_substr($reason, 0, 500),
            ]);

            $locked->forceFill([
                'file_path' => $path,
                'file_size' => strlen($bytes),
                'mime' => $facts['mime'],
                'sha256' => hash('sha256', $bytes),
                'page_count' => $facts['pages'],
                'current_version' => $number,
                'status' => 'pending',
            ])->save();

            // Approvals and agreement belong to a version: a new version starts without either.
            $engagement->forceFill(['agreed_version_id' => null, 'agreed_at' => null])->save();

            $this->audit->record($engagement, 'document.version_created', $actor, $version, [
                'version' => $number, 'sha256' => hash('sha256', $bytes), 'reason' => $reason, 'pages' => $facts['pages'],
            ]);

            return $version;
        });

        $this->states->sync($engagement, $actor);
        $this->engagements->notifyOthers($engagement, null, 'document_revised', 'Version '.$version->version_number.' of '.$contract->title.' is available', route('engagements.show', $engagement), 'Open the agreement');

        return $version;
    }

    public function assertEditable(Contract $contract, Engagement $engagement): void
    {
        if (! $engagement->isOpen()) {
            throw new InvalidArgumentException('This engagement is closed.');
        }
        if ($contract->isLocked()) {
            throw new InvalidArgumentException('This document is out for signature. Cancel the signing request first if it has to change; signed versions are never edited.');
        }
    }

    public function versionFile(ContractVersion $version): string
    {
        abort_unless($version->file_path && Storage::disk(self::DISK)->exists($version->file_path), 404, 'The file is missing.');

        return (string) Storage::disk(self::DISK)->get($version->file_path);
    }

    /** Check the stored bytes still match the hash recorded when the version was created. */
    public function intact(ContractVersion $version): bool
    {
        if (! $version->sha256 || ! $version->file_path || ! Storage::disk(self::DISK)->exists($version->file_path)) {
            return false;
        }

        return hash_equals($version->sha256, hash('sha256', (string) Storage::disk(self::DISK)->get($version->file_path)));
    }

    private function readUpload(UploadedFile $file): string
    {
        abort_unless($file->isValid(), 422, 'The upload did not complete. Try again.');
        $bytes = (string) file_get_contents($file->getRealPath());
        if ($bytes === '') {
            throw new InvalidArgumentException('That file is empty.');
        }
        if (strlen($bytes) > config('engage.max_upload_kb') * 1024) {
            throw new InvalidArgumentException('That file is larger than '.(config('engage.max_upload_kb') / 1024).' MB.');
        }

        return $bytes;
    }

    /** @return array{mime: string, extension: string, pages: ?int, stampable: bool} */
    private function inspect(string $bytes, string $name, ?string $clientMime): array
    {
        // The content decides, never the file name or the browser's guess.
        if (str_starts_with($bytes, '%PDF-')) {
            $info = $this->pdf->inspect($bytes);

            return ['mime' => 'application/pdf', 'extension' => 'pdf', 'pages' => $info['pages'], 'stampable' => $info['stampable']];
        }

        $isDocx = str_starts_with($bytes, "PK\x03\x04") && str_ends_with(strtolower($name), '.docx');
        $isDoc = str_starts_with($bytes, "\xD0\xCF\x11\xE0") && str_ends_with(strtolower($name), '.doc');
        if ($isDocx || $isDoc) {
            return ['mime' => $isDocx ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : 'application/msword', 'extension' => $isDocx ? 'docx' : 'doc', 'pages' => null, 'stampable' => false];
        }

        throw new InvalidArgumentException('Only PDF files can be signed. Export the agreement as a PDF and upload that.');
    }

    private function store(string $bytes, string $extension): string
    {
        $path = Str::uuid()->toString().'.'.$extension;
        Storage::disk(self::DISK)->put($path, $bytes);

        return $path;
    }
}
