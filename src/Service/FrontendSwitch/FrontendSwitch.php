<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\FrontendSwitch;

/**
 * Ein benannter, frontend-wirksamer Schalter, den ein Experiment pro Variante auf
 * einen Wert setzt (No-Code). Der Wert wird in der Varianten-Config unter dem
 * Schluessel {@see getKey} abgelegt; das konsumierende Plugin liest ihn zur
 * Laufzeit ueber `ab_variant_config` und aendert nur sein Frontend-Verhalten —
 * kein generischer Eingriff, jeder Schalter ist ein klar umrissener Fall.
 *
 * Implementierungen werden als getaggte Services gesammelt (Tag
 * `rc_ab_testing.frontend_switch`) und ueber die {@see FrontendSwitchRegistry}
 * bereitgestellt. Labels sind Snippet-Schluessel (die Admin-Oberflaeche lokalisiert).
 */
interface FrontendSwitch
{
    /** Config-Schlussel, unter dem der gewaehlte Wert je Variante liegt. */
    public function getKey(): string;

    /** Snippet-Schluessel des Klartext-Labels (Admin). */
    public function getLabel(): string;

    /**
     * Erlaubte Werte des Schalters mit ihren Klartext-Labels (Snippet-Schluessel).
     *
     * @return list<array{value: string, label: string}>
     */
    public function getOptions(): array;
}
