<?php

namespace App\Services\Attribution;

use App\Models\Franchise;
use App\Models\MarketingLink;
use App\Models\ProductVersion;
use App\Models\User;
use App\Services\AccessService;
use App\Services\Domain\Audit;
use App\Services\Domain\DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class MarketingLinkService
{
    public function scopeFor(User $actor): Builder
    {
        $this->staff($actor);
        $query = MarketingLink::query();
        if ($actor->role === 'central_admin') {
            return $query;
        }
        $ids = $actor->franchises()->where('franchises.active', true)->wherePivot('active', true)->pluck('franchises.id')->all();
        $query->whereIn('originating_franchise_id', $ids);
        if ($actor->role === 'sales') {
            $query->where(fn ($q) => $q->where('owner_id', $actor->id)->orWhere('created_by', $actor->id));
        }

        return $query;
    }

    /** The raw token is returned once here and remains encrypted for authorized later copies. */
    public function create(array $data, User $actor): array
    {
        $this->staff($actor);
        $data = Validator::make($data, [
            'name' => 'required|string|max:120', 'originating_franchise_id' => 'required|integer|exists:franchises,id',
            'owner_id' => 'nullable|integer|exists:users,id', 'product_version_id' => 'nullable|integer|exists:product_versions,id',
            'acquisition_source' => 'nullable|in:shared_link,referral,instagram,facebook,google,other',
            'referrer_customer_id' => 'nullable|integer|exists:customers,id', 'referrer_name' => 'nullable|string|max:180',
            'campaign_name' => 'nullable|string|max:120', 'utm_source' => 'nullable|string|max:120', 'utm_medium' => 'nullable|string|max:120',
            'utm_campaign' => 'nullable|string|max:120', 'utm_content' => 'nullable|string|max:120', 'utm_term' => 'nullable|string|max:120',
            'expires_at' => 'nullable|date|after:now',
            'self_registration' => 'nullable|boolean',
        ])->validate();
        $franchise = Franchise::findOrFail($data['originating_franchise_id']);
        if (! $actor->hasFranchise($franchise->id)) {
            throw new DomainException('FORBIDDEN', 'Unidade não autorizada para este link.', 403);
        }
        $owner = User::findOrFail($data['owner_id'] ?? $actor->id);
        if (! $owner->active || ! in_array($owner->role, ['central_admin', 'manager', 'sales'], true) || ! $owner->hasFranchise($franchise->id)
            || ($actor->role === 'sales' && $owner->id !== $actor->id)) {
            throw new DomainException('LINK_OWNER_FORBIDDEN', 'Responsável não autorizado nesta unidade.', 403);
        }
        if (! empty($data['product_version_id'])) {
            $version = ProductVersion::with('product')->findOrFail($data['product_version_id']);
            if (!$version->isOffered()) {
                throw new DomainException('PRODUCT_UNAVAILABLE', 'Produto indisponível para novas ofertas.', 422);
            }
        }
        $referrer = null;
        if (! empty($data['referrer_customer_id'])) {
            $referrer = app(AccessService::class)->customerQuery($actor)->whereKey($data['referrer_customer_id'])->firstOrFail();
        }
        $token = Str::random(64);
        $attributes = ['name' => TrackingSanitizer::text($data['name'], 120), 'token_hash' => hash('sha256', $token), 'token_encrypted' => $token,
            'acquisition_source' => $data['acquisition_source'] ?? 'shared_link', 'owner_id' => $owner->id, 'created_by' => $actor->id,
            'originating_franchise_id' => $franchise->id, 'product_version_id' => $data['product_version_id'] ?? null,
            'referrer_customer_id' => $referrer?->id, 'referrer_name' => $referrer?->name ?? TrackingSanitizer::text($data['referrer_name'] ?? null, 180),
            'campaign_name' => TrackingSanitizer::text($data['campaign_name'] ?? null), 'status' => 'active', 'expires_at' => $data['expires_at'] ?? null];
        $attributes['self_registration'] = (bool) ($data['self_registration'] ?? false);
        foreach (TrackingSanitizer::UTM_FIELDS as $field) {
            $attributes[$field] = TrackingSanitizer::utm($data[$field] ?? null);
        }

        return DB::transaction(function () use ($attributes, $actor, $token) {
            $link = MarketingLink::create($attributes);
            Audit::record('marketing_link_created', $link, $actor, ['source' => $link->acquisition_source, 'owner_id' => $link->owner_id], null, $link->originating_franchise_id);

            return ['link' => $link, 'token' => $token];
        });
    }

    public function resolve(string $token): MarketingLink
    {
        if (! preg_match('/^[A-Za-z0-9]{64}$/D', $token)) {
            throw new DomainException('MARKETING_LINK_UNAVAILABLE', 'Link indisponível.', 404);
        }
        $link = MarketingLink::where('token_hash', hash('sha256', $token))->first();
        if (! $link || ! $this->isUsable($link)) {
            throw new DomainException('MARKETING_LINK_UNAVAILABLE', 'Link indisponível ou expirado.', 404);
        }

        return $link;
    }

    public function isUsable(MarketingLink $link): bool
    {
        return $link->status === 'active' && (! $link->expires_at || $link->expires_at->isFuture())
            && $link->originatingFranchise?->active && $link->owner?->active
            && in_array($link->owner->role, ['central_admin', 'manager', 'sales'], true)
            && $link->owner->hasFranchise($link->originating_franchise_id);
    }

    public function urlToken(MarketingLink $link, User $actor): string
    {
        $this->authorize($link, $actor);

        return $link->token_encrypted;
    }

    public function revoke(MarketingLink $link, User $actor): MarketingLink
    {
        $this->authorize($link, $actor);
        if ($link->status === 'revoked') {
            return $link;
        }

        return DB::transaction(function () use ($link, $actor) {
            $link->update(['status' => 'revoked']);
            Audit::record('marketing_link_revoked', $link, $actor, [], null, $link->originating_franchise_id);

            return $link->fresh();
        });
    }

    public function authorize(MarketingLink $link, User $actor): void
    {
        if (! $this->scopeFor($actor)->whereKey($link->id)->exists()) {
            throw new DomainException('FORBIDDEN', 'Link fora do seu escopo de acesso.', 403);
        }
    }

    private function staff(User $actor): void
    {
        if (! $actor->active || ! in_array($actor->role, ['central_admin', 'manager', 'sales'], true)) {
            throw new DomainException('FORBIDDEN', 'Seu perfil não pode administrar links de divulgação.', 403);
        }
    }
}
