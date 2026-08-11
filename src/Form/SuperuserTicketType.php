<?php

namespace App\Form;

use App\Entity\Ticket;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType; // Ajout
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SuperuserTicketType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['label' => 'Titre', 'attr' => ['class' => 'form-control']])
            ->add('description', TextareaType::class, ['label' => 'Description', 'required' => false, 'attr' => ['class' => 'form-control', 'rows' => 4]])
            ->add('actionType', TextType::class, ['label' => 'Type d\'action', 'attr' => ['class' => 'form-control']])
            ->add('priority', ChoiceType::class, [
                'label' => 'Priorité',
                'choices' => [
                    'Immédiatement' => 'immediate',
                    'Haute' => 'high',
                    'Moyenne' => 'medium',
                ],
                'attr' => ['class' => 'form-control'],
                'expanded' => false,
                'multiple' => false,
            ])
            ->add('deadline', DateType::class, ['widget' => 'single_text', 'required' => false, 'label' => 'Date limite', 'html5' => true, 'attr' => ['class' => 'form-control']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Ticket::class]);
    }
}