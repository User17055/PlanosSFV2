<?php

/*
 *  (c) Rogério Adriano da Silva <rogerioadris.silva@gmail.com>
 */

namespace LuzPropria\LuzPanel\Controller;

use LuzPropria\LuzPanel\Traits as Traits;
use LuzPropria\LuzPanel\Infrastructure\AppContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class ContainerAware.
 */
abstract class ContainerAware
{
    use Traits\ContainerTrait,
        Traits\ResponseTrait,
        Traits\DoctrineTrait,
        Traits\FlashMessageTrait;

    public function __construct(AppContext $app)
    {
        $this->setContainer($app);
    }

    /**
     * Renderiza pagina.
     *
     * @param       $name
     * @param array $parameters
     *
     * @return mixed
     */
    protected function render($name, array $parameters = array())
    {
        if (substr($name, 0, 1) !== '/') {
            $patterns = array(
                sprintf('/^(\\\\)?%s(\\\\)?/', str_replace('\\', '(\\\\)?', __NAMESPACE__)),
                '/Controller$/',
                '/\\\/',
            );

            $replaces = array(
                '',
                '',
                DIRECTORY_SEPARATOR,
            );

            $prefix = strtolower(preg_replace($patterns, $replaces, get_class($this)));
        } else {
            $prefix = '';
            $name = substr($name, 1);
        }

        return new Response($this->get('twig')->render(ltrim(sprintf('%s/%s', $prefix, $name), '/'), $parameters));
    }

    /**
     * Returns a form builder.
     *
     * @param string|FormTypeInterface $type    The type of the form
     * @param mixed                    $data    The initial data
     * @param array                    $options The options
     *
     * @return FormBuilderInterface The form builder
     *
     * @throws \Symfony\Component\OptionsResolver\Exception\InvalidOptionsException if any given option is not applicable to the given type
     */
    protected function createForm($type = 'form', $data = null, array $options = array())
    {
        $type = is_object($type) ? get_class($type) : $type;
        if ($type === 'form') { $type = \Symfony\Component\Form\Extension\Core\Type\FormType::class; }
        return $this->get('form.factory')->create($type, $data, $options);
    }
}
