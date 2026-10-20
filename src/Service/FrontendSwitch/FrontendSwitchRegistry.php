<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\FrontendSwitch;

/**
 * Die angemeldeten Frontend-Schalter (Service-Tag `rc_ab_testing.frontend_switch`) für die
 * Auswahl in der Verwaltung. Angeboten wird nur, was ein Plugin als Schalter angemeldet hat.
 */
final class FrontendSwitchRegistry
{
    /** @var list<FrontendSwitch> */
    private readonly array $switches;

    /**
     * @param iterable<FrontendSwitch> $switches
     */
    public function __construct(iterable $switches)
    {
        // Einmal in eine Liste umgewandelt, damit `all()` ein Array liefern kann, ohne den
        // getaggten Iterator bei jedem Aufruf neu zu durchlaufen.
        $this->switches = array_values($switches instanceof \Traversable ? iterator_to_array($switches) : $switches);
    }

    /**
     * @return list<FrontendSwitch>
     */
    public function all(): array
    {
        return $this->switches;
    }

    public function get(string $key): ?FrontendSwitch
    {
        foreach ($this->switches as $switch) {
            if ($switch->getKey() === $key) {
                return $switch;
            }
        }

        return null;
    }
}
