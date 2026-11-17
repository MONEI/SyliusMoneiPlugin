<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Gateway;

use Doctrine\ORM\Event\PrePersistEventArgs;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;

/**
 * `usePayum` defaults to true and only the admin form clears it for a non-Payum factory,
 * so a MONEI method created by fixtures or the API would go to Payum.
 */
final class ForcePaymentRequestsListener
{
    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof GatewayConfigInterface && GatewayConfiguration::FACTORY_NAME === $entity->getFactoryName()) {
            $entity->setUsePayum(false);
        }
    }
}
