@php
    $can = fn ($a) => $access->can($a);
    $docs = $engagement->contracts()->orderBy('id')->get();
    $current = $contract && request()->integer('doc') ? ($docs->firstWhere('id', request()->integer('doc')) ?? $contract) : $contract;
    $viewerConfig = $current ? [
            'stateUrl' => route('documents.state', [$engagement, $current]),
            'version' => request('v'),
            'downloadUrl' => route('documents.file', [$engagement, $current, 'download' => 1]),
            'marginWidth' => 340,
            'standardFonts' => '/vendor/pdfjs/standard_fonts/',
            'cmaps' => '/vendor/pdfjs/cmaps/',
            'urls' => [
                'comment' => route('documents.comment', [$engagement, $current]),
                'propose' => route('amendments.propose', [$engagement, $current]),
                'requestApproval' => route('approvals.request', [$engagement, $current]),
                'agree' => route('documents.agree', [$engagement, $current]),
                'revise' => route('documents.revise', [$engagement, $current]),
                'decide' => route('approvals.decide', [$engagement, '__ID__']),
                'reply' => route('comments.reply', [$engagement, '__ID__']),
                'commentStatus' => route('comments.status', [$engagement, '__ID__']),
                'reviewAmendment' => route('amendments.review', [$engagement, '__ID__']),
                'withdrawAmendment' => route('amendments.withdraw', [$engagement, '__ID__']),
            ],
        ] : null;
@endphp

    @if ($errors->has('document'))<div class="flash is-error" role="alert"><x-icon name="warn" /><div>{{ $errors->first('document') }}</div></div>@endif

    @if (! $current)
        <div class="upload" id="upload">
            <h2>Upload the agreement</h2>
            <p class="muted" style="margin-bottom:16px">A PDF is read here, discussed in the margin and signed on the page. Everyone invited sees it; nobody else does.</p>
            @if ($can('manage_documents'))
                <form method="post" action="{{ route('documents.upload', $engagement) }}" enctype="multipart/form-data">
                    @csrf
                    <div class="field"><label for="file">PDF file</label><input class="input" id="file" type="file" name="file" accept="application/pdf" required><span class="hint">Up to {{ config('engage.max_upload_kb') / 1024 }} MB. Password-protected PDFs cannot be signed.</span>@error('file')<span class="err">{{ $message }}</span>@enderror</div>
                    <div class="field"><label for="dtitle">Document name</label><input class="input" id="dtitle" name="title" maxlength="160" value="{{ old('title', $engagement->title) }}"></div>
                    <button class="btn" type="submit"><span>Upload</span></button>
                </form>
            @else
                <p class="muted">The owner has not uploaded the agreement yet. You will be notified when it is here.</p>
            @endif
        </div>
    @else
        <div class="docbar">
            <div class="meta">
                <h2 style="font-size:var(--t-md)">{{ $current->title }}</h2>
                @if ($docs->count() > 1)
                    <form method="get" class="versions"><label class="sr-only" for="docsel">Document</label>
                        <select class="select" id="docsel" name="doc" onchange="this.form.submit()">@foreach ($docs as $d)<option value="{{ $d->id }}" @selected($d->id === $current->id)>{{ $d->title }}</option>@endforeach</select></form>
                @endif
                @php($versions = $current->versions()->orderBy('version_number')->get())
                <form method="get" class="versions">
                    <input type="hidden" name="doc" value="{{ $current->id }}">
                    <label for="vsel">Version</label>
                    <select class="select" id="vsel" name="v" onchange="this.form.submit()">
                        @foreach ($versions->reverse() as $v)<option value="{{ $v->id }}" @selected((int) request('v', $versions->last()->id) === $v->id)>v{{ $v->version_number }}{{ $v->kind === 'signed' ? ' signed' : '' }}{{ $loop->first ? ' (working)' : '' }}</option>@endforeach
                    </select>
                </form>
                @if ($versions->count() > 1)<a href="{{ route('documents.compare', [$engagement, $current]) }}">Compare versions</a>@endif
                <a href="{{ route('documents.file', [$engagement, $current, 'v' => request('v', $versions->last()->id), 'download' => 1]) }}">Download</a>
            </div>
            @if ($can('manage_documents') && ! $current->isLocked())
                <div class="page-actions">
                    <button type="button" class="btn btn-quiet btn-sm" data-open-dialog="version-dialog"><span>Upload a new version</span></button>
                    @if ($can('manage_documents') && $docs->count() < 8)<button type="button" class="btn btn-quiet btn-sm" data-open-dialog="adddoc-dialog"><span>Add another document</span></button>@endif
                </div>
            @endif
        </div>

        <div id="doc-root" data-config='{{ json_encode($viewerConfig, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) }}'>
            <p class="empty" aria-live="polite"><span class="spinner" style="display:inline-block;vertical-align:-2px"></span> Opening the agreement…</p>
            <noscript><p class="empty">The agreement viewer needs JavaScript. <a href="{{ route('documents.file', [$engagement, $current, 'download' => 1]) }}">Download the PDF</a> instead.</p></noscript>
        </div>

        @if ($can('manage_documents') && ! $current->isLocked())
            <dialog class="sheet" id="version-dialog" aria-labelledby="version-title">
                <form method="post" action="{{ route('documents.version', [$engagement, $current]) }}" enctype="multipart/form-data">
                    @csrf
                    <div class="sheet-head"><h2 id="version-title">Upload a new version</h2><button type="button" class="iconbtn" data-close-dialog aria-label="Close"><x-icon name="x" /></button></div>
                    <div class="sheet-body">
                        <p class="muted" style="margin-bottom:14px">The earlier version stays as it is. Approvals start again for the new one, and open notes stay open.</p>
                        <div class="field"><label for="vfile">PDF file</label><input class="input" id="vfile" type="file" name="file" accept="application/pdf" required></div>
                        <div class="field"><label for="vreason">What changed</label><textarea class="textarea" id="vreason" name="reason" required maxlength="500" placeholder="Rate card updated, clause 4.2 reworded"></textarea></div>
                        <label class="check"><input type="checkbox" name="incorporates" value="1" checked> This version includes the changes that were accepted</label>
                    </div>
                    <div class="sheet-foot"><button type="button" class="btn btn-quiet" data-close-dialog><span>Cancel</span></button><button class="btn" type="submit"><span>Upload version</span></button></div>
                </form>
            </dialog>
            <dialog class="sheet" id="adddoc-dialog" aria-labelledby="adddoc-title">
                <form method="post" action="{{ route('documents.upload', $engagement) }}" enctype="multipart/form-data">
                    @csrf
                    <div class="sheet-head"><h2 id="adddoc-title">Add another document</h2><button type="button" class="iconbtn" data-close-dialog aria-label="Close"><x-icon name="x" /></button></div>
                    <div class="sheet-body">
                        <div class="field"><label for="afile">PDF file</label><input class="input" id="afile" type="file" name="file" accept="application/pdf" required></div>
                        <div class="field"><label for="atitle">Document name</label><input class="input" id="atitle" name="title" maxlength="160" placeholder="Schedule 2: rate card"></div>
                    </div>
                    <div class="sheet-foot"><button type="button" class="btn btn-quiet" data-close-dialog><span>Cancel</span></button><button class="btn" type="submit"><span>Add document</span></button></div>
                </form>
            </dialog>
        @endif
        @push('scripts')@vite('resources/js/viewer/viewer.js')@endpush
    @endif
