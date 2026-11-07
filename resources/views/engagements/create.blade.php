<x-app-layout title="New engagement">
    <div class="frame-narrow">
        <div class="page-head">
            <div>
                <h1>New engagement</h1>
                <p class="lede">Name the agreement and the other party. You add the document and the people on the next screens.</p>
            </div>
        </div>

        <form method="post" action="{{ route('engagements.store') }}">
            @csrf
            @if ($template)<input type="hidden" name="template" value="{{ $template->id }}"><p class="flash" style="margin-bottom:18px"><x-icon name="doc" /> <span>Starting from the template <b>{{ $template->title }}</b>. Its document is added as the first version.</span></p>@endif
            <div class="field">
                <label for="title">What are you agreeing?</label>
                <input class="input" id="title" name="title" value="{{ old('title', $template?->title) }}" required maxlength="160" autofocus placeholder="Supply agreement 2026">
                @error('title')<span class="err">{{ $message }}</span>@enderror
            </div>
            <div class="row-2">
                <div class="field">
                    <label for="kind">Kind of agreement</label>
                    <select class="select" id="kind" name="kind">
                        @foreach ($kinds as $value => $label)<option value="{{ $value }}" @selected(old('kind', 'general') === $value)>{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="counterparty">The other party</label>
                    <input class="input" id="counterparty" name="counterparty" value="{{ old('counterparty') }}" required maxlength="160" placeholder="Company or person">
                    @error('counterparty')<span class="err">{{ $message }}</span>@enderror
                </div>
            </div>
            <div class="field">
                <label for="description">Context for the other party <span class="muted" style="font-weight:400">(optional)</span></label>
                <textarea class="textarea" id="description" name="description" maxlength="2000">{{ old('description', $template?->description) }}</textarea>
                <span class="hint">They see this in the invitation page. Do not put confidential terms here.</span>
            </div>
            <div class="field" style="max-width:260px">
                <label for="expires_at">Close it if not signed by <span class="muted" style="font-weight:400">(optional)</span></label>
                <input class="input" type="date" id="expires_at" name="expires_at" value="{{ old('expires_at', $template?->expires_in_days ? now()->addDays($template->expires_in_days)->format('Y-m-d') : null) }}">
                @error('expires_at')<span class="err">{{ $message }}</span>@enderror
            </div>
            <div class="page-actions" style="margin-top:8px">
                <button class="btn" type="submit"><span>Create engagement</span></button>
                <a class="btn btn-quiet" href="{{ route('engagements.index') }}"><span>Cancel</span></a>
            </div>
        </form>
    </div>
</x-app-layout>
