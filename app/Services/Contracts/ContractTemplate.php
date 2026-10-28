<?php

namespace App\Services\Contracts;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** Plain text replacement only: contract text is never evaluated as Blade or PHP. */
class ContractTemplate
{
    public function values(array $snapshot, array $context = []): array
    {
        $get = fn (string $key) => data_get($snapshot, $key) ?: 'Não informado';
        $address = data_get($snapshot, 'customer.address', []);
        $address = is_array($address) ? $address : ['formatted' => $address];
        $birth = data_get($snapshot, 'pet.birth_date');
        $date = !empty($context['accepted_at']) ? CarbonImmutable::parse($context['accepted_at'])->timezone('America/Sao_Paulo') : null;
        $cpf = (string) data_get($snapshot, 'customer.document', '');
        if (preg_match('/^\d{11}$/', $cpf)) {
            $cpf = substr($cpf, 0, 3).'.'.substr($cpf, 3, 3).'.'.substr($cpf, 6, 3).'-'.substr($cpf, 9);
        }
        return [
            'contratante_nome' => $get('customer.name'), 'contratante_cpf' => $cpf ?: 'Não informado',
            'contratante_rg' => $get('customer.rg'), 'contratante_email' => $get('customer.email'),
            'contratante_telefone' => $get('customer.phone'),
            'contratante_endereco' => ($address['formatted'] ?? null) ?: implode(', ', array_filter([$address['street'] ?? null, $address['number'] ?? null, $address['complement'] ?? null, $address['neighborhood'] ?? null])) ?: 'Não informado',
            'contratante_cidade' => ($address['city'] ?? null) ?: 'Não informado',
            'contratante_uf' => ($address['state'] ?? null) ?: 'Não informado',
            'contratante_cep' => ($address['zip'] ?? $address['cep'] ?? $address['postal_code'] ?? null) ?: 'Não informado',
            'pet_nome' => $get('pet.name'), 'pet_raca' => $get('pet.breed'), 'pet_cor' => $get('pet.color'),
            'pet_especie' => ['dog' => 'CANINA', 'cat' => 'FELINA'][data_get($snapshot, 'pet.species', '')] ?? $get('pet.species'),
            'pet_sexo' => ['male' => 'MACHO', 'female' => 'FÊMEA', 'unknown' => 'Não informado'][data_get($snapshot, 'pet.sex', '')] ?? $get('pet.sex'),
            'pet_nascimento' => $birth ? CarbonImmutable::parse($birth)->format('d/m/Y') : 'Não informado',
            'pet_idade' => $birth ? $this->ageInMonths((int) CarbonImmutable::parse($birth)->diffInMonths($date ?? now(), false)) : (data_get($snapshot,'pet.estimated_age_months') !== null ? $this->ageInMonths((int) data_get($snapshot,'pet.estimated_age_months')) : 'Não informado'),
            'produto_nome' => $get('product.name'),
            'valor_parcela' => number_format((int) data_get($snapshot, 'version.price_cents', 0) / 100, 2, ',', '.'),
            'contrato_numero' => $context['contract_number'] ?? 'A gerar no aceite',
            'cidade_assinatura' => 'Presidente Prudente – SP',
            'data_assinatura_extenso' => $date ? $date->locale('pt_BR')->translatedFormat('d \d\e F \d\e Y') : '____ de ______________ de ________',
        ];
    }

    private function ageInMonths(int $months): string
    {
        $months=max(0,$months);
        $years=intdiv($months,12); $rest=$months%12;
        return implode(' e ',array_filter([$years ? $years.($years===1?' ano':' anos') : null, $rest || !$years ? $rest.($rest===1?' mês':' meses') : null]));
    }

    public function validate(string $template): void
    {
        preg_match_all('/\{\{\s*([^{}]+?)\s*\}\}/u', $template, $matches);
        $unknown = array_diff($matches[1], array_keys($this->values([])));
        if ($unknown) {
            throw ValidationException::withMessages(['contract_template' => 'Campos desconhecidos no contrato: '.implode(', ', $unknown)]);
        }
    }

    public function render(string $template, array $snapshot, array $context = []): string
    {
        $this->validate($template);
        $values = $this->values($snapshot, $context);
        return preg_replace_callback('/\{\{\s*([^{}]+?)\s*\}\}/u', fn ($match) => (string) $values[$match[1]], $template);
    }
}
