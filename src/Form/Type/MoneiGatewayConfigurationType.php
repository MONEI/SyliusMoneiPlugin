<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Form\Type;

use Monei\SyliusPlugin\Gateway\GatewayConfiguration;
use Monei\SyliusPlugin\Gateway\GatewayConfigurationProvider;
use Monei\SyliusPlugin\Gateway\InvalidGatewayConfigurationException;
use Monei\SyliusPlugin\Gateway\MoneiMethod;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class MoneiGatewayConfigurationType extends AbstractType
{
    private const GROUPS = ['sylius'];

    public function __construct(private readonly GatewayConfigurationProvider $gatewayConfigurations)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add(GatewayConfiguration::CREDENTIALS, ChoiceType::class, [
                'label' => 'monei.form.gateway_configuration.credentials',
                'choices' => [
                    'monei.form.gateway_configuration.credentials_manual' => GatewayConfiguration::MANUAL,
                    'monei.form.gateway_configuration.credentials_connected_live' => GatewayConfiguration::CONNECTED_LIVE,
                    'monei.form.gateway_configuration.credentials_connected_test' => GatewayConfiguration::CONNECTED_TEST,
                ],
                'empty_data' => GatewayConfiguration::MANUAL,
            ])
            ->add(GatewayConfiguration::API_KEY, TextType::class, [
                'label' => 'monei.form.gateway_configuration.api_key',
                'help' => 'monei.form.gateway_configuration.api_key_help',
                'attr' => ['placeholder' => 'pk_test_…', 'autocomplete' => 'off'],
                'required' => false,
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
            ])
            ->addEventListener(FormEvents::SUBMIT, static function (FormEvent $event): void {
                $config = $event->getData();
                if (\is_array($config) && GatewayConfiguration::MANUAL !== ($config[GatewayConfiguration::CREDENTIALS] ?? GatewayConfiguration::MANUAL)) {
                    unset($config[GatewayConfiguration::API_KEY], $config[GatewayConfiguration::ACCOUNT_ID]);
                    $event->setData($config);
                }
            })
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('constraints', [
            new Callback(callback: $this->validate(...), groups: self::GROUPS),
        ]);
    }

    private function validate(mixed $config, ExecutionContextInterface $context): void
    {
        if (!\is_array($config)) {
            return;
        }

        try {
            $resolved = $this->gatewayConfigurations->resolve($config);
        } catch (InvalidGatewayConfigurationException $exception) {
            // The method field already has a NotBlank constraint.
            if (GatewayConfiguration::METHOD === $exception->field && empty($config[GatewayConfiguration::METHOD])) {
                return;
            }

            $context->buildViolation($exception->translationKey)
                ->atPath('['.$exception->field.']')
                ->addViolation();

            return;
        }

        $accountId = $config[GatewayConfiguration::ACCOUNT_ID] ?? '';
        if (GatewayConfiguration::MANUAL === $resolved->credentials && '' !== $accountId && null === $resolved->accountId) {
            $context->buildViolation('monei.gateway_configuration.account_id.invalid')
                ->atPath('['.GatewayConfiguration::ACCOUNT_ID.']')
                ->addViolation();
        }
    }
}
