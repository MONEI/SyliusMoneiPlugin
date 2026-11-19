<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Form\Type;

use Monei\SyliusPlugin\Gateway\GatewayConfiguration;
use Monei\SyliusPlugin\Gateway\InvalidGatewayConfigurationException;
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
    private const GROUPS = ['sylius'];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add(GatewayConfiguration::API_KEY, TextType::class, [
                'label' => 'monei.form.gateway_configuration.api_key',
                'help' => 'monei.form.gateway_configuration.api_key_help',
                'attr' => ['placeholder' => 'pk_test_…', 'autocomplete' => 'off'],
                'constraints' => [new NotBlank(groups: self::GROUPS)],
            ])
            ->add(GatewayConfiguration::METHOD, ChoiceType::class, [
                'label' => 'monei.form.gateway_configuration.payment_method',
                'choices' => array_combine(
                    array_map(static fn (MoneiMethod $method): string => 'monei.method.'.$method->value, MoneiMethod::cases()),
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
            ->add(GatewayConfiguration::ORDER_ID_PREFIX, TextType::class, [
                'label' => 'monei.form.gateway_configuration.order_id_prefix',
                'help' => 'monei.form.gateway_configuration.order_id_prefix_help',
                'required' => false,
                'empty_data' => '',
            ])
            ->add(GatewayConfiguration::CARD_LAYOUT, ChoiceType::class, [
                'label' => 'monei.form.gateway_configuration.card_layout',
                'help' => 'monei.form.gateway_configuration.card_layout_help',
                // Split first: a stored config without the key preselects the first option.
                'choices' => [
                    'monei.form.gateway_configuration.card_layout_split' => GatewayConfiguration::CARD_LAYOUT_SPLIT,
                    'monei.form.gateway_configuration.card_layout_single' => GatewayConfiguration::CARD_LAYOUT_SINGLE,
                ],
                'empty_data' => GatewayConfiguration::CARD_LAYOUT_SPLIT,
            ])
            ->add(GatewayConfiguration::ACCOUNT_ID, TextType::class, [
                'label' => 'monei.form.gateway_configuration.account_id',
                'help' => 'monei.form.gateway_configuration.account_id_help',
                'required' => false,
                'empty_data' => '',
                'attr' => ['autocomplete' => 'off'],
                'constraints' => [new Regex(
                    pattern: GatewayConfiguration::ACCOUNT_ID_PATTERN,
                    message: 'monei.gateway_configuration.account_id.invalid',
                    groups: self::GROUPS,
                )],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('constraints', [
            new Callback(callback: self::validate(...), groups: self::GROUPS),
        ]);
    }

    public static function validate(mixed $config, ExecutionContextInterface $context): void
    {
        if (!\is_array($config)) {
            return;
        }

        try {
            GatewayConfiguration::fromArray($config);
        } catch (InvalidGatewayConfigurationException $exception) {
            // NotBlank already reports an empty field.
            if (\in_array($config[$exception->field] ?? null, [null, ''], true)) {
                return;
            }

            $context->buildViolation($exception->translationKey)
                ->atPath('['.$exception->field.']')
                ->addViolation();
        }
    }
}
