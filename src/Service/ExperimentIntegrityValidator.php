<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service;

use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentTestType;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantEntity;

/**
 * Prüft die Konsistenzregeln, die ein Experiment vor dem Start erfüllen muss:
 * mindestens zwei Varianten, Gewichtssumme genau 100, keine negativen Gewichte
 * und genau eine Control. Reine Prüf-Logik ohne I/O, damit sie sowohl vom
 * API-Controller als auch von einem Schreib-Guard genutzt und isoliert getestet
 * werden kann (eine Quelle der Wahrheit statt Duplikat je Schreibpfad).
 *
 * Verletzungen werden als stabiler Code zurückgegeben (sprachneutral), damit die
 * Admin-UI sie lokalisiert anzeigen kann. `messageFor()` liefert die deutsche
 * Meldung fürs Server-Logging.
 */
final class ExperimentIntegrityValidator
{
    public const VIOLATION_TOO_FEW_VARIANTS = 'tooFewVariants';
    public const VIOLATION_NEGATIVE_WEIGHT = 'negativeWeight';
    public const VIOLATION_WEIGHT_SUM = 'weightSum';
    public const VIOLATION_CONTROL_COUNT = 'controlCount';
    public const VIOLATION_CMS_PAGE_MISSING = 'cmsPageMissing';

    private const MESSAGES = [
        self::VIOLATION_TOO_FEW_VARIANTS => 'Ein Experiment braucht mindestens zwei Varianten.',
        self::VIOLATION_NEGATIVE_WEIGHT => 'Varianten-Gewichte dürfen nicht negativ sein.',
        self::VIOLATION_WEIGHT_SUM => 'Die Varianten-Gewichte müssen zusammen 100 ergeben.',
        self::VIOLATION_CONTROL_COUNT => 'Ein Experiment braucht genau eine Control-Variante.',
        self::VIOLATION_CMS_PAGE_MISSING => 'Bei einem CMS-Seiten-Test braucht jede Variante eine ausgewählte CMS-Seite.',
    ];

    /**
     * Erster Verletzungs-Code, oder null wenn startklar.
     */
    public function firstStartViolation(AbExperimentEntity $experiment): ?string
    {
        $variants = $this->variants($experiment);

        $violation = $this->firstViolationForVariants($variants);
        if ($violation !== null) {
            return $violation;
        }

        // CMS-Seiten-Test: jede Variante muss eine CMS-Seite tragen, sonst
        // gaebe es fuer einen Teil der Besucher keine ausspielbare Seite.
        if ($experiment->getTestType() === AbExperimentTestType::CMS_PAGE) {
            foreach ($variants as $variant) {
                $config = $variant->getConfig();
                $cmsPageId = \is_array($config) ? ($config[AbExperimentTestType::CMS_PAGE_CONFIG_KEY] ?? null) : null;
                if (!\is_string($cmsPageId) || $cmsPageId === '') {
                    return self::VIOLATION_CMS_PAGE_MISSING;
                }
            }
        }

        return null;
    }

    /**
     * @param list<AbVariantEntity> $variants
     */
    public function firstViolationForVariants(array $variants): ?string
    {
        if (\count($variants) < 2) {
            return self::VIOLATION_TOO_FEW_VARIANTS;
        }

        $controlCount = 0;
        $weightSum = 0;
        foreach ($variants as $variant) {
            if ($variant->getWeight() < 0) {
                return self::VIOLATION_NEGATIVE_WEIGHT;
            }
            $weightSum += $variant->getWeight();
            if ($variant->isControl()) {
                ++$controlCount;
            }
        }

        if ($weightSum !== 100) {
            return self::VIOLATION_WEIGHT_SUM;
        }

        if ($controlCount !== 1) {
            return self::VIOLATION_CONTROL_COUNT;
        }

        return null;
    }

    /**
     * Deutsche Klartext-Meldung zu einem Verletzungs-Code (fürs Server-Logging).
     */
    public function messageFor(string $code): string
    {
        return self::MESSAGES[$code] ?? 'Ungültige Experiment-Konfiguration.';
    }

    /**
     * @return list<AbVariantEntity>
     */
    private function variants(AbExperimentEntity $experiment): array
    {
        $variants = $experiment->getVariants();

        return $variants === null ? [] : array_values($variants->getElements());
    }
}
