<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Storefront\Subscriber;

use Ruhrcoder\RcAbTesting\Service\AbEventType;
use Ruhrcoder\RcAbTesting\Service\RequestEventRecorder;
use Shopware\Core\Checkout\Customer\Event\CustomerRegisterEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Trackt `customer.registered` als Funnel-Zwischenziel (Registrierung als
 * Conversion-naher Schritt in Lead-orientierten Tests).
 */
final class CustomerRegisteredSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RequestEventRecorder $recorder,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [CustomerRegisterEvent::class => 'onCustomerRegistered'];
    }

    public function onCustomerRegistered(CustomerRegisterEvent $event): void
    {
        $this->recorder->record(
            AbEventType::CUSTOMER_REGISTERED,
            null,
            ['customer_id' => $event->getCustomer()->getId()],
            $event->getContext(),
        );
    }
}
