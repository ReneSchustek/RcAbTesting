<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Controller\Api;

use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentStatus;
use Ruhrcoder\RcAbTesting\Service\ExperimentIntegrityValidator;
use Ruhrcoder\RcAbTesting\Service\ExperimentLookup;
use Ruhrcoder\RcAbTesting\Service\FrontendSwitch\FrontendSwitch;
use Ruhrcoder\RcAbTesting\Service\FrontendSwitch\FrontendSwitchRegistry;
use Ruhrcoder\RcAbTesting\Service\Stats\ExperimentStatsResponseAssembler;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Verwaltungs-API für den Lebenszyklus eines Experiments und seine Auswertung.
 *
 * Die Antworten entstehen direkt als `JsonResponse` statt über den Helfer `json()` des
 * `AbstractController`, der einen Container voraussetzt; so lassen sich die Endpunkte ohne Kernel
 * testen. Die Rechte hängen an den Entity-Privilegien `rc_ab_experiment:read` und
 * `rc_ab_experiment:update`, die Shopware zur Definition selbst erzeugt.
 *
 * Erlaubte Übergänge: starten aus Entwurf oder Pause, pausieren aus „läuft“, beenden aus „läuft“ oder
 * „pausiert“ (`AbExperimentStatus::ENDABLE`), archivieren aus „beendet“.
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
        private readonly ExperimentStatsResponseAssembler $statsResponseAssembler,
        private readonly ExperimentIntegrityValidator $integrityValidator,
        private readonly FrontendSwitchRegistry $frontendSwitchRegistry,
    ) {
    }

    /**
     * Registrierte Frontend-Schalter für die Auswahl beim Testtyp „Frontend-Schalter“. `label` ist
     * ein Snippet-Schlüssel, übersetzt wird in der Verwaltung. Das Leserecht auf Experimente
     * genügt, ein eigenes Privileg braucht die Liste nicht.
     */
    #[Route(
        path: '/api/_action/rc-ab-testing/frontend-switches',
        name: 'api.action.rc-ab-testing.frontend-switches',
        methods: ['GET'],
        defaults: ['_acl' => ['rc_ab_experiment:read']],
    )]
    public function frontendSwitches(): JsonResponse
    {
        $switches = array_map(
            static fn (FrontendSwitch $switch): array => [
                'key' => $switch->getKey(),
                'label' => $switch->getLabel(),
                'options' => $switch->getOptions(),
            ],
            $this->frontendSwitchRegistry->all(),
        );

        return new JsonResponse(['switches' => $switches]);
    }

    #[Route(
        path: '/api/_action/rc-ab-testing/experiment/{id}/stats',
        requirements: ['id' => '[0-9a-f]{32}'],
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

        return new JsonResponse($this->statsResponseAssembler->assemble($experiment, $context));
    }

    #[Route(
        path: '/api/_action/rc-ab-testing/experiment/{id}/start',
        requirements: ['id' => '[0-9a-f]{32}'],
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

        // Nur aus Entwurf oder Pause. Ein beendetes oder archiviertes Experiment, das wieder
        // läuft, sammelte in denselben Datenbestand weiter, und die Auswertung mischte zwei
        // Laufzeiten mit womöglich anderen Gewichten oder Zielseiten, ohne dass die Zahlen es
        // verraten.
        if (!\in_array($experiment->getStatus(), [AbExperimentStatus::DRAFT, AbExperimentStatus::PAUSED], true)) {
            return $this->error(
                'onlyDraftOrPausedCanBeStarted',
                'Nur Experimente im Entwurf oder in Pause können gestartet werden. Ein beendetes Experiment würde sonst in denselben Datenbestand weitersammeln.',
                Response::HTTP_CONFLICT,
            );
        }

        $violation = $this->integrityValidator->firstStartViolation($experiment);
        if ($violation !== null) {
            return $this->error(
                $violation,
                $this->integrityValidator->messageFor($violation),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // Nach einer Pause bleibt der erste Startzeitpunkt stehen. Der Planer erkennt daran ein
        // schon einmal gestartetes Experiment und hebt die Pause nicht von selbst auf.
        return $this->applyAndRespond($experiment->getId(), [
            'status' => AbExperimentStatus::RUNNING,
            'startedAt' => $experiment->getStartedAt() ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ], $context);
    }

    #[Route(
        path: '/api/_action/rc-ab-testing/experiment/{id}/pause',
        requirements: ['id' => '[0-9a-f]{32}'],
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
            return $this->error(
                'onlyRunningCanBePaused',
                'Nur laufende Experimente können pausiert werden.',
                Response::HTTP_CONFLICT
            );
        }

        return $this->applyAndRespond($experiment->getId(), ['status' => AbExperimentStatus::PAUSED], $context);
    }

    #[Route(
        path: '/api/_action/rc-ab-testing/experiment/{id}/end',
        requirements: ['id' => '[0-9a-f]{32}'],
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

        if (!\in_array($experiment->getStatus(), AbExperimentStatus::ENDABLE, true)) {
            return $this->error(
                'onlyRunningOrPausedCanBeEnded',
                'Nur laufende oder pausierte Experimente können beendet werden.',
                Response::HTTP_CONFLICT,
            );
        }

        // Ohne `winnerVariantId` im Body bleibt ein schon eingetragener Gewinner stehen.
        $changes = [
            'status' => AbExperimentStatus::DONE,
            'endedAt' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ];

        $winnerVariantId = $this->readWinnerVariantId($request);
        if ($winnerVariantId !== null) {
            // `winner_variant_id` hat keinen Fremdschlüssel; ohne diese Prüfung ließe sich jede
            // beliebige ID, auch die Variante eines anderen Experiments, als Gewinner eintragen.
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
        requirements: ['id' => '[0-9a-f]{32}'],
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
            return $this->error(
                'onlyDoneCanBeArchived',
                'Nur beendete Experimente können archiviert werden.',
                Response::HTTP_CONFLICT
            );
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
        foreach ($experiment->getVariantList() as $variant) {
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
        return $this->error(
            'experimentNotFound',
            \sprintf(
                'Experiment "%s" nicht gefunden.',
                $id
            ),
            Response::HTTP_NOT_FOUND
        );
    }

    /**
     * Fehlerantwort mit stabilem, sprachneutralem `errorCode`, den die Verwaltung übersetzt;
     * `error` trägt den deutschen Klartext für Protokolle und Aufrufer ohne Übersetzung.
     */
    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message, 'errorCode' => $code], $status);
    }
}
