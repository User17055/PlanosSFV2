<?php

namespace App\Services\Attribution;

use App\Models\AttributionTouch;
use App\Models\MarketingLink;
use App\Models\User;
use App\Services\AccessService;
use App\Services\Domain\DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AttributionService
{
    public const SESSION_KEY = 'sf_attribution';

    public function __construct(private MarketingLinkService $links) {}

    /** Captures an entry, not a sale or an identified person. Full URLs, IPs and click IDs are never persisted. */
    public function capture(Request $request, ?MarketingLink $link = null): AttributionTouch
    {
        if (! $request->hasSession()) {
            throw new DomainException('ATTRIBUTION_SESSION_REQUIRED', 'Sessão necessária para registrar a origem.', 409);
        }
        if ($link && ! $this->links->isUsable($link)) {
            throw new DomainException('MARKETING_LINK_UNAVAILABLE', 'Link indisponível ou expirado.', 404);
        }
        $session = $request->session()->get(self::SESSION_KEY, []);
        if (! is_array($session)) {
            $session = [];
        }
        $visitor = is_string($session['visitor_key'] ?? null) ? $session['visitor_key'] : Str::random(48);
        $hash = hash_hmac('sha256', $visitor, (string) config('app.key'));
        $first = isset($session['first_touch_id']) ? AttributionTouch::whereKey($session['first_touch_id'])->where('session_key_hash', $hash)->first() : null;
        $last = isset($session['last_touch_id']) ? AttributionTouch::whereKey($session['last_touch_id'])->where('session_key_hash', $hash)->first() : null;
        $host = TrackingSanitizer::referrerHost($request->headers->get('referer'));
        if ($host === strtolower($request->getHost())) {
            $host = null;
        }
        $utm = [];
        foreach (TrackingSanitizer::UTM_FIELDS as $field) {
            $utm[$field] = TrackingSanitizer::utm($request->query($field));
        }
        // Reloading an ordinary sales page must not erase an earlier meaningful campaign.
        if (! $link && $last && ! $host && ! array_filter($utm, fn ($v) => $v !== null)) {
            return $last;
        }
        $attributes = ['marketing_link_id' => $link?->id, 'session_key_hash' => $hash,
            'acquisition_source' => $link?->acquisition_source ?? TrackingSanitizer::source($utm['utm_source'], $host),
            'owner_id' => $link?->owner_id, 'originating_franchise_id' => $link?->originating_franchise_id,
            'product_version_id' => $link?->product_version_id, 'referrer_customer_id' => $link?->referrer_customer_id,
            'referrer_name' => $link?->referrer_name, 'campaign_name' => $link?->campaign_name ?? $utm['utm_campaign'],
            'referrer_host' => $host, 'touched_at' => now()];
        foreach (TrackingSanitizer::UTM_FIELDS as $field) {
            $attributes[$field] = $link?->{$field} ?? $utm[$field];
        }
        $attributes['metadata_snapshot'] = $this->touchMetadata($attributes) + ['marketing_link_name' => $link?->name];
        $touch = AttributionTouch::create($attributes);
        $request->session()->put(self::SESSION_KEY, ['visitor_key' => $visitor, 'first_touch_id' => $first?->id ?? $touch->id, 'last_touch_id' => $touch->id]);

        return $touch;
    }

    /** HTTP input may declare a source/referrer; ownership and unit always come from authorized server records. */
    public function proposalData(Request $request, array $manual, User $actor): AttributionData
    {
        $manual = Validator::make($manual, [
            'acquisition_source' => 'nullable|in:direct,referral,shared_link,instagram,facebook,google,other',
            'referrer_customer_id' => 'nullable|integer|exists:customers,id', 'referrer_name' => 'nullable|string|max:180',
            'marketing_link_id' => 'nullable|integer|exists:marketing_links,id', 'campaign_name' => 'nullable|string|max:120',
            'utm_source' => 'nullable|string|max:120', 'utm_medium' => 'nullable|string|max:120', 'utm_campaign' => 'nullable|string|max:120',
            'utm_content' => 'nullable|string|max:120', 'utm_term' => 'nullable|string|max:120',
        ])->validate();
        $first = null;
        $last = null;
        $link = null;
        if ($actor->role === 'customer') {
            if (! empty($manual['marketing_link_id']) || ! empty($manual['referrer_customer_id'])) {
                throw new DomainException('ATTRIBUTION_REFERENCE_FORBIDDEN', 'Identificadores de divulgação ou de terceiros não podem ser definidos pelo navegador.', 403);
            }
            [$first,$last] = $this->sessionTouches($request);
            if ($last?->marketing_link_id) {
                $candidate = $last->marketingLink;
                if ($candidate && $this->links->isUsable($candidate)) {
                    $link = $candidate;
                }
            }
        } else {
            if (! $actor->active || ! in_array($actor->role, ['central_admin', 'manager', 'sales'], true)) {
                throw new DomainException('FORBIDDEN', 'Perfil sem permissão para registrar a origem comercial.', 403);
            }
            // An employee previewing a campaign must never attribute the next customer's purchase to that preview.
            if (! empty($manual['marketing_link_id'])) {
                $link = MarketingLink::findOrFail($manual['marketing_link_id']);
                $this->links->authorize($link, $actor);
                if (! $this->links->isUsable($link)) {
                    throw new DomainException('MARKETING_LINK_UNAVAILABLE', 'Link indisponível ou expirado.', 422);
                }
            }
        }
        $profile=$actor->role==='customer'?(array)$actor->customer?->acquisition_profile:[];
        $source = $link?->acquisition_source ?? $last?->acquisition_source ?? ($profile['acquisition_source']??'unknown');
        $declared = $manual['acquisition_source'] ?? null;
        if ($declared !== null && $declared !== '') {
            $source = $declared;
        }
        if(!$declared && filled($manual['referrer_name']??null) && in_array($source,['unknown','direct'],true))$source='referral';
        // If a link has ceased to be authorized, retain the touch history without using its sales assignment.
        if ($last?->marketing_link_id && ! $link && ! $declared) {
            $source = 'unknown';
        }
        $referrer = null;
        if (! empty($manual['referrer_customer_id'])) {
            $referrer = app(AccessService::class)->customerQuery($actor)->whereKey($manual['referrer_customer_id'])->firstOrFail();
        }
        if ($referrer && $actor->role === 'customer' && $referrer->id === $actor->customer_id) {
            throw new DomainException('SELF_REFERRAL_INVALID', 'A indicação deve identificar outra pessoa.', 422);
        }
        $attributes = ['acquisition_source' => $source, 'marketing_link_id' => $link?->id, 'link_owner_id' => $link?->owner_id,
            'referrer_customer_id' => $referrer?->id ?? $link?->referrer_customer_id,
            'referrer_name' => $referrer?->name
                ?? ($link?->referrer_customer_id ? ($link->referrer_name ?? $link->referrerCustomer?->name) : null)
                ?? TrackingSanitizer::text($manual['referrer_name'] ?? null, 180) ?? $link?->referrer_name ?? (array_key_exists('referrer_name',$manual)?null:($profile['referrer_name']??null)),
            'campaign_name' => TrackingSanitizer::text($manual['campaign_name'] ?? null) ?? $link?->campaign_name ?? $last?->campaign_name ?? ($profile['campaign_name']??null),
            'first_touch_id' => $first?->id, 'last_touch_id' => $last?->id, 'first_touch_at' => $first?->touched_at, 'last_touch_at' => $last?->touched_at];
        foreach (TrackingSanitizer::UTM_FIELDS as $field) {
            $attributes[$field] = TrackingSanitizer::utm($manual[$field] ?? null) ?? $link?->{$field} ?? $last?->{$field};
        }
        $snapshot = ['version' => 1, 'model' => $declared || filled($manual['referrer_name']??null) ? 'declared' : ($last?'last_touch':($profile?'customer_profile':'last_touch')), 'recorded_at' => now()->toIso8601String(),
            'acquisition_source' => $source, 'marketing_link_id' => $link?->id, 'marketing_link_name' => $link?->name, 'link_owner_id' => $link?->owner_id,
            'link_owner_name' => $link?->owner?->name, 'referrer_customer_id' => $attributes['referrer_customer_id'], 'referrer_name' => $attributes['referrer_name'],
            'campaign_name' => $attributes['campaign_name'], 'utm' => array_intersect_key($attributes, array_flip(TrackingSanitizer::UTM_FIELDS)),
            'first_touch' => $first ? $this->touchMetadata($first->toArray()) + ['id' => $first->id] : null,
            'last_touch' => $last ? $this->touchMetadata($last->toArray()) + ['id' => $last->id] : null,
            'recorded_by' => $actor->id];
        $attributes['attribution_snapshot'] = $snapshot;

        return new AttributionData($attributes, $link?->originating_franchise_id, $link?->product_version_id);
    }

    private function sessionTouches(Request $request): array
    {
        if (! $request->hasSession()) {
            return [null, null];
        }
        $state = $request->session()->get(self::SESSION_KEY, []);
        if (! is_array($state)) {
            return [null, null];
        }
        if (! is_string($state['visitor_key'] ?? null)) {
            return [null, null];
        }
        $hash = hash_hmac('sha256', $state['visitor_key'], (string) config('app.key'));
        $first = AttributionTouch::whereKey($state['first_touch_id'] ?? 0)->where('session_key_hash', $hash)->first();
        $last = AttributionTouch::whereKey($state['last_touch_id'] ?? 0)->where('session_key_hash', $hash)->first();

        return [$first, $last];
    }

    private function touchMetadata(array $attributes): array
    {
        return array_intersect_key($attributes, array_flip(['marketing_link_id', 'acquisition_source', 'owner_id', 'originating_franchise_id', 'product_version_id', 'referrer_customer_id', 'referrer_name', 'campaign_name', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'referrer_host', 'touched_at']));
    }
}
