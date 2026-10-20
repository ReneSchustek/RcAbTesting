<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Löscht die A/B-Daten einzelner Kunden, wenn Shopware den Kunden löscht (Art. 17 DSGVO).
 *
 * Ohne diesen Weg blieben Zuordnungen und Ereignisse nach der Kundenlöschung stehen, samt
 * Kunden-, Bestell- und Warenkorb-Kennung in `meta`. Die Aufbewahrungsfrist anonymisierte sie
 * zwar irgendwann, aber ein Löschverlangen erfüllt das nicht.
 *
 * Die Zeilen werden gelöscht statt anonymisiert, der Kunde fällt also aus Zähler und Nenner.
 * Laufende Experimente verschieben sich dadurch minimal; die Alternative wäre, Daten zu behalten,
 * die gelöscht gehören.
 */
final class CustomerDataEraser implements CustomerDataEraserInterface
{
    /**
     * Keine der beiden Tabellen verweist per Fremdschlüssel auf die andere, die Reihenfolge ist
     * frei. Gelöscht wird über `customer_id`; Ereignisse aus der Zeit vor dem Anmelden tragen
     * diese Kennung nur, wenn {@see VariantAssigner::upgradeAssignmentsToCustomer()} sie umgehängt hat.
     */
    private const TABLES = ['rc_ab_event', 'rc_ab_assignment'];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param list<string> $customerIds Hex-Kennungen; ungültige werden verworfen
     *
     * @return int Anzahl gelöschter Zeilen
     */
    public function eraseForCustomers(array $customerIds): int
    {
        $valid = array_values(array_filter($customerIds, Uuid::isValid(...)));

        // Ohne gültige Kennung gäbe es nichts für `IN (…)`. Der frühe Ausstieg hält jede spätere
        // Umformung der Abfrage davon ab, versehentlich ohne Bedingung die ganze Tabelle zu leeren.
        if ($valid === []) {
            return 0;
        }

        $binaryIds = array_map(Uuid::fromHexToBytes(...), $valid);
        $deleted = 0;

        foreach (self::TABLES as $table) {
            $deleted += (int) $this->connection->executeStatement(
                sprintf('DELETE FROM `%s` WHERE `customer_id` IN (:ids)', $table),
                ['ids' => $binaryIds],
                ['ids' => ArrayParameterType::BINARY],
            );
        }

        return $deleted;
    }
}
