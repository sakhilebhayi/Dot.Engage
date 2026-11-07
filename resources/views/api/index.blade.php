<x-app-layout title="API tokens">
    <div class="frame-narrow tap">
        <div class="page-head">
            <div>
                <h1>API tokens</h1>
                <p class="lede">Tokens let another service act for you in Dot.Engage. Create one per service so you can remove it on its own.</p>
            </div>
        </div>

        @livewire('api.api-token-manager')
    </div>
</x-app-layout>
