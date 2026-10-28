<?php

/*
 *  (c) Rogério Adriano da Silva <rogerioadris.silva@gmail.com>
 */

namespace LuzPropria\LuzPanel\Twig;

/**
 * Class AssetTwigFunction.
 *
 * http://twig.sensiolabs.org/doc/advanced.html#creating-an-extension
 */
class PaginacaoTwigFunction extends TwigContainerAware
{
    public function getName()
    {
        return 'paginacao';
    }

    public function getFunctions(): array
    {
        return array(
            new \Twig\TwigFunction('getPages', array($this, 'getPages')),            
            new \Twig\TwigFunction('getArrayPages', array($this, 'getArrayPages')),            
            new \Twig\TwigFunction('getPagesInicial', array($this, 'getPagesInicial')),            
            new \Twig\TwigFunction('getPagesFinal', array($this, 'getPagesFinal')),            
        );
    }

    
    public function getPages($array,$quantity)
    {
        return ceil(count($array) / $quantity);                
    }

    public function getPagesInicial($array,$pagina,$range,$quantity)
    {
        $ini = $pagina - $range;
        $ini = $ini < 1?1:$ini;
        return $ini;
    }

    public function getPagesFinal($array,$pagina,$range,$quantity)
    {
        $fim = $pagina + $range;
        $fim = $this->getPages($array,$quantity) >$fim?$fim: $this->getPages($array,$quantity);
        return $fim;
    }

    public function getArrayPages($array,$quantity,$indice)
    {
        $indice = $indice -1;
        $count = $quantity * $indice;        
        return array_slice($array,$count,$quantity);
    }
}
