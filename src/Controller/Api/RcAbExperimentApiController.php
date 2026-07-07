<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Controller\Api;

use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentStatus;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantEntity;
use Ruhrcoder\RcAbTesting\Service\ExperimentIntegrityValidator;
use Ruhrcoder\RcAbTesting\Service\ExperimentLookup;
use Ruhrcoder\RcAbTesting\Service\Stats\ExperimentEvaluator;
use Ruhrcoder\RcAbTesting\Service\Stats\ExperimentStatsAggregator;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin-API für Experiment-Lifecycle und -Auswertung. Bewusst ohne den
 * container-gebundenen `$this->json()`-Helfer (direktes JsonResponse), damit die
 * Endpunkte ohne vollständigen Kernel testbar bleiben. ACL liegt auf den
 * automatisch erzeugten Entity-Privilegien `rc_ab_experiment:read|update`.
 */
#[Route(defaults: ['_routeScope' => ['api']])]
final class RcAbExperimentApiController
{
    /**
     * @param EntityRepository<AbExperimentCollection> $experimentRepository
     */
    public function __construct(
        private readonly ExperimentLookup $experimentLookup,
        private readonly EntityRepository $experimentRepository,
        private readonly ExperimentStatsAggregator $statsAggregator,
        private readonly ExperimentEvaluator $evaluator,
        private readonly ExperimentIntegrityValidator $integrityValidator,
    ) {
    }

    #[Route(
        path: '/api/_action/rc-ab-testing/experiment/{id}/stats',
        name: 'api.action.rc-ab-testing.experiment.stats',
        methods: ['GET'],
        defaults: ['_acl' => ['rc_ab_experiment:read']],
    )]
    public function stats(string $id, Context $context): JsonResponse
    {
        $experiment = $this->experimentLookup->byId($id, $context);
        if ($experiment === null) {
            return $this->notFound($id);
        }

        $stats = $this->statsAggregator->aggregate($experiment, $context);
        $variants = [];
        foreach ($this->variants($experiment) as $variant) {
            $variantStats = $stats[$variant->getId()] ?? ['assignments' => 0, 'conversions' => 0, 'orders' => 0, 'revenue' => 0.0, 'revenueSumSq' => 0.0];
            $variants[] = [
                'id' => $variant->getId(),
                'technicalKey' => $variant->getTechnicalKey(),
                'isControl' => $variant->isControl(),
                'assignments' => $variantStats['assignments'],
                'conversions' => $variantStats['conversions'],
                'rate' => $variantStats['assignments'] > 0 ? $variantStats['conversions'] / $variantStats['assignments'] : null,
                'orders' => $variantStats['orders'],
                'revenue' => $variantStats['revenue'],
                // Durchschnittlicher Bestellwert (Umsatz / Bestellungen).
                'aov' => $variantStats['orders'] > 0 ? $variantStats['revenue'] / $variantStats['orders'] : null,
                // Umsatz je Besucher — die A/B-Kennzahl, die Conversion UND Bestellwert
                // zugleich erfasst (Umsatz / Zuordnungen).
                'revenuePerVisitor' => $variantStats['assignments'] > 0 ? $variantStats['revenue'] / $variantStats['assignments'] : null,
            ];
        }

        return new JsonResponse([
            'experimentId' => $id,
            'variants' => $variants,
            'evaluation' => $this->evaluator->evaluate($experiment, $stats),
        ]);
    }

    #[Route(
        path: '/api/_action/rc-ab-testing/experiment/{id}/start',
        name: 'api.action.rc-ab-testing.experiment.start',
        methods: ['POST'],
        defaults: ['_acl' => ['rc_ab_experiment:update']],
    )]
    public function start(string $id, Context $context): JsonResponse
    {
        $experiment = $this->experimentLookup->byId($id, $context);
        if ($experiment === null) {
            return $this->notFound($id);
        }

        $violation = $this->integrityValidator->firstStartViolation($experiment);
        if ($violation !== null) {
            return $this->error($violation, $this->integrityValidator->messageFor($violation), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->applyAndRespond($experiment->getId(), [
            'status' => AbExperimentStatus::RUNNING,
            'startedAt' => $experiment->getStartedAt() ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ], $context);
    }

    #[Route(
        path: '/api/_action/rc-ab-testing/experiment/{id}/pause',
        name: 'api.action.rc-ab-testing.experiment.pause',
        methods: ['POST'],
        defaults: ['_acl' => ['rc_ab_experiment:update']],
    )]
    public function pause(string $id, Context $context): JsonResponse
    {
        $experiment = $this->experimentLookup->byId($id, $context);
        if ($experiment === null) {
            return $this->notFound($id);
        }

        if ($experiment->getStatus() !== AbExperimentStatus::RUNNING) {
            return $this->error('onlyRunningCanBePaused', 'Nur laufende Experimente können pausiert werden.', Response::HTTP_CONFLICT);
        }

        return $this->applyAndRespond($experiment->getId(), ['status' => AbExperimentStatus::PAUSED], $context);
    }

    #[Route(
        path: '/api/_action/rc-ab-testing/experiment/{id}/end',
        name: 'api.action.rc-ab-testing.experiment.end',
        methods: ['POST'],
        defaults: ['_acl' => ['rc_ab_experiment:update']],
    )]
    public function end(string $id, Request $request, Context $context): JsonResponse
    {
        $experiment = $this->experimentLookup->byId($id, $context);
        if ($experiment === null) {
            return $this->notFound($id);
        }

        $changes = [
            'status' => AbExperimentStatus::DONE,
            'endedAt' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ];

        $winnerVariantId = $this->readWinnerVariantId($request);
        if ($winnerVariantId !== null) {
            if (!$this->hasVariant($experiment, $winnerVariantId)) {
                return $this->error(
                    'winnerNotInExperiment',
                    'Die gewählte Gewinner-Variante gehört nicht zum Experiment.',
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
            $changes['winnerVariantId'] = $winnerVariantId;
        }

        return $this->applyAndRespond($experiment->getId(), $changes, $context);
    }

    #[Route(
        path: '/api/_action/rc-ab-testing/experiment/{id}/archive',
        name: 'api.action.rc-ab-testing.experiment.archive',
        methods: ['POST'],
        defaults: ['_acl' => ['rc_ab_experiment:update']],
    )]
    public function archive(string $id, Context $context): JsonResponse
    {
        $experiment = $this->experimentLookup->byId($id, $context);
        if ($experiment === null) {
            return $this->notFound($id);
        }

        if ($experiment->getStatus() !== AbExperimentStatus::DONE) {
            return $this->error('onlyDoneCanBeArchived', 'Nur beendete Experimente können archiviert werden.', Response::HTTP_CONFLICT);
        }

        return $this->applyAndRespond($experiment->getId(), ['status' => AbExperimentStatus::ARCHIVED], $context);
    }

    private function readWinnerVariantId(Request $request): ?string
    {
        $content = $request->getContent();
        if ($content === '') {
            return null;
        }

        $data = json_decode($content, true);
        if (!\is_array($data) || !isset($data['winnerVariantId']) || !\is_string($data['winnerVariantId'])) {
            return null;
        }

        return $data['winnerVariantId'];
    }

    private function hasVariant(AbExperimentEntity $experiment, string $variantId): bool
    {
        foreach ($this->variants($experiment) as $variant) {
            if ($variant->getId() === $variantId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function applyAndRespond(string $id, array $changes, Context $context): JsonResponse
    {
        $this->experimentRepository->update([['id' => $id] + $changes], $context);

        return new JsonResponse(['id' => $id, 'status' => $changes['status'] ?? null]);
    }

    private function notFound(string $id): JsonResponse
    {
        return $this->error('experimentNotFound', \sprintf('Experiment "%s" nicht gefunden.', $id), Response::HTTP_NOT_FOUND);
    }

    /**
     * Fehler-Antwort mit stabilem, sprachneutralem Code (von der Admin-UI
     * lokalisiert) und deutscher Klartext-Meldung als Fallback/Log.
     */
    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message, 'errorCode' => $code], $status);
    }

    /**
     * @return list<AbVariantEntity>
     */
    private function variants(AbExperimentEntity $experiment): array
    {
        $variants = $experiment->getVariants();

        return $variants === null ? [] : array_values($variants->getElements());
    }
}
