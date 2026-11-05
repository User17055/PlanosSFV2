<?php

namespace App\Models;

use App\Http\Controllers\EmployeeController;
use Illuminate\Support\Facades\Route;

/** Cargo: um nível de acesso (o perfil técnico, que é o teto) e as opções liberadas dentro dele, por tópico. */
class JobRole extends DomainModel
{
    /**
     * "tópico.ação" => [nome na tela, rotas]. O tópico é a chave de User::AREAS e "view" abre o tópico.
     * A primeira rota é a principal: dela vem quais níveis podem ter a opção.
     * Telas só da administração central ficam de fora: cargo nunca tem esse nível.
     */
    public const OPTIONS = [
        'dashboard.view' => ['Ver painel, com valores de vendas e recebimentos', ['dashboard', 'dashboard.map', 'dashboard.neighborhoods']],
        'dashboard.export' => ['Exportar resumo', ['dashboard.export']],
        'leads.view' => ['Ver lista e painel de leads', ['leads.index', 'leads.dashboard']],
        'leads.update' => ['Mudar a situação do lead', ['leads.update']],
        'leads.assign' => ['Escolher com quem o lead fica', ['leads.assign']],
        'leads.destroy' => ['Apagar lead de teste', ['leads.destroy']],
        'customers.view' => ['Ver tutores e pets', ['customers.index', 'customers.show', 'pets.photo']],
        'customers.create' => ['Cadastrar novo tutor', ['customers.create', 'customers.store']],
        'customers.update' => ['Editar tutor e pets, adicionar pets', ['customers.edit', 'customers.update', 'customers.pets.store', 'pets.edit', 'pets.update', 'pets.photo.store']],
        'catalog.view' => ['Ver planos e pacotes', ['catalog.index']],
        'proposals.view' => ['Ver propostas e contratos', ['proposals.index', 'proposals.show', 'proposals.preview-pdf', 'proposals.contract', 'contracts.download', 'sales.proposal']],
        'proposals.create' => ['Criar proposta e enviar link', ['proposals.create', 'proposals.store', 'proposals.registration-link', 'proposals.regenerate-contract', 'proposals.link']],
        'proposals.sign' => ['Assinar no tablet ou em papel', ['proposals.tablet-sign', 'proposals.issue-paper', 'proposals.paper-download', 'proposals.documents.store', 'proposals.selfie']],
        'proposals.cancel' => ['Cancelar proposta', ['proposals.cancel']],
        'proposals.validate' => ['Validar documentos', ['contracts.validate']],
        'proposals.cancel_subscription' => ['Cancelar plano contratado', ['subscriptions.cancel']],
        'finance.view' => ['Ver faturas e pagamentos', ['finance.index']],
        'finance.confirm' => ['Confirmar recebimento', ['finance.confirm']],
        'commissions.view' => ['Ver comissões', ['commissions.index']],
        'commissions.pay' => ['Registrar pagamento de comissão', ['commissions.pay']],
        'reports.view' => ['Ver relatórios', ['reports.index']],
        'reports.export' => ['Exportar relatórios', ['reports.export']],
        'attribution_links.view' => ['Ver links', ['attribution_links.index', 'attribution_links.contract-preview', 'attribution_links.contract-preview-pdf']],
        'attribution_links.manage' => ['Criar e desativar links', ['attribution_links.store', 'attribution_links.revoke']],
        'employees.view' => ['Ver equipe, vendas e comissões', ['employees.index']],
    ];

    private static ?array $optionByRoute = null;

    protected function casts(): array
    {
        return ['permissions' => 'array'];
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    /** @return array<string, string> Níveis que um cargo pode ter: todos, menos a administração central. */
    public static function levels(): array
    {
        return array_diff_key(EmployeeController::ROLES, ['central_admin' => true]);
    }

    /** @return list<string> Níveis que podem ter a opção, lidos da própria rota para a tela nunca oferecer o que a rota recusa. */
    public static function rolesOf(string $option): array
    {
        $roles = array_intersect(array_keys(self::levels()), User::AREAS[explode('.', $option)[0]][2]);
        foreach (Route::getRoutes()->getByName(self::OPTIONS[$option][1][0])->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'role:')) {
                $roles = array_intersect($roles, explode(',', substr($middleware, 5)));
            }
        }
        return array_values($roles);
    }

    /** @return list<string> Só o que o nível permite; marcar uma opção do tópico libera também ver o tópico. */
    public static function clean(array $picked, string $level): array
    {
        $allowed = array_values(array_filter(array_keys(self::OPTIONS), fn ($option) => in_array($level, self::rolesOf($option), true)));
        $chosen = array_intersect($allowed, $picked);
        $topics = array_map(fn ($option) => explode('.', $option)[0].'.view', $chosen);
        return array_values(array_intersect($allowed, [...$chosen, ...$topics]));
    }

    public static function optionOf(string $route): ?string
    {
        self::$optionByRoute ??= collect(self::OPTIONS)->flatMap(fn ($option, $key) => array_fill_keys($option[1], $key))->all();
        return self::$optionByRoute[$route] ?? null;
    }

    public function allows(string $option): bool
    {
        return in_array($option, $this->permissions ?? [], true);
    }

    public function allowsRoute(string $route): bool
    {
        $option = self::optionOf($route);
        return $option !== null && $this->allows($option);
    }
}
