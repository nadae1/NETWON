<?php

namespace App\Form;

use App\Entity\TicketTask;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;

class WorkflowDecisionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('decision', ChoiceType::class, [
                'label' => 'Décision',
                'choices' => $options['choices'],
                'placeholder' => 'Choisir une décision',
                'constraints' => [new NotBlank()],
                'attr' => [
                    'class' => 'form-control',
                    'data-test' => 'workflow-decision'
                ]
            ]);
        
        // Ajouter le choix du site si nécessaire (multi-sites)
        if ($options['sites'] && count($options['sites']) > 1) {
            $siteChoices = array_flip($options['sites']);
            $builder->add('siteValidated', ChoiceType::class, [
                'label' => 'Site validé',
                'choices' => $siteChoices,
                'placeholder' => 'Sélectionner le site',
                'constraints' => [new NotBlank()],
                'mapped' => false,
                'attr' => [
                    'class' => 'form-control',
                    'data-test' => 'site-choice'
                ]
            ]);
        }
        
        $builder
            ->add('comment', TextareaType::class, [
                'label' => 'Commentaire / résultat',
                'required' => false,
                'attr' => [
                    'rows' => 5,
                    'placeholder' => 'Expliquez le résultat, prérequis, blocage, action réalisée...',
                    'class' => 'form-control'
                ],
            ])
            ->add('proofFile', FileType::class, [
                'label' => 'Pièce jointe / preuve',
                'mapped' => false,
                'required' => false,
                'constraints' => [new File(['maxSize' => '8M'])],
                'attr' => [
                    'class' => 'form-control',
                    'accept' => '.pdf,.png,.jpg,.jpeg,.doc,.docx'
                ]
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'choices' => [
                'OK' => 'ok',
                'NOK' => 'nok',
            ],
            'sites' => [],
        ]);
        $resolver->setAllowedTypes('choices', 'array');
        $resolver->setAllowedTypes('sites', 'array');
    }
}

