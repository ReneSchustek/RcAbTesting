<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Bridge;

use Ruhrcoder\RcAbTesting\Bridge\RequestActiveVariantQuery;
use Ruhrcoder\RcAbTesting\Tests\Unit\Service\VariantResolverTestCase;

/**
 * Prüft die Brücke, über die andere Plugins die Variante des Besuchers abfragen. Liefert
 * sie ohne Anfrage etwas anderes als `null` und `false`, schalten diese Plugins außerhalb
 * der Storefront, etwa im CLI-Lauf, auf eine Variante um, die niemand zugeteilt hat.
 */
final class RequestActiveVariantQueryTest extends VariantResolverTestCase
{
    public function testReturnsActiveVariant(): void
    {
        $query = new RequestActiveVariantQuery($this->resolver($this->assignmentRepository()));

        self::assertSame('a', $query->getActiveVariant(self::EXPERIMENT_KEY));
    }

    public function testIsActiveVariantMatchesOnlyAssignedKey(): void
    {
        $query = new RequestActiveVariantQuery($this->resolver($this->assignmentRepository()));

        self::assertTrue($query->isActiveVariant(self::EXPERIMENT_KEY, 'a'));
        self::assertFalse($query->isActiveVariant(self::EXPERIMENT_KEY, 'b'));
    }

    public function testReturnsNullWhenNotParticipating(): void
    {
        $query = new RequestActiveVariantQuery($this->resolver($this->assignmentRepository(), withRequest: false));

        self::assertNull($query->getActiveVariant(self::EXPERIMENT_KEY));
        self::assertFalse($query->isActiveVariant(self::EXPERIMENT_KEY, 'a'));
    }
}
