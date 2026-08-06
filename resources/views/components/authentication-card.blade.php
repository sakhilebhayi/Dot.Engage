<div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-[var(--paper)]">
    <!-- Logo -->
    <div class="mb-6">
        {{ $logo }}
    </div>

    <!-- Card -->
    <div class="w-full sm:max-w-md">
        <div class="bg-[var(--panel)] border border-[var(--line)] rounded-2xl shadow-[0_30px_60px_-30px_rgba(23,27,61,0.25)] overflow-hidden">
            <div class="px-8 py-8">
                {{ $slot }}
            </div>
        </div>
        <p class="mt-6 text-center font-mono text-xs tracking-wide text-[var(--ink-soft)]">
            &copy; {{ date('Y') }} dot.engage &middot; All rights reserved
        </p>
    </div>
</div>
