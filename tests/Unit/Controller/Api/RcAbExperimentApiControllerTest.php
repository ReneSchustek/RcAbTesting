<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Controller\Api;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Controller\Api\RcAbExperimentApiController;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentStatus;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantEntity;
use Ruhrcoder\RcAbTesting\Service\ExperimentIntegrityValidator;
use Ruhrcoder\RcAbTesting\Service\ExperimentLookup;
use Ruhrcoder\RcAbTesting\Service\Stats\ExperimentEvaluator;
use Ruhrcoder\RcAbTesting\Service\Stats\ExperimentFunnelAggregator;
use Ruhrcoder\RcAbTesting\Service\Stats\ExperimentSegmentAggregator;
use Ruhrcoder\RcAbTesting\Service\Stats\ExperimentStatsAggregator;
use Ruhrcoder\RcAbTesting\Service\Stats\ExperimentTimeSeriesAggregator;
use Ruhrcoder\RcAbTesting\Service\Stats\NormalDistribution;
use Ruhrcoder\RcAbTesting\Service\Stats\SampleSizeCalculator;
use Ruhrcoder\RcAbTesting\Service\Stats\StatisticsCalculator;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class RcAbExperimentApiControllerTest extends TestCase
{
    private const EXPERIMENT_ID = '0191aaaabbbbccccddddeeeeffff0001';
    private const VARIANT_A = '0191aaaabbbbccccddddeeeeffff000a';
    private const VARIANT_B = '0191aaaabbbbccccddddeeeeffff000b';

    public function testStatsReturnsVariantData(): void
    {
        $captured = null;
        $controller = $this->controller($this->experiment(AbExperimentStatus::RUNNING), $captured);

        $response = $controller->stats(self::EXPERIMENT_ID, Context::createDefaultContext());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = $this->decode($response->getContent());
        self::assertSame(self::EXPERIMENT_ID, $payload['experimentId']);
        self::assertCount(2, $payload['variants']);
        self::assertSame('a', $payload['variants'][0]['technicalKey']);
        self::assertSame(100, $payload['variants'][0]['assignments']);
        self::assertSame(5, $payload['variants'][0]['conversions']);

        self::assertArrayHasKey('evaluation', $payload);
        self::assertSame('a', $payload['evaluation']['controlKey']);
        self::assertSame(0.95, $payload['evaluation']['confidenceLevel']);
        self::assertCount(1, $payload['evaluation']['comparisons']);
        self::assertSame('b', $payload['evaluation']['comparisons'][0]['variantKey']);
    }

    public function testStatsNotFound(): void
    {
        $captured = null;
        $controller = $this->controller(null, $captured);

        $response = $controller->stats('fehlt', Context::createDefaultContext());

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testStartSetsRunning(): void
    {
        $captured = null;
        $controller = $this->controller($this->experiment(AbExperimentStatus::DRAFT), $captured);

        $response = $controller->start(self::EXPERIMENT_ID, Context::createDefaultContext());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertIsArray($captured);
        self::assertSame(AbExperimentStatus::RUNNING, $captured[0]['status']);
        self::assertArrayHasKey('startedAt', $captured[0]);
    }

    public function testStartRejectsInsufficientVariants(): void
    {
        $captured = null;
        $experiment = $this->experiment(AbExperimentStatus::DRAFT);
        $experiment->setVariants(new AbVariantCollection([$this->variant(self::VARIANT_A, 'a', 100, true)]));
        $controller = $this->controller($experiment, $captured);

        $response = $controller->start(self::EXPERIMENT_ID, Context::createDefaultContext());

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertNull($captured);
    }

    public function testStartRejectsWrongWeightSum(): void
    {
        $captured = null;
        $experiment = $this->experiment(AbExperimentStatus::DRAFT);
        $experiment->setVariants(new AbVariantCollection([
            $this->variant(self::VARIANT_A, 'a', 60, true),
            $this->variant(self::VARIANT_B, 'b', 60, false),
        ]));
        $controller = $this->controller($experiment, $captured);

        $response = $controller->start(self::EXPERIMENT_ID, Context::createDefaultContext());

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertNull($captured);
    }

    public function testStartRejectsMissingControl(): void
    {
        $captured = null;
        $experiment = $this->experiment(AbExperimentStatus::DRAFT);
        $experiment->setVariants(new AbVariantCollection([
            $this->variant(self::VARIANT_A, 'a', 50, false),
            $this->variant(self::VARIANT_B, 'b', 50, false),
        ]));
        $controller = $this->controller($experiment, $captured);

        $response = $controller->start(self::EXPERIMENT_ID, Context::createDefaultContext());

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertNull($captured);
    }

    public function testPauseRequiresRunning(): void
    {
        $captured = null;
        $controller = $this->controller($this->experiment(AbExperimentStatus::DRAFT), $captured);

        $response = $controller->pause(self::EXPERIMENT_ID, Context::createDefaultContext());

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertNull($captured);
    }

    public function testEndSetsDone(): void
    {
        $captured = null;
        $controller = $this->controller($this->experiment(AbExperimentStatus::RUNNING), $captured);

        $response = $controller->end(self::EXPERIMENT_ID, new Request(), Context::createDefaultContext());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertIsArray($captured);
        self::assertSame(AbExperimentStatus::DONE, $captured[0]['status']);
        self::assertArrayHasKey('endedAt', $captured[0]);
        self::assertArrayNotHasKey('winnerVariantId', $captured[0]);
    }

    public function testEndSetsWinnerVariant(): void
    {
        $captured = null;
        $controller = $this->controller($this->experiment(AbExperimentStatus::RUNNING), $captured);

        $request = new Request([], [], [], [], [], [], (string) json_encode(['winnerVariantId' => self::VARIANT_B]));
        $response = $controller->end(self::EXPERIMENT_ID, $request, Context::createDefaultContext());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertIsArray($captured);
        self::assertSame(self::VARIANT_B, $captured[0]['winnerVariantId']);
    }

    public function testEndRejectsForeignWinnerVariant(): void
    {
        $captured = null;
        $controller = $this->controller($this->experiment(AbExperimentStatus::RUNNING), $captured);

        $request = new Request([], [], [], [], [], [], (string) json_encode(['winnerVariantId' => 'fremde-variante']));
        $response = $controller->end(self::EXPERIMENT_ID, $request, Context::createDefaultContext());

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertNull($captured);
    }

    public function testArchiveRequiresDone(): void
    {
        $captured = null;
        $controller = $this->controller($this->experiment(AbExperimentStatus::RUNNING), $captured);

        $response = $controller->archive(self::EXPERIMENT_ID, Context::createDefaultContext());

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertNull($captured);
    }

    public function testArchiveSetsArchived(): void
    {
        $captured = null;
        $controller = $this->controller($this->experiment(AbExperimentStatus::DONE), $captured);

        $response = $controller->archive(self::EXPERIMENT_ID, Context::createDefaultContext());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertIsArray($captured);
        self::assertSame(AbExperimentStatus::ARCHIVED, $captured[0]['status']);
    }

    /**
     * @param list<array<string, mixed>>|null $captured
     */
    private function controller(?AbExperimentEntity $experiment, ?array &$captured): RcAbExperimentApiController
    {
        $searchResult = $this->createStub(EntitySearchResult::class);
        $searchResult->method('getEntities')->willReturn(new AbExperimentCollection($experiment === null ? [] : [$experiment]));

        $experimentRepository = $this->createMock(EntityRepository::class);
        $experimentRepository->method('search')->willReturn($searchResult);
        $experimentRepository->method('update')->willReturnCallback(function (array $payload) use (&$captured): EntityWrittenContainerEvent {
            $captured = $payload;

            return $this->createStub(EntityWrittenContainerEvent::class);
        });

        $normal = new NormalDistribution();

        return new RcAbExperimentApiController(
            new ExperimentLookup($experimentRepository),
            $experimentRepository,
            new ExperimentStatsAggregator($this->assignmentRepository(), $this->connection(5)),
            new ExperimentSegmentAggregator($this->connection(5)),
            new ExperimentTimeSeriesAggregator($this->connection(5)),
            new ExperimentFunnelAggregator($this->connection(5)),
            new ExperimentEvaluator(new StatisticsCalculator($normal), new SampleSizeCalculator($normal)),
            new ExperimentIntegrityValidator(),
            $this->createMock(EntityRepository::class),
        );
    }

    private function assignmentRepository(): EntityRepository
    {
        $result = $this->createStub(EntitySearchResult::class);
        $result->method('getTotal')->willReturn(100);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($result);

        return $repository;
    }

    private function connection(int $distinctVisitors): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn($distinctVisitors);

        return $connection;
    }

    private function experiment(string $status): AbExperimentEntity
    {
        $experiment = new AbExperimentEntity();
        $experiment->setId(self::EXPERIMENT_ID);
        $experiment->setTechnicalKey('checkout');
        $experiment->setName('Checkout');
        $experiment->setStatus($status);
        $experiment->setTestType('twig');
        $experiment->setTrafficAllocationPct(100);
        $experiment->setTargetSignificance(0.95);
        $experiment->setVariants(new AbVariantCollection([
            $this->variant(self::VARIANT_A, 'a', 50, true),
            $this->variant(self::VARIANT_B, 'b', 50, false),
        ]));

        return $experiment;
    }

    private function variant(string $id, string $key, int $weight, bool $isControl): AbVariantEntity
    {
        $variant = new AbVariantEntity();
        $variant->setId($id);
        $variant->setTechnicalKey($key);
        $variant->setName(strtoupper($key));
        $variant->setWeight($weight);
        $variant->setIsControl($isControl);

        return $variant;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string|false $content): array
    {
        self::assertIsString($content);
        $decoded = json_decode($content, true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
