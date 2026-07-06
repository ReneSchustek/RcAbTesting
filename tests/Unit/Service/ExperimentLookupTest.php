<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentStatus;
use Ruhrcoder\RcAbTesting\Service\ExperimentLookup;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;

final class ExperimentLookupTest extends TestCase
{
    public function testByTechnicalKeyReturnsExperiment(): void
    {
        $lookup = new ExperimentLookup($this->repository($this->experiment()));

        $result = $lookup->byTechnicalKey('checkout', Context::createDefaultContext());

        self::assertNotNull($result);
        self::assertSame('checkout', $result->getTechnicalKey());
    }

    public function testByTechnicalKeyReturnsNullWhenMissing(): void
    {
        $lookup = new ExperimentLookup($this->repository(null));

        self::assertNull($lookup->byTechnicalKey('fehlt', Context::createDefaultContext()));
    }

    public function testByIdReturnsExperiment(): void
    {
        $lookup = new ExperimentLookup($this->repository($this->experiment()));

        $result = $lookup->byId(Uuid::randomHex(), Context::createDefaultContext());

        self::assertNotNull($result);
    }

    public function testByIdReturnsNullWhenMissing(): void
    {
        $lookup = new ExperimentLookup($this->repository(null));

        self::assertNull($lookup->byId(Uuid::randomHex(), Context::createDefaultContext()));
    }

    private function repository(?AbExperimentEntity $experiment): EntityRepository
    {
        $result = $this->createStub(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new AbExperimentCollection($experiment === null ? [] : [$experiment]));

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($result);

        return $repository;
    }

    private function experiment(): AbExperimentEntity
    {
        $experiment = new AbExperimentEntity();
        $experiment->setId(Uuid::randomHex());
        $experiment->setTechnicalKey('checkout');
        $experiment->setName('Checkout');
        $experiment->setStatus(AbExperimentStatus::DONE);
        $experiment->setTestType('twig');
        $experiment->setTrafficAllocationPct(100);
        $experiment->setTargetSignificance(0.95);

        return $experiment;
    }
}
