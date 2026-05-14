<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SiteUpdateRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('siteName', HiddenType::class)
            ->add('newCapacity', NumberType::class, [
                'label' => 'Nouvelle capacité (Mbps)',
                'required' => false,
                'scale' => 2,
            ])
            ->add('newSupportType', ChoiceType::class, [
                'label' => 'Type de support',
                'choices' => [
                    'FO' => 'FO',
                    'FH' => 'FH',
                    'Shared' => 'Shared',
                    'Radio' => 'Radio',
                    'Backhaul' => 'Backhaul',
                ],
                'placeholder' => 'Choisir un support',
                'required' => false,
            ])
            ->add('upgradeDate', DateType::class, [
                'label' => 'Date de mise à jour',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('decisionComment', TextareaType::class, [
                'label' => 'Commentaire',
                'required' => false,
                'attr' => ['rows' => 3],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}
