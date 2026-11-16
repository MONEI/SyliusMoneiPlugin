<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Form\Type;

use Monei\SyliusPlugin\Gateway\GatewayConfiguration;
use Monei\SyliusPlugin\Gateway\MoneiMethod;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class MoneiGatewayConfigurationType extends AbstractType
{
    private const GROUPS = ['sylius', 'monei'];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add(GatewayConfiguration::API_KEY, TextType::class, [
                'label' => 'monei.form.gateway_configuration.api_key',
                'help' => 'monei.form.gateway_configuration.api_key_help',
                'attr' => ['placeholder' => 'pk_test_…', 'autocomplete' => 'off'],
                'constraints' => [
                    new NotBlank(groups: self::GROUPS),
                    new Regex(
                        pattern: '/^pk_(test|live)_\S+$/',
                        message: 'monei.gateway_configuration.api_key.invalid',
                        groups: self::GROUPS,
                    ),
                ],
            ])
            ->add(GatewayConfiguration::METHOD, ChoiceType::class, [
                'label' => 'monei.form.gateway_configuration.payment_method',
                'choices' => array_combine(
                    array_map(static fn (MoneiMethod $method): string => 'monei.method.' . $method->value, MoneiMethod::cases()),
                    array_map(static fn (MoneiMethod $method): string => $method->value, MoneiMethod::cases()),
                ),
                'constraints' => [new NotBlank(groups: self::GROUPS)],
            ])
            ->add(GatewayConfiguration::MODE, ChoiceType::class, [
                'label' => 'monei.form.gateway_configuration.mode',
                'help' => 'monei.form.gateway_configuration.mode_help',
                'choices' => [
                    'monei.form.gateway_configuration.mode_hosted' => GatewayConfiguration::MODE_HOSTED,
                    'monei.form.gateway_configuration.mode_onsite' => GatewayConfiguration::MODE_ONSITE,
                ],
                'empty_data' => GatewayConfiguration::MODE_HOSTED,
            ])
            ->add(GatewayConfiguration::USE_AUTHORIZE, CheckboxType::class, [
                'label' => 'monei.form.gateway_configuration.use_authorize',
                'help' => 'monei.form.gateway_configuration.use_authorize_help',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('constraints', [
            new Callback(callback: self::validateCombination(...), groups: self::GROUPS),
        ]);
    }

    /** @param mixed $config */
    public static function validateCombination($config, ExecutionContextInterface $context): void
    {
        if (!is_array($config)) {
            return;
        }

        $method = MoneiMethod::tryFrom(is_string($config[GatewayConfiguration::METHOD] ?? null) ? $config[GatewayConfiguration::METHOD] : '');
        if (null === $method) {
            return;
        }

        if (GatewayConfiguration::MODE_ONSITE === ($config[GatewayConfiguration::MODE] ?? null) && !$method->supportsOnsite()) {
            $context->buildViolation('monei.gateway_configuration.mode.hosted_only')
                ->atPath('[' . GatewayConfiguration::MODE . ']')
                ->addViolation();
        }

        if (true === ($config[GatewayConfiguration::USE_AUTHORIZE] ?? false) && !$method->supportsAuthorize()) {
            $context->buildViolation('monei.gateway_configuration.use_authorize.not_supported')
                ->atPath('[' . GatewayConfiguration::USE_AUTHORIZE . ']')
                ->addViolation();
        }
    }
}
