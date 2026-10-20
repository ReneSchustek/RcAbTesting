<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service;

/**
 * Der eine Zustand, den `CacheVarySubscriber` braucht: ob die Seite dieses Requests ein laufendes,
 * hier anwendbares Experiment berührt hat. Die schmale Schnittstelle hält den Cache-Wächter frei
 * von Zuteilung und Datenbank des {@see RequestVariantResolver}.
 */
interface ExperimentTouchState
{
    /**
     * True auch dann, wenn der Besucher selbst nicht teilnimmt; die Seite darf trotzdem nicht in
     * den HTTP-Cache.
     */
    public function hasTouchedExperiment(): bool;
}
