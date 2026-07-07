<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\FrontendSwitch;

/**
 * Sammelt die registrierten Frontend-Schalter (getaggte Services) und stellt sie
 * der Admin-Oberflaeche bereit. Kein Universalschalter — nur die konkret
 * angemeldeten, frontend-wirksamen Faelle.
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
