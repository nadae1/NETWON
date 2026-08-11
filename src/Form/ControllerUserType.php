<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class ControllerUserType extends AbstractType
{
    // Définition des départements par service
    private const DEPARTMENTS = [
        'FO' => [
            'Ingénierie IP' => 'ingenierie_ip',
            'Support Trans' => 'support_trans',
        ],
        'FH' => [
            'Ingénierie Capillaire' => 'ingenierie_capillaire',
            'Support FH' => 'support_fh',
        ],
        'DEPLOIEMENT' => [
            'Déploiement Télécom' => 'deploiement_telecom',
            'Support Radio' => 'support_radio',
            'Support Backhaul' => 'support_backhaul',
        ],
        'SHARED' => [
            'Opérateur hébergeur' => 'operator',
        ],
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('username', TextType::class, [
                'label' => 'Nom d’utilisateur',
                'constraints' => [
                    new Assert\NotBlank(message: 'Le username est obligatoire.'),
                    new Assert\Length(min: 3, max: 100, minMessage: 'Le username doit contenir au moins {{ limit }} caractères.', maxMessage: 'Le username ne peut pas dépasser {{ limit }} caractères.'),
                    new Assert\Regex(
                        pattern: '/^[A-Za-z0-9._-]+$/',
                        message: 'Le username contient des caractères non autorisés.'
                    ),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'constraints' => [
                    new Assert\NotBlank(message: 'L’email est obligatoire.'),
                    new Assert\Email(message: 'Email invalide.'),
                    new Assert\Length(max: 180, maxMessage: 'L’email ne peut pas dépasser {{ limit }} caractères.'),
                ],
            ])
            ->add('roles', ChoiceType::class, [
                'choices' => [
                    'Utilisateur' => 'ROLE_USER',
                    'Superuser' => 'ROLE_SUPERUSER',
                    'Administrateur' => 'ROLE_ADMIN',
                ],
                'multiple' => true,
                'expanded' => true,
                'label' => 'Rôles',
                'required' => true,
            ])
            ->add('serviceChoice', ChoiceType::class, [
                'label' => 'Service',
                'placeholder' => 'Sélectionner un service',
                'required' => false,
                'mapped' => false,
                'choices' => [
                    'FO' => 'FO',
                    'FH' => 'FH',
                    'Déploiement' => 'DEPLOIEMENT',
                    'Shared' => 'SHARED',
                ],
            ])
            ->add('departmentChoice', ChoiceType::class, [
                'label' => 'Département',
                'placeholder' => 'Sélectionner un département',
                'required' => false,
                'mapped' => false,
                'choices' => [],
            ])
            ->add('plainPassword', PasswordType::class, [
                'mapped' => false,
                'required' => $options['password_required'],
                'label' => 'Mot de passe',
                'attr' => [
                    'autocomplete' => 'new-password',
                ],
                'constraints' => $options['password_required'] ? [
                    new Assert\NotBlank(message: 'Le mot de passe est obligatoire.'),
                    new Assert\Length(min: 8, max: 255, minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.', maxMessage: 'Le mot de passe ne peut pas dépasser {{ limit }} caractères.'),
                ] : [
                    new Assert\Length(max: 255, maxMessage: 'Le mot de passe ne peut pas dépasser {{ limit }} caractères.'),
                ]
            ]);

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $data = $event->getData();
            $form = $event->getForm();

            if (isset($data['serviceChoice']) && !empty($data['serviceChoice'])) {
                $departments = $this->getDepartmentsForService($data['serviceChoice']);

                $form->add('departmentChoice', ChoiceType::class, [
                    'label' => 'Département',
                    'placeholder' => 'Sélectionner un département',
                    'required' => false,
                    'mapped' => false,
                    'choices' => $departments,
                ]);
            }
        });
    }

    private function getDepartmentsForService(string $service): array
    {
        return self::DEPARTMENTS[$service] ?? [];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'password_required' => true,
        ]);
    }
}