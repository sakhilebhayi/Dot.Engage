<?php

namespace App\Support\Ecosystem;

/**
 * The words for "your company does not hold this platform", from what InfoDot said: the `status` of the platform's
 * row in the read endpoint, or the `access` reason of a company in the sign-in answer. Anything unrecognised reads as
 * "not switched on", the plainest true statement.
 */
final class Refusal
{
    /**
     * @return array{title: string, message: string}
     */
    public static function words(?string $reason, ?string $company = null): array
    {
        $who = filled($company) ? $company : 'your company';

        return match ($reason) {
            'suspended' => [
                'title' => 'Dot.Engage is suspended for '.$who,
                'message' => 'Nothing has been removed: the engagements, documents and signed agreements are kept as they were. Whoever manages platforms for the company can see why in InfoDot.',
            ],
            'payment_required' => [
                'title' => 'A payment is needed before '.$who.' can use Dot.Engage',
                'message' => 'Nothing has been removed: the engagements, documents and signed agreements are kept as they were. Whoever manages platforms for the company can settle it in InfoDot.',
            ],
            'subscription_expired', 'expired', 'trial_ended', 'cancelled' => [
                'title' => 'Dot.Engage has ended for '.$who,
                'message' => 'Nothing has been removed: the engagements, documents and signed agreements are kept as they were. Whoever manages platforms for the company can switch it on again in InfoDot.',
            ],
            'not_permitted_for_you' => [
                'title' => 'You have not been given Dot.Engage at '.$who,
                'message' => 'The company chooses who uses this platform. Ask whoever manages platforms for the company to add you in InfoDot.',
            ],
            'no_personal_access' => [
                'title' => 'Dot.Engage needs a company that has switched it on',
                'message' => 'A personal workspace does not include this platform. Create or join a company in InfoDot and switch Dot.Engage on there. Nothing in your personal workspace has been removed.',
            ],
            'temporarily_unavailable', 'not_launched' => [
                'title' => 'Dot.Engage is not available to '.$who.' right now',
                'message' => 'InfoDot shows what is happening with this platform and when it can be opened again.',
            ],
            default => [
                'title' => 'Dot.Engage is not switched on for '.$who,
                'message' => 'Whoever manages platforms for the company can switch it on in InfoDot. Nothing already made here has been removed.',
            ],
        };
    }
}
