<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentStatus;
use Ruhrcoder\RcAbTesting\Service\ExperimentRegistry;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

/**
 * Prüft den Zwischenspeicher der laufenden Experimente. Er wird bei jedem Seitenaufruf
 * gefragt; lüde er jedes Mal aus der Datenbank, kostete jede Storefront-Anfrage eine
 * zusätzliche Abfrage mit Varianten-Assoziation.
 */
final class ExperimentRegistryTest extends TestCase
{
    public function testRunningExperimentsAreCachedAfterFirstLoad(): void
    {
        $experiment = $this->experiment('checkout-layout');
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())
            ->method('search')
            ->willReturn($this->resultFor($experiment));

        $registry = new ExperimentRegistry($repository, new TagAwareAdapter(new ArrayAdapter()));
        $context = Context::createDefaultContext();

        // Das `expects(self::once())` am Repository trägt den Test: Der zweite Aufruf muss aus
        // dem Zwischenspeicher kommen. Ein Vergleich auf Objektidentität taugt nicht, weil der
        // Adapter beim Speichern serialisiert.
        $first = $registry->getRunning($context);
        $second = $registry->getRunning($context);

        self::assertCount(1, $first);
        self::assertCount(1, $second);
        self::assertSame('checkout-layout', $second[0]->getTechnicalKey());
    }

    public function testGetByTechnicalKeyFindsAndMisses(): void
    {
        $experiment = $this->experiment('checkout-layout');
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($this->resultFor($experiment));

        $registry = new ExperimentRegistry($repository, new TagAwareAdapter(new ArrayAdapter()));
        $context = Context::createDefaultContext();

        self::assertSame($experiment, $registry->getByTechnicalKey('checkout-layout', $context));
        self::assertNull($registry->getByTechnicalKey('unbekannt', $context));
    }

    private function experiment(string $technicalKey): AbExperimentEntity
    {
        $experiment = new AbExperimentEntity();
        $experiment->setId(Uuid::randomHex());
        $experiment->setTechnicalKey($technicalKey);
        $experiment->setName($technicalKey);
        $experiment->setStatus(AbExperimentStatus::RUNNING);
        $experiment->setTestType('twig');
        $experiment->setTrafficAllocationPct(100);
        $experiment->setTargetSignificance(0.95);

        return $experiment;
    }

    /**
     * @return EntitySearchResult<AbExperimentCollection>
     */
    private function resultFor(AbExperimentEntity $experiment): EntitySearchResult
    {
        $result = $this->createStub(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new AbExperimentCollection([$experiment]));

        return $result;
    }
}
