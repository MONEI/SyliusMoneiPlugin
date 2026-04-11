<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Form\Type;

use Monei\SyliusPlugin\Factory\MoneiGatewayFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

final class MoneiGatewayConfigurationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('api_key', TextType::class, [
                'label' => 'monei.form.gateway_configuration.api_key',
                'constraints' => [new NotBlank(['groups' => ['sylius']])],
                'attr' => [
                    'placeholder' => 'pk_test_...',
                ],
            ])
            ->add('account_id', TextType::class, [
                'label' => 'monei.form.gateway_configuration.account_id',
                'constraints' => [new NotBlank(['groups' => ['sylius']])],
                'attr' => [
                    'placeholder' => 'Your MONEI Account ID',
                ],
            ])
            ->add('integration_type', ChoiceType::class, [
                'label' => 'monei.form.gateway_configuration.integration_type',
                'choices' => [
                    'monei.form.gateway_configuration.integration_redirect' => MoneiGatewayFactory::INTEGRATION_REDIRECT,
                    'monei.form.gateway_configuration.integration_component' => MoneiGatewayFactory::INTEGRATION_COMPONENT,
                ],
            ])
            ->add('sandbox', CheckboxType::class, [
                'label' => 'monei.form.gateway_configuration.sandbox',
                'required' => false,
            ])
        ;
    }
}
