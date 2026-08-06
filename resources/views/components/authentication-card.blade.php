<div class="relative min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 overflow-hidden">
    {{-- Same hero photo as welcome.blade.php (a man signing a document with a pen, Jakub
    Żerdzicki), with the same dark-ink scrim treatment the hero itself already proves works on
    this brand. --}}
    <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1752650733337-cb0189176fb9?q=80&w=2400&auto=format&fit=crop');"></div>
    <div class="absolute inset-0" style="background: radial-gradient(ellipse 68% 62% at 50% 40%, rgba(23,27,61,0.9) 0%, rgba(23,27,61,0.68) 45%, rgba(23,27,61,0.35) 74%, rgba(23,27,61,0.12) 100%);"></div>
    <div class="absolute inset-0" style="background: linear-gradient(180deg, rgba(23,27,61,0.6) 0%, transparent 18%, transparent 74%, rgba(23,27,61,0.5) 100%);"></div>

    <!-- Logo -->
    <div class="relative z-10 mb-6">
        {{ $logo }}
    </div>

    <!-- Card -->
    <div class="relative z-10 w-full sm:max-w-md">
        <div class="bg-[var(--panel)] border border-[var(--line)] rounded-2xl shadow-[0_30px_60px_-30px_rgba(0,0,0,0.5)] overflow-hidden">
            <div class="px-8 py-8">
                {{ $slot }}
            </div>
        </div>
        <p class="mt-6 text-center font-mono text-xs tracking-wide text-[rgba(245,245,242,0.8)]">
            &copy; {{ date('Y') }} dot.engage &middot; All rights reserved
        </p>
    </div>
</div>
