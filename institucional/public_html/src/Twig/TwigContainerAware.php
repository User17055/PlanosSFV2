<?php

/*
 *  (c) Rogério Adriano da Silva <rogerioadris.silva@gmail.com>
 */

namespace LuzPropria\LuzPanel\Twig;

use LuzPropria\LuzPanel\Infrastructure\AppContext as Application;
use LuzPropria\LuzPanel\Traits\ContainerTrait;

/**
 * Class TwigContainerAware.
 *
 * http://twig.sensiolabs.org/doc/advanced.html#creating-an-extension
 */
abstract class TwigContainerAware extends \Twig\Extension\AbstractExtension
{
    use ContainerTrait;

    /**
     * @param Application $app
     */
    public function __construct(Application $app)
    {
        $this->setContainer($app);
    }
}
