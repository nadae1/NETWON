<?php

namespace App\Form;

use App\Entity\Service;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SubWorkflowRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $serviceChoices = $options['service_choices'] ?? [];

        $builder
            ->add('service', ChoiceType::class, [
                'choices' => $serviceChoices,
                'label' => 'Service cible',
                'placeholder' => 'Choisir un service',
            ])
            ->add('reason', TextareaType::class, [
                'label' => 'Motif / description',
                'attr' => ['rows' => 4],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'service_choices' => [],
        ]);
    }
}
