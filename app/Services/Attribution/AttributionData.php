<?php

namespace App\Services\Attribution;

final readonly class AttributionData
{
    public const FIELDS = ['acquisition_source', 'marketing_link_id', 'link_owner_id', 'referrer_customer_id', 'referrer_name', 'campaign_name', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'first_touch_id', 'last_touch_id', 'first_touch_at', 'last_touch_at', 'attribution_snapshot'];

    /** Built by AttributionService after resolving server-owned link and session records. */
    public function __construct(public array $attributes, public ?int $originatingFranchiseId = null, public ?int $productVersionId = null) {}

    public function proposalAttributes(): array
    {
        return array_intersect_key($this->attributes, array_flip(self::FIELDS));
    }
}
