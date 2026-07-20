<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\Stats;

use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;

/**
 * Baut das Auswertungs-Read-Model einer Experiment-Detailseite: Varianten-
 * Kennzahlen, statistische Auswertung, Segmente (Gerät/Kanal), Zeitverlauf und
 * Funnel. Bewusst aus dem API-Controller herausgelöst (Arbeitspaket AB40): der
 * Controller trägt nur noch den Lifecycle-Schreibpfad, diese Read-Model-
 * Aufbereitung ist ein eigener Belang und dadurch isoliert testbar.
 */
final class ExperimentStatsResponseAssembler
{
    /**
     * @param EntityRepository<SalesChannelCollection> $salesChannelRepository
     */
    public function __construct(
        private readonly ExperimentStatsAggregator $statsAggregator,
        private readonly ExperimentSegmentAggregator $segmentAggregator,
        private readonly ExperimentTimeSeriesAggregator $timeSeriesAggregator,
        private readonly ExperimentFunnelAggregator $funnelAggregator,
        private readonly ExperimentEvaluator $evaluator,
        private readonly EntityRepository $salesChannelRepository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function assemble(AbExperimentEntity $experiment, Context $context): array
    {
        $stats = $this->statsAggregator->aggregate($experiment);

        return [
            'experimentId' => $experiment->getId(),
            'variants' => $this->buildVariants($experiment, $stats),
            'evaluation' => $this->evaluator->evaluate($experiment, $stats),
            'segments' => [
                'device' => $this->buildSegment($experiment, ExperimentSegmentAggregator::DEVICE, $context),
                'salesChannel' => $this->buildSegment($experiment, ExperimentSegmentAggregator::SALES_CHANNEL, $context),
            ],
            'timeSeries' => $this->buildTimeSeries($experiment),
            'funnel' => $this->buildFunnel($experiment),
        ];
    }

    /**
     * @param array<string, array{assignments: int, conversions: int, orders: int, revenue: float, revenueSumSq: float}> $stats
     *
     * @return list<array<string, mixed>>
     */
    private function buildVariants(AbExperimentEntity $experiment, array $stats): array
    {
        $variants = [];
        foreach ($experiment->getVariantList() as $variant) {
            $variantStats = $stats[$variant->getId()] ?? ['assignments' => 0, 'conversions' => 0, 'orders' => 0, 'revenue' => 0.0, 'revenueSumSq' => 0.0];
            $variants[] = [
                'id' => $variant->getId(),
                'technicalKey' => $variant->getTechnicalKey(),
                'isControl' => $variant->isControl(),
                'assignments' => $variantStats['assignments'],
                'conversions' => $variantStats['conversions'],
                'rate' => $variantStats['assignments'] > 0 ? $variantStats['conversions'] / $variantStats['assignments'] : null,
                'orders' => $variantStats['orders'],
                'revenue' => $variantStats['revenue'],
                // Durchschnittlicher Bestellwert (Umsatz / Bestellungen).
                'aov' => $variantStats['orders'] > 0 ? $variantStats['revenue'] / $variantStats['orders'] : null,
                // Umsatz je Besucher — die A/B-Kennzahl, die Conversion UND Bestellwert
                // zugleich erfasst (Umsatz / Zuordnungen).
                'revenuePerVisitor' => $variantStats['assignments'] > 0 ? $variantStats['revenue'] / $variantStats['assignments'] : null,
            ];
        }

        return $variants;
    }

    /**
     * Funnel je Variante: distinct Besucher je Stufe, Bezugsgröße sind die
     * Zuordnungen (Teilnehmer). Die Admin-Ansicht zeigt daraus den Anteil je Stufe
     * und den Drop-off zwischen den Stufen.
     *
     * @return array{stages: list<string>, variants: list<array{technicalKey: string, isControl: bool, assignments: int, stages: list<int>}>}
     */
    private function buildFunnel(AbExperimentEntity $experiment): array
    {
        $byVariant = $this->funnelAggregator->aggregate($experiment);

        $variants = [];
        foreach ($experiment->getVariantList() as $variant) {
            $data = $byVariant[$variant->getId()] ?? ['assignments' => 0, 'stages' => []];
            $stageCounts = [];
            foreach (ExperimentFunnelAggregator::STAGES as $stage) {
                $stageCounts[] = $data['stages'][$stage] ?? 0;
            }
            $variants[] = [
                'technicalKey' => $variant->getTechnicalKey(),
                'isControl' => $variant->isControl(),
                'assignments' => $data['assignments'],
                'stages' => $stageCounts,
            ];
        }

        return ['stages' => ExperimentFunnelAggregator::STAGES, 'variants' => $variants];
    }

    /**
     * Zeitverlauf je Variante: die täglichen Zuwächse (Zuordnungen, Conversions,
     * Umsatz). Die Admin-Ansicht bildet daraus den kumulativen Verlauf der
     * gewählten Kennzahl. Nur Varianten mit mindestens einem Datenpunkt.
     *
     * @return list<array{technicalKey: string, isControl: bool, points: list<array{date: string, assignments: int, conversions: int, revenue: float}>}>
     */
    private function buildTimeSeries(AbExperimentEntity $experiment): array
    {
        $byVariant = $this->timeSeriesAggregator->aggregate($experiment);
        if ($byVariant === []) {
            return [];
        }

        $series = [];
        foreach ($experiment->getVariantList() as $variant) {
            $points = $byVariant[$variant->getId()] ?? [];
            if ($points === []) {
                continue;
            }
            $series[] = [
                'technicalKey' => $variant->getTechnicalKey(),
                'isControl' => $variant->isControl(),
                'points' => $points,
            ];
        }

        return $series;
    }

    /**
     * Baut die Segment-Auswertung einer Dimension: je Segment-Wert die Varianten-
     * Kennzahlen plus dieselbe statistische Auswertung wie im Gesamtbild. Nach
     * Segmentgröße (Zuordnungen) absteigend sortiert, damit das relevanteste
     * Segment oben steht.
     *
     * @return list<array{segment: string, name: string|null, size: int, variants: list<array{technicalKey: string, isControl: bool, assignments: int, conversions: int, rate: float|null, revenuePerVisitor: float|null}>, evaluation: array<string, mixed>}>
     */
    private function buildSegment(AbExperimentEntity $experiment, string $dimension, Context $context): array
    {
        $bySegment = $this->segmentAggregator->aggregate($experiment, $dimension);
        if ($bySegment === []) {
            return [];
        }

        $names = $dimension === ExperimentSegmentAggregator::SALES_CHANNEL
            ? $this->salesChannelNames(array_keys($bySegment), $context)
            : [];

        $segments = [];
        foreach ($bySegment as $segmentValue => $variantStats) {
            $size = array_sum(array_map(static fn (array $stat): int => $stat['assignments'], $variantStats));
            $segments[] = [
                'segment' => (string) $segmentValue,
                'name' => $names[$segmentValue] ?? null,
                'size' => $size,
                'variants' => $this->buildSegmentVariants($experiment, $variantStats),
                'evaluation' => $this->evaluator->evaluate($experiment, $variantStats),
            ];
        }

        usort($segments, static fn (array $a, array $b): int => $b['size'] <=> $a['size']);

        return $segments;
    }

    /**
     * @param array<string, array{assignments: int, conversions: int, revenue: float, revenueSumSq: float}> $variantStats
     *
     * @return list<array{technicalKey: string, isControl: bool, assignments: int, conversions: int, rate: float|null, revenuePerVisitor: float|null}>
     */
    private function buildSegmentVariants(AbExperimentEntity $experiment, array $variantStats): array
    {
        $rows = [];
        foreach ($experiment->getVariantList() as $variant) {
            $stat = $variantStats[$variant->getId()] ?? ['assignments' => 0, 'conversions' => 0, 'revenue' => 0.0, 'revenueSumSq' => 0.0];
            $rows[] = [
                'technicalKey' => $variant->getTechnicalKey(),
                'isControl' => $variant->isControl(),
                'assignments' => $stat['assignments'],
                'conversions' => $stat['conversions'],
                'rate' => $stat['assignments'] > 0 ? $stat['conversions'] / $stat['assignments'] : null,
                'revenuePerVisitor' => $stat['assignments'] > 0 ? $stat['revenue'] / $stat['assignments'] : null,
            ];
        }

        return $rows;
    }

    /**
     * Auflösung der Verkaufskanal-Namen zu den (hex) Segment-Ids. Nur ein DAL-Read
     * für alle vorkommenden Kanäle.
     *
     * @param list<string> $ids
     *
     * @return array<string, string>
     */
    private function salesChannelNames(array $ids, Context $context): array
    {
        if ($ids === []) {
            return [];
        }

        $channels = $this->salesChannelRepository->search(new Criteria($ids), $context)->getEntities();

        $names = [];
        foreach ($channels as $channel) {
            $names[$channel->getId()] = $channel->getName() ?? $channel->getId();
        }

        return $names;
    }
}
