<?php

/**
 *  (c) Rogério Adriano da Silva <rogerioadris.silva@gmail.com>.
 */
namespace LuzPropria\LuzPanel\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\OptionsResolver\OptionsResolver;
use LuzPropria\LuzPanel\Form\Type\StatusType;

class AdocoesTypeForm extends AbstractType
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
        $adocoes_type = $options['data'];

        $image_valid = array();

        $builder
            ->add('nome', \Symfony\Component\Form\Extension\Core\Type\TextType::class, array(
                'required' => true,
                'label' => 'Nome',
                'constraints' => array(
                    new Assert\NotBlank(),
                    new Assert\Length(array(
                        'min' => 4,
                        'max' => 120,
                    )),
                ),
            ))
            ->add('cidade', \Symfony\Component\Form\Extension\Core\Type\TextType::class, array(
                'required' => true,
                'label' => 'Cidade',
                'constraints' => array(
                    new Assert\NotBlank(),
                    new Assert\Length(array(
                        'min' => 4,
                        'max' => 120,
                    )),
                ),
            ))
            ->add('image', \Symfony\Component\Form\Extension\Core\Type\FileType::class, array(
                'required' => !array_key_exists('id', $adocoes_type),
                'label' => 'Imagem',
                'constraints' => array(
                    new Assert\Image($image_valid),
                ),
            ))

            // enabled
            ->add('enabled', StatusType::class)
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
        return 'adocoes_type';
    }
}
