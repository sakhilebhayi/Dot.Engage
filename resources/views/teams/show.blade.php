<x-app-layout title="Business and members">
    <div class="frame-narrow tap">
        <div class="page-head">
            <div>
                <h1>Business and members</h1>
                <p class="lede">{{ $team->name }}. Who is in this business and what each person can do in it.</p>
            </div>
        </div>

        @livewire('teams.update-team-name-form', ['team' => $team])

        @livewire('teams.team-member-manager', ['team' => $team])

        @if (Gate::check('delete', $team) && ! $team->personal_team)
            @livewire('teams.delete-team-form', ['team' => $team])
        @endif
    </div>
</x-app-layout>
