<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Subscriber;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentDefinition;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentStatus;
use Ruhrcoder\RcAbTesting\Service\ExperimentRegistry;
use Ruhrcoder\RcAbTesting\Subscriber\ExperimentCacheInvalidationSubscriber;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

final class ExperimentCacheInvalidationSubscriberTest extends TestCase
{
    public function testSubscribesToExperimentAndVariantWrittenEvents(): void
    {
        $events = ExperimentCacheInvalidationSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(AbExperimentDefinition::ENTITY_NAME . '.written', $events);
        self::assertArrayHasKey('rc_ab_variant.written', $events);
    }

    public function testInvalidationForcesAReload(): void
    {
        $result = $this->createStub(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new AbExperimentCollection([$this->experiment()]));

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::exactly(2))->method('search')->willReturn($result);

        $registry = new ExperimentRegistry($repository, new TagAwareAdapter(new ArrayAdapter()));
        $context = Context::createDefaultContext();

        $registry->getRunning($context);          // 1. Lauf -> Repository
        $registry->getRunning($context);          // aus dem Cache, kein Repository
        (new ExperimentCacheInvalidationSubscriber($registry))->onWritten($this->createStub(EntityWrittenEvent::class));
        $registry->getRunning($context);          // nach Invalidierung -> Repository erneut
    }

    private function experiment(): AbExperimentEntity
    {
        $experiment = new AbExperimentEntity();
        $experiment->setId(Uuid::randomHex());
        $experiment->setTechnicalKey('checkout');
        $experiment->setName('Checkout');
        $experiment->setStatus(AbExperimentStatus::RUNNING);
        $experiment->setTestType('twig');
        $experiment->setTrafficAllocationPct(100);
        $experiment->setTargetSignificance(0.95);

        return $experiment;
    }
}
