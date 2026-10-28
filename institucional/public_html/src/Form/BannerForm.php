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

class BannerForm extends AbstractType
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
        $banner = $options['data'];

        $image_valid = array();
        $banner_label = 'Banner';

        if (array_key_exists('banner_type', $banner)) {
            $image_valid = array(
                'minWidth' => ($banner['banner_type']['width'] - 5),
                'maxWidth' => ($banner['banner_type']['width'] + 5),
                'minHeight' => ($banner['banner_type']['height'] - 5),
                'maxHeight' => ($banner['banner_type']['height'] + 5),
            );
            $banner_label = sprintf('Banner (%ux%u)', $banner['banner_type']['width'], $banner['banner_type']['height']);
        }

        $builder
            ->add('type', \Symfony\Component\Form\Extension\Core\Type\HiddenType::class, array(
                'required' => true,
                'label' => 'Type',
            ))
            ->add('title', \Symfony\Component\Form\Extension\Core\Type\TextType::class, array(
                'required' => true,
                'label' => 'Titulo',
            ))
            ->add('url', \Symfony\Component\Form\Extension\Core\Type\UrlType::class, array(
                'required' => false,
                'label' => 'Url',
            ))
            ->add('image', \Symfony\Component\Form\Extension\Core\Type\FileType::class, array(
                'required' => !array_key_exists('id', $banner),
                'label' => $banner_label,
                'constraints' => array(
                    new Assert\Image($image_valid),
                ),
            ))
            ->add('enabled', StatusType::class)
            ->add('show_in', \Symfony\Component\Form\Extension\Core\Type\TextType::class, array(
                'required' => false,
                'label' => 'Exibir de',
            ))
            ->add('show_out', \Symfony\Component\Form\Extension\Core\Type\TextType::class, array(
                'required' => false,
                'label' => 'Exibir ate',
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
        return 'banner';
    }
}
