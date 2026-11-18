{{-- R-54: every sign-in failure ends here, in words, with one next step and no automatic redirect. --}}
<x-guest-layout title="Sign-in problem">
    <x-authentication-card :title="$headline" :lede="$body">
        <div class="claim-actions" role="alert">
            @if ($retry)<a class="btn claim-submit" href="{{ $retryUrl }}"><span>Start again</span></a>@endif
            <a class="btn btn-quiet" href="{{ $homeUrl }}"><span>Back to Dot.Engage</span></a>
        </div>
        <p class="claim-help">Reference: {{ $code }}</p>
    </x-authentication-card>
</x-guest-layout>
