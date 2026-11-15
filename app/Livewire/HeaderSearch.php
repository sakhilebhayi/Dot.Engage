<?php

namespace App\Livewire;

use App\Models\Engagement;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The live results of the header search. It asks the same question the engagements list asks (Engagement::matching: title,
 * reference or party name) and nothing else: no new entities. The query runs inside the signed-in person's current business
 * only, a person without one gets nothing, and the number of rows is capped. The pill itself still submits a plain GET to the
 * engagements list, so Enter, a phone keyboard and a browser without script behave as before.
 */
class HeaderSearch extends Component
{
    public const LIMIT = 6;

    public string $query = '';

    /** On the engagements list the box keeps the term that produced the list. */
    public function mount(): void
    {
        if (request()->routeIs('engagements.index')) {
            $this->query = mb_substr(trim((string) request()->query('q', '')), 0, 160);
        }
    }

    public function render()
    {
        /** @var User|null $user */
        $user = Auth::user();
        $team = $user?->currentTeam;
        $term = trim($this->query);
        $rows = collect();

        if ($team && $term !== '') {
            $rows = Engagement::where('team_id', $team->id)->matching($term)
                ->orderByDesc('updated_at')->limit(self::LIMIT + 1)->get();
        }

        return view('livewire.header-search', [
            'term' => $term,
            'rows' => $rows->take(self::LIMIT),
            'more' => $rows->count() > self::LIMIT,
        ]);
    }
}
