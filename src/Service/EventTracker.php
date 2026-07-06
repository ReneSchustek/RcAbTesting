<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcAbTesting\Core\Content\AbAssignment\AbAssignmentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbAssignment\AbAssignmentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbEvent\AbEventCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Schreibt Funnel-Events synchron in `rc_ab_event`. Pro offener Zuordnung des
 * Besuchers entsteht genau ein Event, damit die Auswertung jedes Experiment
 * unabhängig zählt. Synchron-INSERT ist eine bewusste MVP-Entscheidung
 * (Memory: einfacher zu warten; Async-Queue erst bei hohem Volumen).
 */
final class EventTracker implements ResetInterface
{
    /** @var array<string, list<AbAssignmentEntity>> Zuordnungen je Besucher/Kunde, memoized pro Request. */
    private array $assignmentMemo = [];

    /**
     * @param EntityRepository<AbAssignmentCollection> $assignmentRepository
     * @param EntityRepository<AbEventCollection>      $eventRepository
     */
    public function __construct(
        private readonly EntityRepository $assignmentRepository,
        private readonly EntityRepository $eventRepository,
        private readonly ExperimentRegistry $experimentRegistry,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function track(string $eventType, ?float $value, array $meta, string $visitorId, Context $context, ?string $customerId = null): void
    {
        if (!AbEventType::isValid($eventType)) {
            // Unbekannter Typ: protokollieren und überspringen statt zu crashen —
            // ein fehlerhafter Storefront-Call darf den Request nicht abreißen.
            $this->logger->warning('Unbekannter A/B-Event-Typ {eventType} verworfen', ['eventType' => $eventType]);

            return;
        }

        $runningIds = $this->runningExperimentIds($context);
        if ($runningIds === []) {
            return;
        }

        $assignments = $this->runningAssignments($visitorId, $customerId, $runningIds, $context);
        if ($assignments === []) {
            return;
        }

        $cleanMeta = $this->sanitizeMeta($meta);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $payload = array_map(
            fn (AbAssignmentEntity $assignment): array => [
                'id' => Uuid::randomHex(),
                'experimentId' => $assignment->getExperimentId(),
                'variantId' => $assignment->getVariantId(),
                'visitorId' => $visitorId,
                'customerId' => $assignment->getCustomerId(),
                'eventType' => $eventType,
                'eventValue' => $value,
                'meta' => $cleanMeta,
                'occurredAt' => $now,
            ],
            $assignments,
        );

        $this->eventRepository->create($payload, $context);
    }

    /**
     * @return list<string>
     */
    private function runningExperimentIds(Context $context): array
    {
        return array_map(
            static fn (AbExperimentEntity $experiment): string => $experiment->getId(),
            $this->experimentRegistry->getRunning($context),
        );
    }

    /**
     * Zuordnungen, deren Experiment noch läuft. Ist der Kunde angemeldet, folgt
     * die Attribution dem Kunden (wie der Anzeige-Pfad), sonst dem Besucher-Cookie
     * — so stimmen angezeigte und getrackte Variante geräteübergreifend überein.
     * Beendete/pausierte Experimente bleiben außen vor, sonst schriebe jede
     * spätere Bestellung Events in alte Experimente (Post-hoc-Verzerrung).
     *
     * Bewusst wird hier KEIN Targeting mehr geprüft: Sales-Channel-/Rule-Targeting
     * ist ein Eintritts-Gate im Anzeige-Pfad (VariantAssigner::matchesTargeting).
     * Wer bereits zugeordnet ist, bleibt Teilnehmer und wird weiter getrackt —
     * eine nachträgliche Targeting-Änderung entzieht bestehende Zuordnungen nicht
     * (sonst gingen bereits gezählte Teilnehmer verloren).
     *
     * @param list<string> $runningExperimentIds
     *
     * @return list<AbAssignmentEntity>
     */
    private function runningAssignments(string $visitorId, ?string $customerId, array $runningExperimentIds, Context $context): array
    {
        // Mehrere Funnel-Subscriber feuern pro Request — dieselbe Zuordnungs-Abfrage
        // nur einmal je Besucher/Kunde ausführen (Hot-Path). kernel.reset leert das Memo.
        $memoKey = ($customerId ?? '') . '|' . $visitorId;
        if (isset($this->assignmentMemo[$memoKey])) {
            return $this->assignmentMemo[$memoKey];
        }

        $criteria = new Criteria();
        if ($customerId !== null) {
            $criteria->addFilter(new EqualsFilter('customerId', $customerId));
        } else {
            $criteria->addFilter(new EqualsFilter('visitorId', $visitorId));
        }
        $criteria->addFilter(new EqualsAnyFilter('experimentId', $runningExperimentIds));
        $criteria->setLimit(\count($runningExperimentIds));

        /** @var list<AbAssignmentEntity> $assignments */
        $assignments = array_values($this->assignmentRepository->search($criteria, $context)->getEntities()->getElements());

        return $this->assignmentMemo[$memoKey] = $assignments;
    }

    public function reset(): void
    {
        $this->assignmentMemo = [];
    }

    /**
     * Stellt sicher, dass die Metadaten JSON-serialisierbar sind. Nicht
     * kodierbare Werte (z.B. Resourcen) würden den DAL-Write sprengen — in dem
     * Fall fällt das Plugin auf ein leeres Objekt zurück und protokolliert.
     *
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private function sanitizeMeta(array $meta): array
    {
        if ($meta === []) {
            return [];
        }

        $encoded = json_encode($meta);
        if ($encoded === false) {
            $this->logger->warning('A/B-Event-Meta nicht JSON-serialisierbar, auf leer gesetzt', [
                'error' => json_last_error_msg(),
            ]);

            return [];
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($encoded, true);

        return $decoded;
    }
}
