<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Storefront\Subscriber;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcAbTesting\Service\AbEventType;
use Ruhrcoder\RcAbTesting\Service\RequestEventRecorder;
use Ruhrcoder\RcAbTesting\Service\VariantAssigner;
use Ruhrcoder\RcAbTesting\Service\VisitorIdResolver;
use Shopware\Core\Checkout\Customer\Event\CustomerLoginEvent;
use Shopware\Core\Framework\Context;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Zeichnet `customer.logged_in` auf und hängt die anonymen Zuordnungen dieses Geräts an den jetzt
 * bekannten Kunden. Danach folgt die Variante dem Kunden auf jedem Gerät; hat er für ein
 * Experiment schon eine Zuordnung von einem anderen Gerät, gewinnt diese.
 */
final class CustomerLoginSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RequestEventRecorder $recorder,
        private readonly VariantAssigner $variantAssigner,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [CustomerLoginEvent::class => 'onCustomerLogin'];
    }

    public function onCustomerLogin(CustomerLoginEvent $event): void
    {
        $customerId = $event->getCustomer()->getId();
        $context = $event->getContext();

        $this->recorder->record(AbEventType::CUSTOMER_LOGGED_IN, null, ['customer_id' => $customerId], $context);

        $this->upgradeAssignments($customerId, $context);
    }

    private function upgradeAssignments(string $customerId, Context $context): void
    {
        $request = $this->requestStack->getMainRequest();
        $visitorId = $request?->attributes->get(VisitorIdResolver::REQUEST_ATTRIBUTE);
        if (!\is_string($visitorId)) {
            return;
        }

        try {
            $this->variantAssigner->upgradeAssignmentsToCustomer($visitorId, $customerId, $context);
        } catch (\Throwable $exception) {
            // Scheitert die Verknüpfung, darf der Login trotzdem nicht abbrechen.
            $this->logger->error('A/B-Customer-Upgrade fehlgeschlagen', [
                // Zwölf Hex-Zeichen eines Hashes statt der Kundenkennung: genug, um Meldungen
                // desselben Kunden zusammenzuführen, ohne die Kennung im Klartext zu protokollieren.
                'customerRef' => substr(hash('sha256', $customerId), 0, 12),
                'exception' => $exception,
            ]);
        }
    }
}
