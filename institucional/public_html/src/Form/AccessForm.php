<?php

/**
 *  (c) Rogério Adriano da Silva <rogerioadris.silva@gmail.com>.
 */
namespace LuzPropria\LuzPanel\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AccessForm extends AbstractType
{
    /**
     * Builds the form.
     *
     * This method is called for each type in the hierarchy starting from the
     * top most type. Type extensions can further modify the form.
     *
     * @see FormTypeExtensionInterface::buildForm()
     *
     * @param FormBuilderInterface $builder The form builder
     * @param array                $options The options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder

            // path
            ->add('path', \Symfony\Component\Form\Extension\Core\Type\TextType::class, array(
                'required' => true,
                'label' => 'Rota',
                'attr' => array(
                    'placeholder' => '^/luzsisadm',
                ),
                'constraints' => array(
                    new Assert\NotBlank(),
                    new Assert\Length(array(
                        'min' => 8,
                        'max' => 60,
                    )),
                ),
            ))

            // roles
            ->add('roles', \Symfony\Component\Form\Extension\Core\Type\ChoiceType::class, array(
                'label' => 'Regras',
                'multiple' => true,
                'required' => true,
                'choices' => array_key_exists('role_choices', $options['data']) ? $options['data']['role_choices'] : array(),
                'empty_data' => array(2),
                'constraints' => array(
                ),
            ))

            // methods
            ->add('methods', \Symfony\Component\Form\Extension\Core\Type\ChoiceType::class, array(
                'label' => 'Método',
                'required' => false,
                'multiple' => true,
                'choices' => array(
                    'GET' => 'GET',
                    'POST' => 'POST',
                    'PUT' => 'PUT',
                    'DELETE' => 'DELETE',
                    'OPTIONS' => 'OPTIONS',
                    'HEAD' => 'HEAD',
                    'TRACE' => 'TRACE',
                    'CONNECT' => 'CONNECT',
                ),
                'constraints' => array(
                ),
            ))

            // host
            ->add('host', \Symfony\Component\Form\Extension\Core\Type\TextType::class, array(
                'required' => false,
                'label' => 'Host',
                'attr' => array(
                    'placeholder' => '',
                ),
                'constraints' => array(
                ),
            ))

            // ip
            ->add('ip', \Symfony\Component\Form\Extension\Core\Type\TextType::class, array(
                'required' => false,
                'label' => 'IP',
                'attr' => array(
                    'placeholder' => '127.0.0.1',
                ),
                'constraints' => array(
                ),
            ))
        ;
    }

    /**
     * Configures the options for this type.
     *
     * @param OptionsResolver $resolver The resolver for the options.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        //
    }

    /**
     * Returns the name of this type.
     *
     * @return string The name of this type
     */
    public function getBlockPrefix(): string
    {
        return 'access';
    }
}
