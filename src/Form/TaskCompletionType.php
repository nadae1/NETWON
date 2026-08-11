<?php 

// src//Form/TaskCompletionType.php

namespace App\Form;

use App\Entity\TicketTask;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TaskCompletionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('decision', ChoiceType::class, [
                'label' => 'Décision',
                'choices' => [
                    '✅ OK — Action réussie' => 'OK',
                    '❌ NOK — Problème' => 'NOK',
                ],
                'expanded' => true,
                'multiple' => false,
                'required' => false,
            ])
            ->add('comment', TextareaType::class, [
                'label' => 'Commentaire',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('proofFile', FileType::class, [
                'label' => 'Pièce jointe (preuve)',
                'required' => false,
                'mapped' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TicketTask::class,
        ]);
    }
}