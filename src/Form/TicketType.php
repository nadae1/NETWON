<?php

namespace App\Form;

use App\Entity\Ticket;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TicketType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Titre du ticket',
            ])
            ->add('actionType', ChoiceType::class, [
                'label' => 'Action demandée',
                'choices' => [
                    'Upgrade 10G' => 'upgrade_10g',
                    'Swap T+F' => 'swap_t_f',
                    'Nouveau besoin' => 'nouveau_besoin',
                    'Nouveau site' => 'nouveau_site',
                ],
            ])
            ->add('deadlineAt', DateTimeType::class, [
                'label' => 'Date limite',
                'required' => false,
                'widget' => 'single_text',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Ticket::class,
        ]);
    }
}