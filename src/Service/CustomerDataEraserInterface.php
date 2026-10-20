<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service;

/**
 * Löscht die A/B-Daten einzelner Kunden (Art. 17 DSGVO).
 */
interface CustomerDataEraserInterface
{
    /**
     * @param list<string> $customerIds Kundenkennungen (hex)
     *
     * @return int Zahl der gelöschten Zeilen
     */
    public function eraseForCustomers(array $customerIds): int;
}
