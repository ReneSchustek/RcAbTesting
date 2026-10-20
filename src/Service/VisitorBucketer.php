<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service;

use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantEntity;

/**
 * Teilt Besucher allein aus Kennung und Experiment-Schlüssel zu, ohne Datenbank und Request.
 * Derselbe Besucher landet so immer im selben Bucket, auch bevor seine Zuordnung gespeichert ist,
 * und Verteilung wie Determinismus lassen sich ohne Datenbank testen.
 */
final class VisitorBucketer
{
    /**
     * Bucket 0 bis 99, damit die Variantengewichte direkt als Prozent gelten. Gleiche Kennung und
     * gleicher Schlüssel ergeben immer denselben Bucket.
     */
    public function bucketFor(string $visitorId, string $experimentKey): int
    {
        return $this->hashToBucket($visitorId . '|variant|' . $experimentKey);
    }

    /**
     * Ob der Besucher überhaupt teilnimmt. Der Hash bekommt einen anderen Zusatz als der
     * Varianten-Bucket. Mit demselben Hash nähmen bei 50 % Allocation nur die Buckets 0 bis 49
     * teil, und eine Variante mit den Buckets 50 bis 99 bekäme nie einen Besucher.
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
     * Wählt die Variante, in deren Abschnitt der aufsummierten Gewichte der Bucket fällt. Sortiert
     * wird vorher nach `technicalKey`, weil die DAL die Varianten in wechselnder Reihenfolge
     * liefern kann; sonst wechselte derselbe Bucket die Variante.
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

        // Nur bei einer Gewichtssumme unter 100 möglich. Der Start prüft die Summe
        // (`ExperimentIntegrityValidator`), eine spätere Änderung der Gewichte nicht.
        return null;
    }

    /**
     * Die ersten vier Bytes des SHA-256 als vorzeichenlose Zahl, modulo 100. SHA-256 verteilt
     * gleichmäßig; die Schiefe durch das Modulo liegt bei 2^32 Werten unter einem Millionstel.
     */
    private function hashToBucket(string $input): int
    {
        $digest = hash('sha256', $input, true);
        $unpacked = unpack('N', substr($digest, 0, 4));
        if ($unpacked === false) {
            // Unerreichbar, weil SHA-256 immer 32 Bytes liefert. `unpack()` kann laut Signatur
            // aber false liefern, und die statische Analyse verlangt den Zweig.
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
