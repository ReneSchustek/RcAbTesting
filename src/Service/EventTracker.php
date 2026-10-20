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
 * Schreibt Funnel-Ereignisse in `rc_ab_event`, je Zuordnung des Besuchers in einem laufenden
 * Experiment eine Zeile. So zählt jedes Experiment für sich, auch wenn ein Besucher an mehreren
 * teilnimmt.
 *
 * Geschrieben wird synchron im Request. Eine Warteschlange lohnt erst, wenn das Volumen den
 * Request spürbar bremst; bis dahin ist der direkte Schreibweg leichter zu warten und zu prüfen.
 */
final class EventTracker implements ResetInterface
{
    /** @var array<string, list<AbAssignmentEntity>> Zuordnungen je Kunde und Besucher, gültig für einen Request. */
    private array $assignmentMemo = [];

    /** @var array<string, true> Zuordnungs-IDs, deren `last_seen_at` in diesem Request schon nachgezogen wurde. */
    private array $seenMemo = [];

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
     * Ohne Zuordnung in einem laufenden Experiment schreibt der Aufruf nichts; Besucher außerhalb
     * aller Tests hinterlassen keine Zeilen.
     *
     * @param array<string, mixed> $meta Zusatzangaben; `order_id` dient bei Bestellungen der Entdopplung
     */
    public function track(
        string $eventType,
        ?float $value,
        array $meta,
        string $visitorId,
        Context $context,
        ?string $customerId = null,
    ): void {
        if (!AbEventType::isValid($eventType)) {
            // Protokollieren und verwerfen statt werfen: Ein fehlerhafter Aufruf aus der
            // Storefront darf den Request nicht abbrechen.
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

        // `last_seen_at` darf nie hinter dem jüngsten Ereignis zurückliegen, denn die
        // Anonymisierung richtet sich nach der letzten Aktivität. Läge die Zuordnung zurück,
        // bekäme sie eine gehashte Kennung, während ihre Ereignisse die klare behalten, und der
        // Join über `visitor_id` risse ab.
        $this->markAssignmentsSeen($assignments, $context);

        if ($eventType === AbEventType::CHECKOUT_ORDER_PLACED) {
            // Eine Bestellung zählt je Experiment nur einmal. Wird dasselbe
            // `CheckoutOrderPlacedEvent` erneut ausgelöst, verdoppelten sich sonst Umsatz und
            // durchschnittlicher Bestellwert. Die `order_id` ist eine UUID und taugt als Schlüssel.
            $assignments = $this->withoutAlreadyRecordedOrder($assignments, $meta, $visitorId, $customerId, $context);
            if ($assignments === []) {
                return;
            }
        }

        $this->eventRepository->create($this->eventRows($assignments, $eventType, $value, $meta, $visitorId), $context);
    }

    /**
     * Eine Ereigniszeile je laufender Zuordnung, alle mit demselben Zeitpunkt.
     *
     * @param list<AbAssignmentEntity> $assignments
     * @param array<string, mixed>     $meta
     *
     * @return list<array<string, mixed>>
     */
    private function eventRows(
        array $assignments,
        string $eventType,
        ?float $value,
        array $meta,
        string $visitorId,
    ): array {
        $cleanMeta = $this->sanitizeMeta($meta);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return array_map(
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
     * Zuordnungen des Besuchers in laufenden Experimenten. Ist der Kunde angemeldet, wird nach
     * dem Kunden gesucht wie im Anzeigepfad, sonst nach dem Besucher-Cookie; so stimmen
     * angezeigte und gezählte Variante auch über Geräte hinweg überein. Beendete und pausierte
     * Experimente bleiben außen vor, sonst änderte jede spätere Bestellung ihre Zahlen im
     * Nachhinein.
     *
     * Das Targeting (Verkaufskanal, Regel) wird hier nicht geprüft. Es entscheidet nur über den
     * Eintritt in {@see VariantAssigner}; wer zugeordnet ist, bleibt Teilnehmer. Ein später
     * geändertes Targeting nähme sonst schon gezählten Teilnehmern ihre Conversions.
     *
     * @param list<string> $runningExperimentIds
     *
     * @return list<AbAssignmentEntity>
     */
    private function runningAssignments(
        string $visitorId,
        ?string $customerId,
        array $runningExperimentIds,
        Context $context,
    ): array {
        // Mehrere Funnel-Subscriber feuern im selben Request; die Abfrage läuft deshalb nur
        // einmal je Kunde und Besucher. `reset()` leert den Speicher zwischen Requests.
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
        // Je Experiment gibt es höchstens eine Zuordnung pro Besucher und pro Kunde (eindeutige
        // Schlüssel), mehr Treffer als laufende Experimente kann es also nicht geben.
        $criteria->setLimit(\count($runningExperimentIds));

        /** @var list<AbAssignmentEntity> $assignments */
        $assignments = array_values(
            $this->assignmentRepository->search(
                $criteria,
                $context
            )->getEntities()->getElements()
        );

        return $this->assignmentMemo[$memoKey] = $assignments;
    }

    /**
     * Lässt die Zuordnungen weg, deren Experiment für diese Bestellung schon ein
     * `checkout.order_placed` hat. Ohne `order_id` in `meta` gibt es nichts zum Vergleichen;
     * dann bleiben alle Zuordnungen.
     *
     * Die Datenbank filtert nach Ereignistyp, Experiment und Person; die `order_id` in `meta`
     * vergleicht erst die Schleife.
     *
     * @param list<AbAssignmentEntity> $assignments
     * @param array<string, mixed>     $meta
     *
     * @return list<AbAssignmentEntity>
     */
    private function withoutAlreadyRecordedOrder(
        array $assignments,
        array $meta,
        string $visitorId,
        ?string $customerId,
        Context $context,
    ): array {
        $orderId = $meta['order_id'] ?? null;
        if (!\is_string($orderId) || $orderId === '') {
            return $assignments;
        }

        $experimentIds = array_values(array_unique(array_map(
            static fn (AbAssignmentEntity $assignment): string => $assignment->getExperimentId(),
            $assignments,
        )));

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('eventType', AbEventType::CHECKOUT_ORDER_PLACED));
        $criteria->addFilter(new EqualsAnyFilter('experimentId', $experimentIds));
        // Dieselbe Person wie in `runningAssignments()`, damit nur ihre eigenen Bestellungen
        // geladen werden und nicht alle des Experiments.
        $criteria->addFilter($customerId !== null
            ? new EqualsFilter('customerId', $customerId)
            : new EqualsFilter('visitorId', $visitorId));

        $recordedExperimentIds = [];
        $found = $this->eventRepository->search($criteria, $context)->getEntities();
        foreach ($found as $event) {
            $eventMeta = $event->getMeta();
            if (\is_array($eventMeta) && ($eventMeta['order_id'] ?? null) === $orderId) {
                $recordedExperimentIds[$event->getExperimentId()] = true;
            }
        }

        if ($recordedExperimentIds === []) {
            return $assignments;
        }

        return array_values(array_filter(
            $assignments,
            static fn (
                AbAssignmentEntity $assignment,
            ): bool => !isset(
                $recordedExperimentIds[$assignment->getExperimentId()]
            ),
        ));
    }

    /**
     * Je Zuordnung höchstens ein Schreibvorgang pro Request, obwohl eine Seite mehrere
     * Ereignisse auslöst.
     *
     * @param list<AbAssignmentEntity> $assignments
     */
    private function markAssignmentsSeen(array $assignments, Context $context): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $updates = [];
        foreach ($assignments as $assignment) {
            if (isset($this->seenMemo[$assignment->getId()])) {
                continue;
            }
            $this->seenMemo[$assignment->getId()] = true;
            $updates[] = ['id' => $assignment->getId(), 'lastSeenAt' => $now];
        }

        if ($updates !== []) {
            $this->assignmentRepository->update($updates, $context);
        }
    }

    public function reset(): void
    {
        $this->assignmentMemo = [];
        $this->seenMemo = [];
    }

    /**
     * Gibt `meta` nur zurück, wenn es sich als JSON kodieren lässt, sonst ein leeres Array mit
     * Protokolleintrag. Ein nicht kodierbarer Wert (etwa eine Ressource oder ungültiges UTF-8)
     * ließe den Schreibvorgang scheitern, und das Ereignis ginge ganz verloren.
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

        // Der Umweg über Kodieren und Dekodieren macht aus Objekten schlichte Arrays, wie sie
        // später auch aus der Datenbank zurückkommen.
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($encoded, true);

        return $decoded;
    }
}
