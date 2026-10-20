<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Ruhrcoder\RcAbTesting\Core\Content\AbAssignment\AbAssignmentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbAssignment\AbAssignmentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Bestimmt die Variante eines Besuchers für ein Experiment und speichert die Zuordnung, damit
 * jeder weitere Aufruf dieselbe Variante liefert. Ist der Kunde angemeldet, folgt die Variante
 * dem Kunden statt dem Gerät.
 *
 * Der Service nimmt den `SalesChannelContext`, weil `rc_ab_assignment` Verkaufskanal und Sprache
 * als Pflichtfelder hat und der Kontext beide liefert; außerdem trägt er Kunde und Regel-IDs für
 * das Targeting.
 */
final class VariantAssigner
{
    /**
     * @param EntityRepository<AbAssignmentCollection> $assignmentRepository
     */
    public function __construct(
        private readonly ExperimentRegistry $experimentRegistry,
        private readonly EntityRepository $assignmentRepository,
        private readonly VisitorBucketer $bucketer,
        private readonly LoggerInterface $logger,
        private readonly Connection $connection,
    ) {
    }

    /**
     * Null, wenn das Experiment nicht läuft, das Targeting nicht passt, der Besucher außerhalb der
     * Traffic-Allocation liegt, keine Variante seinen Bucket abdeckt oder die gespeicherte
     * Variante nicht mehr im Experiment steht.
     *
     * Gerufen wird nur für Besucher mit dauerhafter Kennung; jede Zuordnung wird gespeichert. `$device` ist eine
     * Geräteklasse aus {@see AbDeviceClass} und landet nur in neu angelegten Zuordnungen.
     */
    public function assign(
        string $experimentTechnicalKey,
        string $visitorId,
        SalesChannelContext $salesChannelContext,
        ?string $device = null,
    ): ?AbVariantEntity {
        $context = $salesChannelContext->getContext();

        $experiment = $this->experimentRegistry->getByTechnicalKey($experimentTechnicalKey, $context);
        if ($experiment === null) {
            return null;
        }

        if (!$this->matchesTargeting($experiment, $salesChannelContext)) {
            return null;
        }

        if (!$this->bucketer->participates(
            $visitorId,
            $experimentTechnicalKey,
            $experiment->getTrafficAllocationPct()
        )) {
            return null;
        }

        return $this->storedOrNewVariant($experiment, $visitorId, $salesChannelContext, $device);
    }

    /**
     * Die gespeicherte Variante des Kunden, sonst die des Geräts, sonst eine neue Zuordnung.
     */
    private function storedOrNewVariant(
        AbExperimentEntity $experiment,
        string $visitorId,
        SalesChannelContext $salesChannelContext,
        ?string $device,
    ): ?AbVariantEntity {
        $context = $salesChannelContext->getContext();

        // Ist der Kunde angemeldet, gilt seine Zuordnung vor der des Geräts, damit er auf jedem
        // Gerät dieselbe Variante sieht.
        $customerId = $salesChannelContext->getCustomer()?->getId();
        if ($customerId !== null) {
            $byCustomer = $this->findCustomerAssignment($experiment->getId(), $customerId, $context);
            if ($byCustomer !== null) {
                $this->touchLastSeen($byCustomer->getId(), $context);

                return $this->resolveVariant($experiment, $byCustomer->getVariantId());
            }
        }

        $existing = $this->findExistingAssignment($experiment->getId(), $visitorId, $context);
        if ($existing !== null) {
            if ($customerId !== null && $existing->getCustomerId() === null) {
                // Angemeldet, aber die Zuordnung des Geräts trägt noch keinen Kunden, etwa weil
                // die Umstellung beim Anmelden sie nicht erfasst hat. `EventTracker` sucht beim
                // angemeldeten Kunden nach `customerId`; ohne Nachtrag sähe der Kunde die
                // Variante, seine Conversion würde aber nicht gezählt.
                $this->attachCustomer($existing->getId(), $customerId, $context);
            } else {
                $this->touchLastSeen($existing->getId(), $context);
            }

            return $this->resolveVariant($experiment, $existing->getVariantId());
        }

        return $this->createAssignment($experiment, $visitorId, $customerId, $salesChannelContext, $device);
    }

    /**
     * Ob ein laufendes Experiment mit diesem Schlüssel im aktuellen Kontext gilt, ohne Blick auf
     * die Traffic-Allocation des einzelnen Besuchers. Der Cache-Wächter braucht genau das: Eine
     * Seite mit anwendbarem Experiment darf nicht in den Cache, sonst bekämen Teilnehmer die
     * gecachte Kontrollseite eines ausgeschlossenen Besuchers.
     */
    public function appliesTo(string $experimentTechnicalKey, SalesChannelContext $salesChannelContext): bool
    {
        $experiment = $this->experimentRegistry->getByTechnicalKey(
            $experimentTechnicalKey,
            $salesChannelContext->getContext()
        );

        return $experiment !== null && $this->matchesTargeting($experiment, $salesChannelContext);
    }

    /**
     * Hängt beim Anmelden die Zuordnungen des Geräts an den Kunden. Hat der Kunde in einem
     * Experiment schon eine Zuordnung von einem anderen Gerät, wird die des aktuellen Geräts
     * gelöscht; so bleibt je Experiment und Kunde genau eine, und angezeigte wie gezählte Variante
     * folgen dem Kunden.
     *
     * Behält die Gerätezuordnung ihren Platz, bleiben ihre Ereignisse aus der Zeit vor dem
     * Anmelden ohne `customer_id`; sie hängen weiter über `visitor_id` an der Zuordnung.
     *
     * Eine Zuordnung, die schon einem anderen Kunden gehört, bleibt unangetastet. Auf einem geteilten
     * Gerät schriebe die Übernahme sie sonst auf den zweiten Kunden um, und der erste verlöre seine
     * Zuordnung samt Messung. Der zweite Kunde wird in diesem Experiment auf dem
     * Gerät dann nicht gezählt; das ist die kleinere Verzerrung.
     */
    public function upgradeAssignmentsToCustomer(string $visitorId, string $customerId, Context $context): void
    {
        $visitorAssignments = $this->assignmentsByVisitor($visitorId, $context);
        if ($visitorAssignments === []) {
            return;
        }

        $customerVariants = $this->customerVariantByExperiment($customerId, $context);

        $updates = [];
        $deletes = [];
        foreach ($visitorAssignments as $assignment) {
            if ($assignment->getCustomerId() !== null) {
                continue;
            }

            $experimentId = $assignment->getExperimentId();
            if (isset($customerVariants[$experimentId])) {
                $deletes[] = ['id' => $assignment->getId()];
                // Die anonymen Ereignisse der gelöschten Gerätezuordnung wandern zur Zuordnung des
                // Kunden. Ohne Zuordnungszeile fielen sie aus dem Segment-Join, zählten in der
                // Gesamtauswertung aber weiter, und beide Ansichten wichen still voneinander ab.
                $this->reattachAnonymousEvents(
                    $experimentId,
                    $visitorId,
                    $customerId,
                    $customerVariants[$experimentId]
                );
            } else {
                $updates[] = ['id' => $assignment->getId(), 'customerId' => $customerId];
            }
        }

        if ($updates !== []) {
            $this->assignmentRepository->update($updates, $context);
        }
        if ($deletes !== []) {
            $this->assignmentRepository->delete($deletes, $context);
        }
    }

    /**
     * Ein leeres Targeting-Feld heißt „gilt für alle". Die Regel wird nicht selbst ausgewertet;
     * es zählen die Regel-IDs, die Shopware im `SalesChannelContext` schon aufgelöst hat.
     */
    private function matchesTargeting(AbExperimentEntity $experiment, SalesChannelContext $salesChannelContext): bool
    {
        $salesChannelId = $experiment->getSalesChannelId();
        if ($salesChannelId !== null && $salesChannelId !== $salesChannelContext->getSalesChannelId()) {
            return false;
        }

        $ruleId = $experiment->getRuleId();
        if ($ruleId !== null && !\in_array($ruleId, $salesChannelContext->getRuleIds(), true)) {
            return false;
        }

        return true;
    }

    private function createAssignment(
        AbExperimentEntity $experiment,
        string $visitorId,
        ?string $customerId,
        SalesChannelContext $salesChannelContext,
        ?string $device = null,
    ): ?AbVariantEntity {
        $context = $salesChannelContext->getContext();
        $bucket = $this->bucketer->bucketFor($visitorId, $experiment->getTechnicalKey());
        $variant = $this->bucketer->pick($bucket, $experiment->getVariantList());
        if ($variant === null) {
            return null;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        try {
            $this->assignmentRepository->create([[
                'id' => Uuid::randomHex(),
                'experimentId' => $experiment->getId(),
                'variantId' => $variant->getId(),
                'visitorId' => $visitorId,
                'customerId' => $customerId,
                'salesChannelId' => $salesChannelContext->getSalesChannelId(),
                'languageId' => $context->getLanguageId(),
                'assignedAt' => $now,
                'lastSeenAt' => $now,
                'device' => $device,
            ]], $context);
        } catch (UniqueConstraintViolationException) {
            // Ein paralleler Request war schneller. Dessen Zeile gilt, damit beide Requests
            // dieselbe Variante sehen. Beim angemeldeten Kunden kann der Schlüssel
            // (Experiment, Kunde) gegriffen haben statt (Experiment, Besucher), deshalb zuerst
            // nach dem Kunden suchen.
            $existing = $customerId !== null
                ? $this->findCustomerAssignment($experiment->getId(), $customerId, $context)
                : null;
            $existing ??= $this->findExistingAssignment($experiment->getId(), $visitorId, $context);

            return $existing !== null ? $this->resolveVariant($experiment, $existing->getVariantId()) : $variant;
        }

        return $variant;
    }

    private function findExistingAssignment(
        string $experimentId,
        string $visitorId,
        Context $context,
    ): ?AbAssignmentEntity {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('experimentId', $experimentId));
        $criteria->addFilter(new EqualsFilter('visitorId', $visitorId));
        $criteria->setLimit(1);

        $assignment = $this->assignmentRepository->search($criteria, $context)->getEntities()->first();

        return $assignment instanceof AbAssignmentEntity ? $assignment : null;
    }

    private function findCustomerAssignment(
        string $experimentId,
        string $customerId,
        Context $context,
    ): ?AbAssignmentEntity {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('experimentId', $experimentId));
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));
        // Der eindeutige Schlüssel (Experiment, Kunde) lässt nur einen Treffer zu. Die
        // Sortierung wählt dieselbe Zeile wie die Migration, die ihn eingeführt hat, und hält
        // die Wahl eindeutig, falls der Schlüssel in einer Installation fehlt.
        $criteria->addSorting(new FieldSorting('assignedAt', FieldSorting::ASCENDING));
        $criteria->setLimit(1);

        $assignment = $this->assignmentRepository->search($criteria, $context)->getEntities()->first();

        return $assignment instanceof AbAssignmentEntity ? $assignment : null;
    }

    /**
     * @return list<AbAssignmentEntity>
     */
    private function assignmentsByVisitor(string $visitorId, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('visitorId', $visitorId));

        /** @var list<AbAssignmentEntity> $assignments */
        $assignments = array_values(
            $this->assignmentRepository->search(
                $criteria,
                $context
            )->getEntities()->getElements()
        );

        return $assignments;
    }

    /**
     * Die Variante des Kunden je Experiment. Der eindeutige Schlüssel (Experiment, Kunde) lässt
     * je Experiment nur eine Zeile zu; sollten es doch mehrere sein, gilt die früheste.
     *
     * @return array<string, string> Experiment-Id (hex) => Varianten-Id (hex)
     */
    private function customerVariantByExperiment(string $customerId, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));
        $criteria->addSorting(new FieldSorting('assignedAt', FieldSorting::ASCENDING));

        $map = [];
        $found = $this->assignmentRepository->search($criteria, $context)->getEntities();
        foreach ($found as $assignment) {
            $map[$assignment->getExperimentId()] ??= $assignment->getVariantId();
        }

        return $map;
    }

    /**
     * Schreibt die anonymen Ereignisse (`customer_id` NULL) eines Besuchers in einem Experiment
     * auf den Kunden und dessen Variante um. Der Kunde folgt über alle Geräte einer Variante; so
     * zählen seine Ereignisse von vor dem Anmelden in Gesamt-, Segment- und Funnel-Auswertung bei
     * derselben Variante.
     *
     * Ein einzelnes UPDATE über DBAL statt DAL: Es betrifft viele Zeilen, läuft nur beim Anmelden,
     * und `rc_ab_event` ist ein reines Protokoll ohne Abonnenten auf Schreibereignisse.
     */
    private function reattachAnonymousEvents(
        string $experimentId,
        string $visitorId,
        string $customerId,
        string $variantId,
    ): void {
        $this->connection->executeStatement(
            'UPDATE rc_ab_event
             SET customer_id = :customerId, variant_id = :variantId
             WHERE experiment_id = :experimentId AND visitor_id = :visitorId AND customer_id IS NULL',
            [
                'customerId' => Uuid::fromHexToBytes($customerId),
                'variantId' => Uuid::fromHexToBytes($variantId),
                'experimentId' => Uuid::fromHexToBytes($experimentId),
                'visitorId' => $visitorId,
            ],
        );
    }

    private function touchLastSeen(string $assignmentId, Context $context): void
    {
        $this->assignmentRepository->update([[
            'id' => $assignmentId,
            'lastSeenAt' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ]], $context);
    }

    /**
     * Trägt den Kunden in eine Gerätezuordnung nach und setzt `lastSeenAt` im selben
     * Schreibvorgang. Der Aufrufer hat vorher festgestellt, dass der Kunde in diesem Experiment noch
     * keine Zuordnung hat; der Schlüssel (Experiment, Kunde) kann also nicht anschlagen.
     */
    private function attachCustomer(string $assignmentId, string $customerId, Context $context): void
    {
        $this->assignmentRepository->update([[
            'id' => $assignmentId,
            'customerId' => $customerId,
            'lastSeenAt' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ]], $context);
    }

    private function resolveVariant(AbExperimentEntity $experiment, string $variantId): ?AbVariantEntity
    {
        $variant = $experiment->getVariants()?->get($variantId);
        if ($variant instanceof AbVariantEntity) {
            return $variant;
        }

        // Die gespeicherte Variante fehlt in der Variantenliste des Experiments, etwa weil sie
        // gelöscht wurde. Der Besucher sieht in diesem Request die Kontrollfassung, statt dass
        // eine Ausnahme die Seite abbricht.
        $this->logger->warning('Zugeordnete Variante {variantId} nicht im Experiment {experiment}', [
            'variantId' => $variantId,
            'experiment' => $experiment->getTechnicalKey(),
        ]);

        return null;
    }

}
