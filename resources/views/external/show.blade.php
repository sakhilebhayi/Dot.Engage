<x-guest-layout>
    <div class="max-w-2xl mx-auto px-4 py-10">
        <div class="bg-[var(--panel)] border border-[var(--line)] rounded-2xl p-8 space-y-6">
            <div>
                <p class="text-xs uppercase tracking-wide text-[var(--ink-soft)] font-mono">Contract for signature</p>
                <h1 class="font-display text-2xl font-semibold text-[var(--ink)] mt-1">{{ $contract->title }}</h1>
                @if($contract->description)
                    <p class="text-sm text-[var(--ink-soft)] mt-2">{{ $contract->description }}</p>
                @endif
            </div>

            @if(session('signed') || $signer->status === 'signed')
                <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-lg p-4 text-sm">
                    You signed this contract on {{ $signer->signed_at?->format('jS F Y \a\t g:ia') }}. Thank you!
                </div>
            @endif

            <div class="border border-[var(--line)] rounded-lg overflow-hidden bg-white">
                @if($contract->file_path)
                    <iframe src="{{ $downloadUrl }}"
                            class="w-full" style="min-height: 500px;"></iframe>
                @else
                    <div class="flex items-center justify-center h-48 text-sm text-[var(--ink-soft)]">No document uploaded.</div>
                @endif
            </div>

            @if($signer->status !== 'signed' && ! $signer->isSignable())
                <div class="bg-amber-50 border border-amber-200 text-amber-700 rounded-lg p-4 text-sm">
                    It's not your turn to sign yet — another signer needs to sign this contract first. You'll be able to sign once they have.
                </div>
            @endif

            @if($signer->status !== 'signed' && $signer->isSignable())
                <div x-data="externalSignaturePad()" x-init="init()" class="space-y-3">
                    <p class="text-sm text-[var(--ink-soft)]">Draw your signature below to sign as <strong>{{ $signer->name }}</strong> ({{ $signer->email }}).</p>

                    <canvas id="ext-sig-canvas" width="500" height="150"
                            class="border-2 border-[var(--line)] rounded-md w-full touch-none cursor-crosshair bg-white"
                            @mousedown="startDraw($event)" @mousemove="draw($event)" @mouseup="stopDraw()"
                            @touchstart.prevent="startDraw($event.touches[0])" @touchmove.prevent="draw($event.touches[0])" @touchend="stopDraw()">
                    </canvas>

                    @error('signature_data')
                        <p class="text-sm text-red-600">{{ $message }}</p>
                    @enderror

                    <form method="POST" action="{{ $signActionUrl }}" @submit="beforeSubmit">
                        @csrf
                        <input type="hidden" name="signature_data" x-ref="signatureData">
                        <div class="flex justify-between">
                            <button type="button" @click="clear()" class="text-sm text-[var(--ink-soft)] hover:text-[var(--ink)]">Clear</button>
                            <button type="submit" class="px-4 py-2 text-sm text-white bg-[var(--ink)] rounded-lg hover:bg-[var(--navy-soft)]">
                                Sign Contract
                            </button>
                        </div>
                    </form>
                </div>
            @endif
        </div>
    </div>

    <script>
        function externalSignaturePad() {
            return {
                drawing: false, ctx: null, canvas: null,
                init() {
                    this.canvas = document.getElementById('ext-sig-canvas');
                    this.ctx = this.canvas.getContext('2d');
                    this.ctx.strokeStyle = '#171b3d';
                    this.ctx.lineWidth = 2;
                    this.ctx.lineCap = 'round';
                },
                startDraw(e) {
                    this.drawing = true;
                    const r = this.canvas.getBoundingClientRect();
                    this.ctx.beginPath();
                    this.ctx.moveTo(e.clientX - r.left, e.clientY - r.top);
                },
                draw(e) {
                    if (!this.drawing) return;
                    const r = this.canvas.getBoundingClientRect();
                    this.ctx.lineTo(e.clientX - r.left, e.clientY - r.top);
                    this.ctx.stroke();
                },
                stopDraw() { this.drawing = false; },
                clear() { this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height); },
                beforeSubmit() {
                    this.$refs.signatureData.value = this.canvas.toDataURL('image/png');
                },
            };
        }
    </script>
</x-guest-layout>
