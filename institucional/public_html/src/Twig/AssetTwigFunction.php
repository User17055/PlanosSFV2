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
class AssetTwigFunction extends TwigContainerAware
{
    public function getName()
    {
        return 'asset';
    }

    public function getFunctions(): array
    {
        return array(
            new \Twig\TwigFunction('asset', array($this, 'find')),
            new \Twig\TwigFunction('format_date', array($this, 'format_date')),
        );
    }

    public function find($asset)
    {
        $request = $this->get('request');
        $base = $request?->getBaseUrl() ?? '';
        $version = $this->get('composer')['version'];
        return sprintf('%s/%s%sv=%s', $base, ltrim($asset, '/'), str_contains($asset, '?') ? '&' : '?', rawurlencode($version));
    }

    public function format_date($data){
        $date = date_create($data);
        $date_exibe = date_format($date, 'd/m/Y');        
        return $date_exibe;
    }
}
