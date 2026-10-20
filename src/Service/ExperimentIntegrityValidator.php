<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service;

use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentTestType;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantEntity;

/**
 * Die Regeln, die ein Experiment vor dem Start erfüllen muss: mindestens zwei Varianten, keine
 * negativen Gewichte, Gewichtssumme genau 100, genau eine Control und beim CMS-Seiten-Test an
 * jeder Variante eine Seite. Beide Startwege nutzen sie, der Start über die Admin-API und der
 * zeitgesteuerte Start; als eigene Klasse ohne Datenbankzugriff gibt es die Regeln nur einmal.
 *
 * Ergebnis ist ein sprachneutraler Code, den die Verwaltungsoberfläche übersetzt.
 * `messageFor()` liefert dazu einen deutschen Klartext für API-Antwort und Protokoll.
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
        $variants = $experiment->getVariantList();

        $violation = $this->firstViolationForVariants($variants);
        if ($violation !== null) {
            return $violation;
        }

        // Beim CMS-Seiten-Test braucht jede Variante eine Seite, sonst gäbe es für einen Teil
        // der Besucher nichts auszuliefern.
        if ($experiment->getTestType() === AbExperimentTestType::CMS_PAGE) {
            foreach ($variants as $variant) {
                if ($variant->getCmsPageId() === null) {
                    return self::VIOLATION_CMS_PAGE_MISSING;
                }
            }
        }

        return null;
    }

    /**
     * Nur die Variantenregeln, ohne Blick auf den Testtyp; erster Verletzungs-Code oder null.
     *
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

        // Die Gewichte sind Prozent der 100 Buckets aus `VisitorBucketer`. Unter 100 bekämen
        // die übrigen Buckets keine Variante, über 100 erreichte die letzte Variante nie ihren Anteil.
        if ($weightSum !== 100) {
            return self::VIOLATION_WEIGHT_SUM;
        }

        if ($controlCount !== 1) {
            return self::VIOLATION_CONTROL_COUNT;
        }

        return null;
    }

    /**
     * Deutscher Klartext zu einem Verletzungs-Code, mit allgemeinem Text für unbekannte Codes.
     */
    public function messageFor(string $code): string
    {
        return self::MESSAGES[$code] ?? 'Ungültige Experiment-Konfiguration.';
    }

}
