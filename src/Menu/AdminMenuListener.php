<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Menu;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

final class AdminMenuListener
{
    public function __invoke(MenuBuilderEvent $event): void
    {
        $event->getMenu()->getChild('configuration')
            ?->addChild('monei', ['route' => 'monei_sylius_admin_connect'])
            ->setLabel('monei.gateway_factory.monei')
            ->setLabelAttribute('icon', 'tabler:credit-card');
    }
}
