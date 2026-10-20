<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\FrontendSwitch;

/**
 * Ein benannter Schalter im Frontend, den ein Experiment je Variante auf einen Wert setzt, ohne
 * dass dafür Code geschrieben wird. Der Wert liegt in der Varianten-Konfiguration unter
 * {@see getKey}; das Plugin, das ihn nutzt, liest ihn über `ab_switch` oder
 * {@see FrontendSwitchResolver} und ändert nur sein eigenes Verhalten. Jeder Schalter ist ein
 * klar umrissener Fall; einen allgemeinen Eingriff in fremde Plugins gibt es nicht.
 *
 * Implementierungen tragen den Service-Tag `rc_ab_testing.frontend_switch` und erscheinen über
 * {@see FrontendSwitchRegistry} in der Verwaltung.
 */
interface FrontendSwitch
{
    /** Schlüssel in der Varianten-Konfiguration, unter dem der gewählte Wert liegt. */
    public function getKey(): string;

    /** Snippet-Schlüssel der Bezeichnung; die Verwaltung übersetzt ihn. */
    public function getLabel(): string;

    /**
     * Erlaubte Werte mit den Snippet-Schlüsseln ihrer Bezeichnungen.
     *
     * @return list<array{value: string, label: string}>
     */
    public function getOptions(): array;
}
