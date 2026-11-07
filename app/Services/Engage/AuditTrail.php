<?php

namespace App\Services\Engage;

use App\Models\Engagement;
use App\Models\EngagementAuditEvent;
use App\Support\Engage\Actor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Append-only, hash-chained history of everything that matters in an engagement.
 *
 * Each row stores the hash of the row before it in the same engagement, so removing or rewriting a row
 * (even with direct database access, past the triggers that already refuse UPDATE and DELETE) breaks the chain,
 * and verify() reports the first row where it breaks.
 */
class AuditTrail
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * @param  array<string, mixed>  $data  small, JSON-safe facts about the event. Never put secrets or whole documents here.
     */
    public function record(Engagement|int $engagement, string $event, Actor $actor, ?Model $subject = null, array $data = []): EngagementAuditEvent
    {
        $engagementId = $engagement instanceof Engagement ? $engagement->id : $engagement;

        return DB::transaction(function () use ($engagementId, $event, $actor, $subject, $data) {
            $prev = EngagementAuditEvent::query()
                ->where('engagement_id', $engagementId)
                ->orderByDesc('id')
                ->value('hash') ?? self::GENESIS;

            $occurredAt = now()->format('Y-m-d H:i:s.u');
            $row = [
                'engagement_id' => $engagementId,
                'event' => $event,
                'actor_type' => $actor->type,
                'actor_id' => $actor->id,
                'actor_label' => $actor->label,
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey(),
                'data' => $data === [] ? null : $data,
                'ip' => $actor->ip,
                'user_agent' => $actor->userAgent,
                'occurred_at' => $occurredAt,
                'prev_hash' => $prev,
            ];
            $row['hash'] = self::hashRow($row);

            return EngagementAuditEvent::create($row);
        });
    }

    /** @param array<string, mixed> $row */
    public static function hashRow(array $row): string
    {
        $data = $row['data'] ?? null;

        return hash('sha256', implode('|', [
            $row['prev_hash'],
            $row['engagement_id'],
            $row['event'],
            $row['actor_type'],
            $row['actor_id'] ?? '',
            $row['actor_label'],
            $row['subject_type'] ?? '',
            $row['subject_id'] ?? '',
            $data === null ? '' : self::canonicalJson($data),
            $row['ip'] ?? '',
            $row['user_agent'] ?? '',
            $row['occurred_at'],
        ]));
    }

    /**
     * @return array{ok: bool, count: int, broken_at: ?int}
     */
    public function verify(Engagement|int $engagement): array
    {
        $engagementId = $engagement instanceof Engagement ? $engagement->id : $engagement;
        $prev = self::GENESIS;
        $count = 0;

        foreach (EngagementAuditEvent::query()->where('engagement_id', $engagementId)->orderBy('id')->cursor() as $event) {
            $count++;
            $expected = self::hashRow([
                'prev_hash' => $prev,
                'engagement_id' => $event->engagement_id,
                'event' => $event->event,
                'actor_type' => $event->actor_type,
                'actor_id' => $event->actor_id,
                'actor_label' => $event->actor_label,
                'subject_type' => $event->subject_type,
                'subject_id' => $event->subject_id,
                'data' => $event->data,
                'ip' => $event->ip,
                'user_agent' => $event->user_agent,
                'occurred_at' => $event->getRawOriginal('occurred_at'),
            ]);

            if ($event->prev_hash !== $prev || $event->hash !== $expected) {
                return ['ok' => false, 'count' => $count, 'broken_at' => $event->id];
            }

            $prev = $event->hash;
        }

        return ['ok' => true, 'count' => $count, 'broken_at' => null];
    }

    /** Hash of the newest event: pins the whole history up to now (used inside the evidence record). */
    public function head(Engagement|int $engagement): string
    {
        $engagementId = $engagement instanceof Engagement ? $engagement->id : $engagement;

        return EngagementAuditEvent::query()->where('engagement_id', $engagementId)->orderByDesc('id')->value('hash') ?? self::GENESIS;
    }

    public static function canonicalJson(mixed $value): string
    {
        return json_encode(self::sortKeys($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($v) => self::sortKeys($v), $value);
    }
}
