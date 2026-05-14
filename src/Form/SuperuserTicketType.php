<?php

namespace App\Form;

use App\Entity\Ticket;
use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SuperuserTicketType extends AbstractType
{
    public function __construct(private UserRepository $userRepository)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Titre du workflow',
                'attr' => ['placeholder' => 'Ex: Plan Data - Sites congestionnés', 'class' => 'form-control']
            ])
            ->add('actionType', TextType::class, [
                'label' => "Type d'action",
                'attr' => ['placeholder' => 'UPGRADE / SWAP / NOUVEAU_SITE', 'class' => 'form-control']
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['rows' => 4, 'class' => 'form-control']
            ])
            ->add('deadlineAt', DateTimeType::class, [
                'label' => 'Date limite',
                'required' => false,
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control']
            ])
            ->add('assignedUsers', EntityType::class, [
                'label' => 'Utilisateurs assignés',
                'class' => User::class,
                'choice_label' => function(User $user) {
                    return $user->getUsername() . ' (' . ($user->getService() ?? 'N/A') . ')';
                },
                'multiple' => true,
                'expanded' => false,
                'choices' => $this->userRepository->findAssignableUsers(),
                'attr' => ['class' => 'multi-select form-control', 'size' => 8]
            ]);
    }
    
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Ticket::class,
        ]);
    }
}