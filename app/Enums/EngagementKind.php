<?php

namespace App\Enums;

enum EngagementKind: string
{
    case Supplier = 'supplier_agreement';
    case Sla = 'service_level_agreement';
    case Employment = 'employment_contract';
    case Nda = 'non_disclosure_agreement';
    case Partnership = 'partnership_agreement';
    case Purchase = 'purchase_agreement';
    case Consultancy = 'consultancy_agreement';
    case Proposal = 'client_proposal';
    case Commercial = 'commercial_contract';
    case Project = 'project_agreement';
    case Mou = 'memorandum_of_understanding';
    case General = 'general';

    public function label(): string
    {
        return match ($this) {
            self::Supplier => 'Supplier agreement',
            self::Sla => 'Service-level agreement',
            self::Employment => 'Employment contract',
            self::Nda => 'Non-disclosure agreement',
            self::Partnership => 'Partnership agreement',
            self::Purchase => 'Purchase agreement',
            self::Consultancy => 'Consultancy agreement',
            self::Proposal => 'Client proposal',
            self::Commercial => 'Commercial contract',
            self::Project => 'Project agreement',
            self::Mou => 'Memorandum of understanding',
            self::General => 'General business agreement',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
