<?php

namespace Tests\Unit;

use App\Services\Reports\NeighborhoodNames;
use PHPUnit\Framework\TestCase;

/** Grafias reais dos cadastros de Presidente Prudente. */
class NeighborhoodNamesTest extends TestCase
{
    public function test_spellings_of_the_same_neighborhood_join_and_different_ones_stay_apart(): void
    {
        $entries = [];
        foreach (['Jardim Maracanã' => 22, 'jardim Maracanã' => 1, 'JD MARACANA' => 3, 'Conjunto Habitacional Jardim Humberto Salvador' => 5, 'Humberto Salvador' => 1,
            'Residencial Maré Mansa' => 3, 'Residencial Maré Man' => 2, 'Parque Residencial Damha III' => 2, 'Damha 3' => 3, 'Parque Residencial Damha II' => 3,
            'Jardim Jequitibás' => 2, 'Jardim Jequitibá' => 3, 'Jardim Bongiovani' => 12, 'Residencial Bongiovani' => 3, 'Residencial Minerva I' => 2,
            'Residencial Minerva II' => 1, 'VILA EUCLIDES' => 1, 'Vila Euclides' => 5, 'cohab' => 7, 'Jardim Paulista' => 6, 'Jardim Paulistano' => 3,
            'Res. Terra Nova' => 1, 'Residencial Terra Nova' => 2, 'SÃO JUDAS TADEU' => 2, 'Parque São Judas Tadeu' => 7, 'Central Park Residence ' => 1] as $name => $count) {
            array_push($entries, ...array_fill(0, $count, ['SP', 'Presidente Prudente', $name]));
        }
        $names = new NeighborhoodNames($entries, ['SP|presidente prudente' => ['Jardim Maracanã', 'Jardim Jequitibás', 'Parque Residencial Damha III']]);
        $name = fn (string $n) => $names->name('SP', 'Presidente Prudente', $n);

        // Maiúsculas, abreviação, nome sem o tipo, número romano, nome cortado em 20 letras e erro de digitação.
        $this->assertSame('Jardim Maracanã', $name('JD MARACANA'));
        $this->assertSame('Jardim Maracanã', $name('jardim Maracanã'));
        $this->assertSame('Conjunto Habitacional Jardim Humberto Salvador', $name('Humberto Salvador'));
        $this->assertSame('Parque Residencial Damha III', $name('Damha 3'));
        $this->assertSame('Residencial Maré Mansa', $name('Residencial Maré Man'));
        $this->assertSame('Jardim Jequitibás', $name('Jardim Jequitibá'));
        $this->assertSame('Residencial Terra Nova', $name('Res. Terra Nova'));
        $this->assertSame('Parque São Judas Tadeu', $name('SÃO JUDAS TADEU'));
        $this->assertSame('Vila Euclides', $name('VILA EUCLIDES'));
        $this->assertSame('Cohab', $name('cohab'));
        // Bairros diferentes continuam separados.
        $this->assertSame('Parque Residencial Damha II', $name('Parque Residencial Damha II'));
        $this->assertSame('Residencial Bongiovani', $name('Residencial Bongiovani'));
        $this->assertSame('Residencial Minerva II', $name('Residencial Minerva II'));
        $this->assertSame('Jardim Paulista', $name('Jardim Paulista'));
        $this->assertSame('Jardim Paulistano', $name('Jardim Paulistano'));
        // Outra cidade não se mistura.
        $this->assertSame('Jardim Maracanã', $names->name('SP', 'Álvares Machado', 'Jardim Maracanã'));
        $this->assertSame('', $name('  '));
    }

    /** Planos que ficavam fora do mapa em 15/11/2026, cada um com a grafia que veio do cadastro. */
    public function test_cut_misspelled_and_condominium_names_find_the_neighborhood_on_the_map(): void
    {
        $entries = [];
        foreach (['Núcleo Bartholomeu Bueno de Miranda' => 7, 'Núcleo Bartholomeu B' => 5, 'Village Damha Presidente Prudente' => 7, 'DHAMA I' => 1,
            'DAHMA 2' => 1, 'DHAMA BEATRIZ' => 1, 'CONDOMINIO ESSENZA' => 2, 'Condomínio Residencial Jardins de Anahy' => 2, 'RES BOSQUE DOS TAMBU' => 1,
            'Parque Residencial Mart Ville' => 5, 'Parque Residencial M' => 3, 'Residencial Parque d' => 1, 'Jardim Bongiovani' => 8, 'Residencial Bongiovani' => 3] as $name => $count) {
            array_push($entries, ...array_fill(0, $count, ['SP', 'Presidente Prudente', $name]));
        }
        $names = new NeighborhoodNames($entries, ['SP|presidente prudente' => ['Nucleo Bartolomeu Bueno de Miranda', 'Conjunto Habitacional Bartholomeu Bueno de Miranda',
            'Residencial Village Damha', 'Parque Residencial Damha I', 'Parque Residencial Damha II', 'Damha Beatriz', 'Residencial Essenza',
            'Residencial Jardins do Anahy', 'Condomínio Bosque dos Tamburis', 'Parque Residencial Mediterrâneo', 'Jardim Bongiovani', 'Residencial Bongiovani']]);
        $name = fn (string $n) => $names->name('SP', 'Presidente Prudente', $n);

        $this->assertSame('Nucleo Bartolomeu Bueno de Miranda', $name('Núcleo Bartholomeu B'));
        $this->assertSame('Residencial Village Damha', $name('Village Damha Presidente Prudente'));
        $this->assertSame('Parque Residencial Damha I', $name('DHAMA I'));
        $this->assertSame('Parque Residencial Damha II', $name('DAHMA 2'));
        $this->assertSame('Damha Beatriz', $name('DHAMA BEATRIZ'));
        $this->assertSame('Residencial Essenza', $name('CONDOMINIO ESSENZA'));
        $this->assertSame('Residencial Jardins do Anahy', $name('Condomínio Residencial Jardins de Anahy'));
        $this->assertSame('Condomínio Bosque dos Tamburis', $name('RES BOSQUE DOS TAMBU'));
        // Cortado e com dois candidatos (Mart Ville e Mediterrâneo): fica como veio. Tipos diferentes continuam separados.
        $this->assertSame('Parque Residencial M', $name('Parque Residencial M'));
        $this->assertSame('Residencial Parque d', $name('Residencial Parque d'));
        $this->assertSame('Residencial Bongiovani', $name('Residencial Bongiovani'));
        $this->assertSame('Jardim Bongiovani', $name('Jardim Bongiovani'));
    }
}
