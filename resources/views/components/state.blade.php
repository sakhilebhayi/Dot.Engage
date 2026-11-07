@props(['state'])
@php
    $tone = match ($state->value) {
        'negotiation', 'awaiting_amendments', 'awaiting_approval', 'awaiting_participants', 'invitation_sent' => 'is-warn',
        'ready_for_signing', 'partially_signed', 'fully_signed', 'completed' => 'is-ok',
        'in_discussion' => 'is-ink',
        'declined', 'cancelled', 'expired' => 'is-wax',
        default => '',
    };
@endphp
<span {{ $attributes->class(['state', $tone]) }}>{{ $state->label() }}</span>
