<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service;

use Ruhrcoder\RcAbTesting\Core\Content\AbAssignment\AbAssignmentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbAssignment\AbAssignmentEntity;
use Ruhrcoder\RcAbTesting\Service\RequestVariantResolver;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;

final class RequestVariantResolverTest extends VariantResolverTestCase
{
    public function testResolvesVariant(): void
    {
        $variant = $this->resolver($this->assignmentRepository())->resolve(self::EXPERIMENT_KEY);

        self::assertNotNull($variant);
        self::assertSame('a', $variant->getTechnicalKey());
    }

    public function testMarksRequestAsInExperiment(): void
    {
        $resolver = $this->resolver($this->assignmentRepository());

        // Vor der Zuordnung ist die Seite nicht als Experiment-Seite markiert.
        self::assertNull($this->builtRequest?->attributes->get(RequestVariantResolver::IN_EXPERIMENT_ATTRIBUTE));

        $resolver->resolve(self::EXPERIMENT_KEY);

        // Danach signalisiert das Attribut dem CacheVarySubscriber, den HTTP-Cache zu deaktivieren.
        self::assertTrue($this->builtRequest?->attributes->get(RequestVariantResolver::IN_EXPERIMENT_ATTRIBUTE));
    }

    public function testMemoizesWithinRequest(): void
    {
        $repository = $this->assignmentRepository();
        $repository->expects(self::once())->method('search');
        $resolver = $this->resolver($repository);

        $resolver->resolve(self::EXPERIMENT_KEY);
        $resolver->resolve(self::EXPERIMENT_KEY);
    }

    public function testResetClearsMemo(): void
    {
        $repository = $this->assignmentRepository();
        $repository->expects(self::exactly(2))->method('search');
        $resolver = $this->resolver($repository);

        $resolver->resolve(self::EXPERIMENT_KEY);
        $resolver->reset();
        $resolver->resolve(self::EXPERIMENT_KEY);
    }

    public function testMemoizesNullResultWithinRequest(): void
    {
        // Zuordnung auf eine Variante, die es im Experiment nicht (mehr) gibt →
        // resolve() liefert null. Das null-Ergebnis muss ebenfalls memoized werden,
        // damit der Hot-Path nicht bei jedem Aufruf erneut die Datenbank trifft.
        $assignment = new AbAssignmentEntity();
        $assignment->setId(Uuid::randomHex());
        $assignment->setExperimentId('exp-checkout');
        $assignment->setVariantId('nicht-existente-variante');
        $assignment->setVisitorId('visitor-1');

        $result = $this->createStub(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new AbAssignmentCollection([$assignment]));

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())->method('search')->willReturn($result);

        $resolver = $this->resolver($repository);

        self::assertNull($resolver->resolve(self::EXPERIMENT_KEY));
        self::assertNull($resolver->resolve(self::EXPERIMENT_KEY));
    }

    public function testReturnsNullWithoutRequest(): void
    {
        $resolver = $this->resolver($this->assignmentRepository(), withRequest: false);

        self::assertNull($resolver->resolve(self::EXPERIMENT_KEY));
    }

    public function testReturnsNullWithoutSalesChannelContext(): void
    {
        $resolver = $this->resolver($this->assignmentRepository(), withSalesChannelContext: false);

        self::assertNull($resolver->resolve(self::EXPERIMENT_KEY));
    }
}
