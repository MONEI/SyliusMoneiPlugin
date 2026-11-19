<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Grid;

use Sylius\Bundle\GridBundle\Builder\Field\TwigField;
use Sylius\Component\Grid\Builder\GridBuilderInterface;
use Sylius\Component\Grid\Mutator\GridMutatorInterface;

/**
 * Shows the MONEI logo in the Sylius 2.3 admin payment method list. Acts only on the stock
 * name field template, so Sylius 2.2 and a shop's own grid override stay as they are.
 */
final class PaymentMethodGridMutator implements GridMutatorInterface
{
    public const GRID = 'sylius_admin_payment_method';
    private const STOCK_TEMPLATE = '@SyliusAdmin/payment_method/grid/field/name.html.twig';

    private ?string $nameTemplate;

    /** @param array<array-key, mixed> $gridsDefinitions `%sylius.grids_definitions%` */
    public function __construct(array $gridsDefinitions)
    {
        $template = $gridsDefinitions[self::GRID]['fields']['name']['options']['template'] ?? null;
        $this->nameTemplate = \is_string($template) ? $template : null;
    }

    public function __invoke(GridBuilderInterface $gridBuilder): void
    {
        if (self::STOCK_TEMPLATE !== $this->nameTemplate) {
            return;
        }

        // The builder field replaces the whole field on the attribute grid path, so it repeats the stock settings.
        $gridBuilder->addField(
            TwigField::create('name', '@MoneiSyliusPlugin/admin/payment_method/grid/field/name.html.twig')
                ->setLabel('sylius.ui.payment_method')
                ->setPath('.')
                ->setSortable(true, 'translation.name'),
        );
    }
}
