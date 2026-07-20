<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\FrontendSwitch;

/**
 * Ein benannter, frontend-wirksamer Schalter, den ein Experiment pro Variante auf
 * einen Wert setzt (No-Code). Der Wert wird in der Varianten-Config unter dem
 * Schlüssel {@see getKey} abgelegt; das konsumierende Plugin liest ihn zur
 * Laufzeit über `ab_variant_config` und ändert nur sein Frontend-Verhalten —
 * kein generischer Eingriff, jeder Schalter ist ein klar umrissener Fall.
 *
 * Implementierungen werden als getaggte Services gesammelt (Tag
 * `rc_ab_testing.frontend_switch`) und über die {@see FrontendSwitchRegistry}
 * bereitgestellt. Labels sind Snippet-Schlüssel (die Admin-Oberfläche lokalisiert).
 */
interface FrontendSwitch
{
    /** Config-Schlüssel, unter dem der gewählte Wert je Variante liegt. */
    public function getKey(): string;

    /** Snippet-Schlüssel des Klartext-Labels (Admin). */
    public function getLabel(): string;

    /**
     * Erlaubte Werte des Schalters mit ihren Klartext-Labels (Snippet-Schlüssel).
     *
     * @return list<array{value: string, label: string}>
     */
    public function getOptions(): array;
}
