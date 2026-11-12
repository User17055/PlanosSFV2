<?php

namespace App\Services\Reports;

/**
 * Junta as grafias do mesmo bairro numa cidade: maiúsculas e acentos ("JD MARACANA", "jardim Maracanã"), abreviações ("Res.", "Jd"),
 * o nome sem o tipo ("Humberto Salvador"), número escrito diferente ("Damha 3" e "Damha III"), nome cortado em 20 letras
 * ("Residencial Maré Man") e um erro de digitação ("Jequitibá" e "Jequitibás"). Tipos diferentes não se misturam
 * ("Jardim Bongiovani" e "Residencial Bongiovani"). Fica o nome do mapa, quando o bairro está nele; senão, a grafia mais usada.
 */
class NeighborhoodNames
{
    private const ABBREVIATIONS = ['jd' => 'jardim', 'jdm' => 'jardim', 'jrd' => 'jardim', 'vl' => 'vila', 'pq' => 'parque', 'pqe' => 'parque', 'pque' => 'parque',
        'res' => 'residencial', 'resid' => 'residencial', 'cj' => 'conjunto', 'conj' => 'conjunto', 'hab' => 'habitacional', 'cond' => 'condominio',
        'nuc' => 'nucleo', 'sta' => 'santa', 'sto' => 'santo', 'chac' => 'chacara'];

    private const ROMAN = ['i' => '1', 'ii' => '2', 'iii' => '3', 'iv' => '4', 'v' => '5', 'vi' => '6'];

    private const TYPES = '(parque residencial|conjunto habitacional|nucleo habitacional|condominio residencial|residencial|conjunto|nucleo|jardim|vila|parque|chacaras?|loteamento|condominio|bairro)';

    /** Tamanho em que alguns nomes chegaram cortados do cadastro antigo. */
    private const CUT = 20;

    /** @var array<string, array<string, string>> cidade => [grafia normalizada => nome do bairro] */
    private array $names = [];

    /**
     * @param  iterable<array{0: ?string, 1: ?string, 2: ?string}>  $entries  [estado, cidade, bairro] de cada cadastro
     * @param  array<string, string[]>  $catalog  chave da cidade => nomes dos bairros no mapa
     */
    public function __construct(iterable $entries, array $catalog = [])
    {
        $counts = [];
        foreach ($entries as [$state, $city, $neighborhood]) {
            $raw = trim(preg_replace('/\s+/u', ' ', (string) $neighborhood));
            if ($raw !== '') {
                $counts[self::cityKey($state, $city)][$raw] = ($counts[self::cityKey($state, $city)][$raw] ?? 0) + 1;
            }
        }
        foreach ($counts as $city => $spellings) {
            $groups = [];
            foreach ($catalog[$city] ?? [] as $name) {
                $groups[self::full($name)] ??= ['name' => $name, 'spellings' => []];
            }
            arsort($spellings);
            foreach ($spellings as $raw => $count) {
                $full = self::full($raw);
                $key = self::match($full, mb_strlen($raw), $groups) ?? $full;
                $groups[$key] ??= ['name' => null, 'spellings' => []];
                $groups[$key]['spellings'][$raw] = $count;
                $this->names[$city][$full] = $key;
            }
            foreach ($this->names[$city] as $full => $key) {
                $this->names[$city][$full] = $groups[$key]['name'] ?? self::best($groups[$key]['spellings']);
            }
        }
    }

    /** Nome do bairro como deve aparecer; vazio quando não informado. */
    public function name(?string $state, ?string $city, ?string $neighborhood): string
    {
        $raw = trim(preg_replace('/\s+/u', ' ', (string) $neighborhood));

        return $raw === '' ? '' : ($this->names[self::cityKey($state, $city)][self::full($raw)] ?? self::pretty($raw));
    }

    public static function cityKey(?string $state, ?string $city): string
    {
        return strtoupper(trim((string) $state)).'|'.DashboardService::normalize($city);
    }

    /** Sem acento, maiúsculas, pontuação e abreviação; números romanos viram algarismos. */
    public static function full(string $name): string
    {
        $words = preg_split('/\s+/', DashboardService::normalize(preg_replace('/[.,;:\/\-]+/', ' ', $name)), -1, PREG_SPLIT_NO_EMPTY);

        return implode(' ', array_map(fn ($word) => self::ABBREVIATIONS[$word] ?? self::ROMAN[$word] ?? $word, $words));
    }

    /** O nome sem o tipo do bairro na frente ("Conjunto Habitacional Jardim Humberto Salvador" vira "humberto salvador"). */
    public static function bare(string $full): string
    {
        do {
            $before = $full;
            $full = preg_replace('/^'.self::TYPES.'\s+/', '', $full);
        } while ($full !== $before);

        return $full;
    }

    /** Mesmo bairro, comparando duas grafias soltas (sem o resto da cidade). */
    public static function same(?string $a, ?string $b): bool
    {
        [$a, $b] = [self::full((string) $a), self::full((string) $b)];

        return $a === $b || (self::bare($a) !== '' && self::bare($a) === self::bare($b) && (self::bare($a) === $a || self::bare($b) === $b));
    }

    /** O grupo em que esta grafia entra; null quando é um bairro novo. Só junta quando há um único candidato. */
    private static function match(string $full, int $length, array $groups): ?string
    {
        if (isset($groups[$full])) {
            return $full;
        }
        $hits = ['bare' => [], 'cut' => [], 'typo' => []];
        $bare = self::bare($full);
        foreach (array_keys($groups) as $key) {
            $keyBare = self::bare($key);
            if ($bare !== '' && $bare === $keyBare && ($bare === $full || $keyBare === $key)) {
                $hits['bare'][] = $key;
            } elseif ($length === self::CUT && str_starts_with($key, $full)) {
                $hits['cut'][] = $key;
            } elseif (strlen($full) >= 8 && self::numbers($full) === self::numbers($key) && levenshtein($full, $key) === 1) {
                $hits['typo'][] = $key;
            }
        }
        foreach ($hits as $keys) {
            if (count($keys) === 1) {
                return $keys[0];
            }
        }

        return null;
    }

    private static function numbers(string $full): string
    {
        preg_match_all('/\d+/', $full, $m);

        return implode(' ', $m[0]);
    }

    /** A grafia mais usada, preferindo a que tem acento e maiúsculas normais; nunca a cortada. */
    private static function best(array $spellings): string
    {
        $score = fn (string $s, int $count) => [(int) (mb_strlen($s) !== self::CUT), (int) ($s !== mb_strtoupper($s) && $s !== mb_strtolower($s)), preg_match('/[^\x00-\x7F]/u', $s), $count];
        $best = null;
        foreach ($spellings as $s => $count) {
            if ($best === null || $score((string) $s, $count) > $score($best[0], $best[1])) {
                $best = [(string) $s, $count];
            }
        }

        return self::pretty($best[0]);
    }

    /** "VILA EUCLIDES" e "cohab" viram "Vila Euclides" e "Cohab"; "de", "da", "dos" ficam minúsculos e "II" fica "II". */
    public static function pretty(string $name): string
    {
        if ($name !== mb_strtoupper($name) && $name !== mb_strtolower($name)) {
            return $name;
        }
        $words = explode(' ', mb_convert_case(mb_strtolower($name), MB_CASE_TITLE));
        foreach ($words as $i => $word) {
            $lower = mb_strtolower($word);
            if ($i > 0 && in_array($lower, ['de', 'da', 'do', 'das', 'dos', 'e'], true)) {
                $words[$i] = $lower;
            } elseif (isset(self::ROMAN[$lower])) {
                $words[$i] = mb_strtoupper($word);
            }
        }

        return implode(' ', $words);
    }
}
