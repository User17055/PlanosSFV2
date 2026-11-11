<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'areas', 'job_role_id', 'franchise_id', 'customer_id', 'active', 'email_verified_at', 'employee_code', 'phone', 'job_title', 'hired_at', 'monthly_sales_target_cents', 'digisac_user_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    private const STAFF = ['central_admin', 'manager', 'sales', 'operator', 'finance'];

    /**
     * Telas do menu que dá para tirar de cada funcionário: nome, rotas que pertencem à tela e perfis que podem abri-la.
     * O perfil é o limite; a escolha por pessoa só esconde o que o perfil já permite.
     */
    public const AREAS = [
        'dashboard' => ['Visão geral', ['dashboard', 'dashboard.*'], self::STAFF],
        'leads' => ['Leads', ['leads.*'], ['central_admin', 'manager', 'sales', 'operator', 'attendant']],
        'customers' => ['Tutores e pets', ['customers.*', 'pets.*'], self::STAFF],
        'catalog' => ['Planos e pacotes', ['catalog.*'], self::STAFF],
        'proposals' => ['Propostas', ['proposals.*', 'contracts.*', 'subscriptions.*'], self::STAFF],
        'finance' => ['Financeiro', ['finance.*'], ['central_admin', 'manager', 'finance']],
        'commissions' => ['Comissões', ['commissions.*'], ['central_admin', 'manager', 'finance', 'sales']],
        'reports' => ['Relatórios', ['reports.*'], ['central_admin', 'manager', 'finance']],
        'attribution_links' => ['Links de divulgação', ['attribution_links.index', 'attribution_links.store', 'attribution_links.revoke'], ['central_admin', 'manager', 'sales']],
        'integrations' => ['Integrações', ['integrations.*'], ['central_admin']],
        'employees' => ['Funcionários', ['employees.*'], ['central_admin', 'manager', 'finance']],
        'management' => ['Rede e acessos', ['management.*'], ['central_admin']],
    ];

    /** @return list<string> */
    public static function areasFor(string $role): array
    {
        return array_keys(array_filter(self::AREAS, fn ($area) => in_array($role, $area[2], true)));
    }

    public function canOpen(string $area): bool
    {
        if ($this->job_role_id && isset(self::AREAS[$area])) return (bool) $this->jobRole?->allows($area.'.view');
        return ! isset(self::AREAS[$area]) || $this->areas === null || in_array($area, $this->areas, true);
    }

    public function jobRole()
    {
        return $this->belongsTo(JobRole::class);
    }

    /** Com cargo, vale só o que ele libera. Sem cargo, quem decide é o perfil, nas rotas. */
    public function canUse(string $route): bool
    {
        return ! $this->job_role_id || (bool) $this->jobRole?->allowsRoute($route);
    }

    public function franchise()
    {
        return $this->belongsTo(Franchise::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function franchises()
    {
        return $this->belongsToMany(Franchise::class, 'user_franchises')->withPivot('active', 'permissions');
    }

    public function hasFranchise(int $id): bool
    {
        return $this->active && Franchise::whereKey($id)->where('active', true)->exists() && ($this->role === 'central_admin' || $this->franchises()->where('franchises.id', $id)->wherePivot('active', true)->exists());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
            'areas' => 'array',
            'hired_at' => 'date',
            'monthly_sales_target_cents' => 'integer',
        ];
    }
}
