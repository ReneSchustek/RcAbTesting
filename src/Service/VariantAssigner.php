<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service;

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
 * Bestimmt die Variante eines Besuchers für ein Experiment und persistiert die
 * Zuordnung sticky. Erneute Hits desselben Besuchers liefern dieselbe Variante.
 *
 * `rc_ab_assignment` verlangt `sales_channel_id` und `language_id` (beide
 * Required). Daher nimmt der Service den SalesChannelContext entgegen, der
 * beide Werte liefert und im Storefront-Subscriber ohnehin vorliegt.
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
    ) {
    }

    public function assign(string $experimentTechnicalKey, string $visitorId, SalesChannelContext $salesChannelContext, bool $persistAssignment = true): ?AbVariantEntity
    {
        $context = $salesChannelContext->getContext();

        $experiment = $this->experimentRegistry->getByTechnicalKey($experimentTechnicalKey, $context);
        if ($experiment === null) {
            return null;
        }

        if (!$this->matchesTargeting($experiment, $salesChannelContext)) {
            return null;
        }

        if (!$this->bucketer->participates($visitorId, $experimentTechnicalKey, $experiment->getTrafficAllocationPct())) {
            return null;
        }

        if (!$persistAssignment) {
            // Cookieloser/Bot-Request: deterministisch bucketen, aber nichts persistieren,
            // damit die Stichprobe nicht mit Einmal-„Besuchern" verfälscht wird.
            return $this->bucketer->pick($this->bucketer->bucketFor($visitorId, $experimentTechnicalKey), $this->variantList($experiment));
        }

        // Cross-Device: ist der Kunde angemeldet, folgt die Variante dem Kunden,
        // nicht dem gerätegebundenen Visitor-Cookie.
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
                // Eingeloggter Kunde, dessen Login-Upgrade diese Visitor-Zuordnung nicht
                // erfasst hat: customerId defensiv nachziehen, damit der Tracking-Pfad
                // (er filtert beim eingeloggten Kunden nach customerId) dieselbe
                // Zuordnung findet — sonst Variante sichtbar, Conversion ungetrackt.
                $this->attachCustomer($existing->getId(), $customerId, $context);
            } else {
                $this->touchLastSeen($existing->getId(), $context);
            }

            return $this->resolveVariant($experiment, $existing->getVariantId());
        }

        return $this->createAssignment($experiment, $visitorId, $customerId, $salesChannelContext);
    }

    /**
     * Verknüpft die Geräte-Zuordnungen eines Besuchers mit dem Kunden, sobald
     * dieser sich anmeldet. Hat der Kunde für ein Experiment bereits eine
     * kanonische Zuordnung (anderes Gerät), wird die redundante Besucher-Zeile
     * verworfen — so bleibt es genau eine Zuordnung je (Experiment, Kunde), und
     * angezeigte wie getrackte Variante folgen konsistent dem Kunden.
     */
    public function upgradeAssignmentsToCustomer(string $visitorId, string $customerId, Context $context): void
    {
        $visitorAssignments = $this->assignmentsByVisitor($visitorId, $context);
        if ($visitorAssignments === []) {
            return;
        }

        $customerExperimentIds = $this->experimentIdsWithCustomerAssignment($customerId, $context);

        $updates = [];
        $deletes = [];
        foreach ($visitorAssignments as $assignment) {
            if ($assignment->getCustomerId() === $customerId) {
                continue;
            }

            if (\in_array($assignment->getExperimentId(), $customerExperimentIds, true)) {
                $deletes[] = ['id' => $assignment->getId()];
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
     * Prüft das Sales-Channel- und Rule-Targeting des Experiments gegen den
     * aktuellen Kontext. Ein leeres Targeting-Feld bedeutet „gilt für alle". Die
     * Rule wird nicht selbst ausgewertet — es zählen die bereits im
     * SalesChannelContext aufgelösten Regel-IDs.
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

    private function createAssignment(AbExperimentEntity $experiment, string $visitorId, ?string $customerId, SalesChannelContext $salesChannelContext): ?AbVariantEntity
    {
        $context = $salesChannelContext->getContext();
        $bucket = $this->bucketer->bucketFor($visitorId, $experiment->getTechnicalKey());
        $variant = $this->bucketer->pick($bucket, $this->variantList($experiment));
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
            ]], $context);
        } catch (UniqueConstraintViolationException) {
            // Paralleler Request hat dieselbe Zuordnung bereits angelegt: den
            // bestehenden Datensatz lesen, damit beide Requests dieselbe Variante
            // sehen. Bei angemeldetem Kunden kann der (Experiment, Kunde)-Constraint
            // zuerst greifen, daher diesen zuerst prüfen.
            $existing = $customerId !== null
                ? $this->findCustomerAssignment($experiment->getId(), $customerId, $context)
                : null;
            $existing ??= $this->findExistingAssignment($experiment->getId(), $visitorId, $context);

            return $existing !== null ? $this->resolveVariant($experiment, $existing->getVariantId()) : $variant;
        }

        return $variant;
    }

    private function findExistingAssignment(string $experimentId, string $visitorId, Context $context): ?AbAssignmentEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('experimentId', $experimentId));
        $criteria->addFilter(new EqualsFilter('visitorId', $visitorId));
        $criteria->setLimit(1);

        $assignment = $this->assignmentRepository->search($criteria, $context)->getEntities()->first();

        return $assignment instanceof AbAssignmentEntity ? $assignment : null;
    }

    private function findCustomerAssignment(string $experimentId, string $customerId, Context $context): ?AbAssignmentEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('experimentId', $experimentId));
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));
        // Deterministisch die früheste Zuordnung wählen (Rest-Duplikate aus der
        // Zeit vor dem UNIQUE-Constraint liefern sonst nichtdeterministisch).
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
        $assignments = array_values($this->assignmentRepository->search($criteria, $context)->getEntities()->getElements());

        return $assignments;
    }

    /**
     * @return list<string>
     */
    private function experimentIdsWithCustomerAssignment(string $customerId, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));

        return array_values(array_map(
            static fn (AbAssignmentEntity $assignment): string => $assignment->getExperimentId(),
            $this->assignmentRepository->search($criteria, $context)->getEntities()->getElements(),
        ));
    }

    private function touchLastSeen(string $assignmentId, Context $context): void
    {
        $this->assignmentRepository->update([[
            'id' => $assignmentId,
            'lastSeenAt' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ]], $context);
    }

    /**
     * Zieht die customerId an eine bestehende Visitor-Zuordnung nach (und
     * aktualisiert lastSeenAt in einem Write). Sicher, weil der Aufrufer zuvor
     * geprüft hat, dass für (Experiment, Kunde) noch keine Zuordnung existiert —
     * der UNIQUE(experiment, customer)-Constraint wird also nicht verletzt.
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

        // Zugeordnete Variante existiert nicht mehr (gelöscht) — der Besucher
        // bekommt diesen Request keine Variante, der Funnel bricht sauber ab.
        $this->logger->warning('Zugeordnete Variante {variantId} nicht im Experiment {experiment}', [
            'variantId' => $variantId,
            'experiment' => $experiment->getTechnicalKey(),
        ]);

        return null;
    }

    /**
     * @return list<AbVariantEntity>
     */
    private function variantList(AbExperimentEntity $experiment): array
    {
        $variants = $experiment->getVariants();

        return $variants === null ? [] : array_values($variants->getElements());
    }
}
