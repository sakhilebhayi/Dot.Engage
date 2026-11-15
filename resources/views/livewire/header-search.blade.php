<div>
    <x-dot.search name="q" wire="query" :action="route('engagements.index')" :value="request('q')" placeholder="Find an engagement" label="Find an engagement"
        :results-nav="true" loading-text="Looking in your engagements..." :escape-clears="true"
        :root-attributes="['wire:ignore.self' => '', 'wire:loading.attr' => 'data-dsr-loading', 'wire:target' => 'query']">
        <x-slot:results>
            @if ($term === '')
                <p class="dsr-foot">Type a title, a reference, or the name of a party.</p>
            @else
                @if ($rows->isEmpty())
                    <p class="dsr-foot">Nothing matches &ldquo;{{ $term }}&rdquo;. Try fewer words, a reference, or the other party&rsquo;s name.</p>
                @else
                    <x-dot.search-head>Engagements</x-dot.search-head>
                    @foreach ($rows as $engagement)
                        <x-dot.search-row :href="route('engagements.show', $engagement)" :code="$engagement->reference" :label="$engagement->title" :tag="$engagement->state->label()" />
                    @endforeach
                @endif
                <x-dot.search-row :href="route('engagements.index', ['q' => $term, 'show' => 'all'])" :action="true" :label="'See every result for “'.$term.'”'" />
            @endif
        </x-slot:results>
    </x-dot.search>
</div>
