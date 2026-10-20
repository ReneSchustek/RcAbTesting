<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service;

use Ruhrcoder\RcAbTesting\Core\Content\AbAssignment\AbAssignmentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbAssignment\AbAssignmentEntity;
use Ruhrcoder\RcAbTesting\Service\VisitorIdResolver;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Prüft den Resolver, den Twig, Brücke, CMS-Tausch und Frontend-Schalter gemeinsam nutzen.
 * Er entscheidet, ob ein Besucher überhaupt teilnimmt, merkt sich das Ergebnis je Anfrage
 * und meldet dem Cache-Schutz jede Berührung eines Experiments. Bricht eines davon, sehen
 * Besucher wechselnde Varianten oder bekommen eine fremde Fassung aus dem HTTP-Cache.
 */
final class RequestVariantResolverTest extends VariantResolverTestCase
{
    public function testResolvesVariant(): void
    {
        $variant = $this->resolver($this->assignmentRepository())->resolve(self::EXPERIMENT_KEY);

        self::assertNotNull($variant);
        self::assertSame('a', $variant->getTechnicalKey());
    }

    public function testMarksExperimentTouchedAfterResolve(): void
    {
        $resolver = $this->resolver($this->assignmentRepository());

        // Vor der Auflösung hat noch keine Seite ein Experiment berührt.
        self::assertFalse($resolver->hasTouchedExperiment());

        $resolver->resolve(self::EXPERIMENT_KEY);

        // Danach weiß `CacheVarySubscriber`, dass die Antwort nicht in den HTTP-Cache darf. Das
        // gilt auch für Besucher, die keine Variante bekommen; den Fall prüft
        // `testTheCacheGuardStillTriggersForNonParticipants()`.
        self::assertTrue($resolver->hasTouchedExperiment());
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
        // Die Zuordnung zeigt auf eine Variante, die es im Experiment nicht gibt, also liefert
        // `resolve()` null. Auch dieses Ergebnis muss gemerkt werden, sonst fragte jeder Aufruf
        // im Template erneut die Datenbank.
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

    /**
     * Eine Besucherkennung, die die Anfrage nicht überlebt, darf nicht eingeteilt werden. Beim
     * nächsten Aufruf wäre sie eine andere, und der Besucher sähe abwechselnd A und B. Das
     * trifft jeden mit Cookie-Blocker und, mit Einwilligungsbanner, jeden vor der Zustimmung.
     *
     * Kein Fehler in einem A/B-Werkzeug ist schwerer zu bemerken: keine Meldung, keine
     * auffälligen Zahlen, nur still vermischte Gruppen und eine Auswertung, die aussieht wie
     * eine echte.
     */
    public function testNoParticipationWithoutAPersistentIdentity(): void
    {
        $resolver = $this->resolver($this->assignmentRepository());
        $this->builtRequest?->attributes->set(VisitorIdResolver::PERSISTENT_ATTRIBUTE, false);

        self::assertNull($resolver->resolve(self::EXPERIMENT_KEY));
    }

    /**
     * Wer nicht teilnimmt, sieht die Kontrollfassung. Die darf nicht in den gemeinsamen Cache
     * wandern, sonst bekämen Teilnehmer sie über den Proxy ausgeliefert; die Markierung hängt
     * deshalb nicht von der Teilnahme ab.
     */
    public function testTheCacheGuardStillTriggersForNonParticipants(): void
    {
        $resolver = $this->resolver($this->assignmentRepository());
        $this->builtRequest?->attributes->set(VisitorIdResolver::PERSISTENT_ATTRIBUTE, false);

        $resolver->resolve(self::EXPERIMENT_KEY);

        self::assertTrue($resolver->hasTouchedExperiment());
    }
}
