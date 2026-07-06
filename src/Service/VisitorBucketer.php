<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service;

use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantEntity;

/**
 * Reiner, seiteneffektfreier Bucketing-Algorithmus. Bewusst ohne DAL- oder
 * Request-Abhängigkeit, damit Determinismus und Gewichts-Verteilung ohne
 * Datenbank getestet werden können (Trennung Algorithmus von I/O).
 */
final class VisitorBucketer
{
    /**
     * Sticky-Bucket 0..99 für einen Besucher in einem Experiment. Gleicher
     * Besucher und Schlüssel liefern garantiert denselben Bucket (Determinismus).
     */
    public function bucketFor(string $visitorId, string $experimentKey): int
    {
        return $this->hashToBucket($visitorId . '|variant|' . $experimentKey);
    }

    /**
     * Entscheidet, ob der Besucher überhaupt am Experiment teilnimmt. Eigener
     * Hash-Salt, damit die Teilnahme-Würfelung unabhängig vom Varianten-Bucket
     * ist (sonst korrelierten beide und verzerrten die Verteilung).
     */
    public function participates(string $visitorId, string $experimentKey, int $trafficAllocationPct): bool
    {
        if ($trafficAllocationPct >= 100) {
            return true;
        }

        if ($trafficAllocationPct <= 0) {
            return false;
        }

        return $this->hashToBucket($visitorId . '|alloc|' . $experimentKey) < $trafficAllocationPct;
    }

    /**
     * Wählt die Variante anhand des Buckets über kumulierte Gewichte. Stabile
     * Sortierung nach technicalKey hält die Zuordnung reproduzierbar, auch wenn
     * die DAL die Varianten in wechselnder Reihenfolge liefert.
     *
     * @param list<AbVariantEntity> $variants
     */
    public function pick(int $bucket, array $variants): ?AbVariantEntity
    {
        $ordered = $this->orderByTechnicalKey($variants);

        $cumulative = 0;
        foreach ($ordered as $variant) {
            $cumulative += $variant->getWeight();
            if ($bucket < $cumulative) {
                return $variant;
            }
        }

        // Bucket liegt oberhalb der Gewichtssumme (Summe < 100): keine Zuteilung.
        return null;
    }

    private function hashToBucket(string $input): int
    {
        $digest = hash('sha256', $input, true);
        $unpacked = unpack('N', substr($digest, 0, 4));
        if ($unpacked === false) {
            // sha256 liefert immer 32 Bytes; dieser Pfad ist praktisch unerreichbar,
            // hält aber PHPStan-Level-8 ohne Suppression sauber.
            return 0;
        }

        return $unpacked[1] % 100;
    }

    /**
     * @param list<AbVariantEntity> $variants
     *
     * @return list<AbVariantEntity>
     */
    private function orderByTechnicalKey(array $variants): array
    {
        usort(
            $variants,
            static fn (AbVariantEntity $a, AbVariantEntity $b): int => $a->getTechnicalKey() <=> $b->getTechnicalKey(),
        );

        return $variants;
    }
}
