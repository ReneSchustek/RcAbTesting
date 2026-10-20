<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Subscriber;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcAbTesting\Service\CustomerDataEraserInterface;
use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeleteEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Hängt den Löschweg nach Art. 17 DSGVO an das Löschen eines Kunden. Das Ereignis greift bei jeder
 * Löschung, ob aus der Verwaltung, über die API oder aus einem anderen Plugin.
 *
 * Das Löschen selbst liegt im {@see \Ruhrcoder\RcAbTesting\Service\CustomerDataEraser}; der Abonnent koordiniert nur.
 *
 * Gelöscht wird vor dem Kunden. Die Fremdschlüssel von `rc_ab_assignment` und `rc_ab_event` auf
 * `customer` stehen auf `ON DELETE SET NULL`. Nach dem Löschen (`customer.deleted`) hat die Datenbank
 * `customer_id` schon geleert, und ein Löschen über die Kundenkennung träfe keine Zeile mehr; die Daten
 * blieben, nur ohne Zuordnung. `EntityDeleteEvent` feuert vorher, in derselben Transaktion wie das Löschen
 * des Kunden; scheitert das, rollt auch dieses Löschen zurück. So macht es der Kern selbst
 * (`CustomerBeforeDeleteSubscriber`).
 */
final class CustomerDeletedSubscriber implements EventSubscriberInterface
{
    private const LOG_CONTEXT = 'ruhrcoder_ab_testing.customer_deleted';

    public function __construct(
        private readonly CustomerDataEraserInterface $eraser,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [EntityDeleteEvent::class => 'onBeforeCustomerDelete'];
    }

    public function onBeforeCustomerDelete(EntityDeleteEvent $event): void
    {
        $ids = array_values(array_filter($event->getIds(CustomerDefinition::ENTITY_NAME), \is_string(...)));
        if ($ids === []) {
            return;
        }

        try {
            $deleted = $this->eraser->eraseForCustomers($ids);

            if ($deleted > 0) {
                $this->logger->info('A/B-Daten gelöschter Kunden entfernt', [
                    'context' => self::LOG_CONTEXT,
                    'rows' => $deleted,
                ]);
            }
        } catch (\Throwable $error) {
            // Ein Fehler beim Aufräumen darf nicht zum Aufrufer durchschlagen und die
            // Kundenlöschung scheitern lassen. Er wird protokolliert, und die Aufbewahrungsfrist
            // anonymisiert die Zeilen später ohnehin.
            $this->logger->error('A/B-Daten gelöschter Kunden konnten nicht entfernt werden', [
                'context' => self::LOG_CONTEXT,
                'exception' => $error,
            ]);
        }
    }
}
