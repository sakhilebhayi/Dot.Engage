<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Engagement;
use App\Models\Meeting;
use App\Models\Message;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

#[Signature('dotengage:team-activity-report
    {--team= : Restrict report to a specific team ID (omit for all teams)}
    {--month= : Report month as YYYY-MM (defaults to last calendar month)}
    {--output=table : Output format: table or csv}')]
#[Description('Generate a monthly activity summary per team: engagements, completed agreements, messages and meetings.')]
class GenerateTeamActivityReport extends Command
{
    public function handle(): int
    {
        $monthInput = $this->option('month') ?? now()->subMonth()->format('Y-m');

        try {
            $period = Carbon::createFromFormat('Y-m', $monthInput);
        } catch (\Exception) {
            $this->error('Invalid --month format. Use YYYY-MM (e.g. 2026-03).');

            return self::FAILURE;
        }

        $start = $period->copy()->startOfMonth();
        $end = $period->copy()->endOfMonth();
        $teamId = $this->option('team');
        $output = $this->option('output');

        $this->info("Activity report for {$start->format('F Y')}");

        // Collect team IDs to report on.
        $teams = DB::table('teams')
            ->when($teamId, fn ($q) => $q->where('id', $teamId))
            ->get(['id', 'name']);

        if ($teams->isEmpty()) {
            $this->warn('No teams found.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($teams as $team) {
            $contracts = Engagement::withoutGlobalScopes()->where('team_id', $team->id)
                ->whereBetween('created_at', [$start, $end])
                ->count();

            $signed = Engagement::withoutGlobalScopes()->where('team_id', $team->id)
                ->whereBetween('completed_at', [$start, $end])
                ->count();

            $conversationIds = Conversation::where('team_id', $team->id)->pluck('id');

            $messages = Message::whereIn('conversation_id', $conversationIds)
                ->whereBetween('created_at', [$start, $end])
                ->count();

            $sessions = Meeting::whereIn('engagement_id', Engagement::withoutGlobalScopes()->where('team_id', $team->id)->pluck('id'))
                ->whereBetween('created_at', [$start, $end])
                ->count();

            $rows[] = [
                $team->id,
                $team->name,
                $contracts,
                $signed,
                $messages,
                $sessions,
            ];
        }

        $headers = ['Team ID', 'Team Name', 'Engagements Started', 'Agreements Completed', 'Messages Sent', 'Meetings'];

        if ($output === 'csv') {
            $this->line(implode(',', $headers));
            foreach ($rows as $row) {
                $this->line(implode(',', $row));
            }
        } else {
            $this->table($headers, $rows);
        }

        Log::info('GenerateTeamActivityReport: report generated for '.$start->format('Y-m').', '.count($rows).' team(s).');

        return self::SUCCESS;
    }
}
