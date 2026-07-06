<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Bridge;

use Ruhrcoder\RcAbTesting\Bridge\ActiveVariantQueryImpl;
use Ruhrcoder\RcAbTesting\Tests\Unit\Service\VariantResolverTestCase;

final class ActiveVariantQueryImplTest extends VariantResolverTestCase
{
    public function testReturnsActiveVariant(): void
    {
        $query = new ActiveVariantQueryImpl($this->resolver($this->assignmentRepository()));

        self::assertSame('a', $query->getActiveVariant(self::EXPERIMENT_KEY));
    }

    public function testIsActiveVariantMatchesOnlyAssignedKey(): void
    {
        $query = new ActiveVariantQueryImpl($this->resolver($this->assignmentRepository()));

        self::assertTrue($query->isActiveVariant(self::EXPERIMENT_KEY, 'a'));
        self::assertFalse($query->isActiveVariant(self::EXPERIMENT_KEY, 'b'));
    }

    public function testReturnsNullWhenNotParticipating(): void
    {
        $query = new ActiveVariantQueryImpl($this->resolver($this->assignmentRepository(), withRequest: false));

        self::assertNull($query->getActiveVariant(self::EXPERIMENT_KEY));
        self::assertFalse($query->isActiveVariant(self::EXPERIMENT_KEY, 'a'));
    }
}
