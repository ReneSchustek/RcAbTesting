<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcAbTesting\Service\Consent\ConsentGate;
use Ruhrcoder\RcAbTesting\Service\VisitorIdResolver;
use Symfony\Component\HttpFoundation\Request;

/**
 * Prüft, welche Besucherkennung als dauerhaft gilt und damit teilnehmen darf.
 *
 * Eine Zuordnung, die die Anfrage nicht überlebt, ist keine. Würde auf einer frisch gewürfelten
 * Kennung ohne geschriebenen Cookie eingeteilt, sähe der Besucher bei jedem Seitenaufruf eine
 * andere Variante, bei einem Seitentest abwechselnd A und B. Das träfe jeden mit Cookie-Blocker
 * und, mit Einwilligungsbanner, jeden, der noch nicht zugestimmt hat.
 *
 * Ein solcher Fehler erzeugt keine Meldung und keine auffälligen Zahlen, sondern vermischt still
 * die Gruppen; die Auswertung sieht danach genauso aus wie eine echte.
 */
#[CoversClass(VisitorIdResolver::class)]
final class VisitorParticipationTest extends TestCase
{
    /**
     * Beim ersten Aufruf mit Einwilligung wird der Cookie mit der Antwort geschrieben, die
     * Kennung überlebt also. Den Besucher auszuschließen kostete jeden Test die Ansicht der
     * Einstiegsseite.
     */
    public function testAFirstVisitWithConsentCounts(): void
    {
        $request = Request::create('/');

        $resolver = new VisitorIdResolver($this->consentGate(true), new NullLogger());
        $resolver->resolveForRequest($request);

        self::assertTrue($request->attributes->get(VisitorIdResolver::PERSISTENT_ATTRIBUTE));
    }

    /**
     * Ohne Einwilligung wird kein Cookie geschrieben, und die Kennung ist beim nächsten Aufruf
     * weg. Wer hier teilnähme, bekäme jedes Mal eine neue Variante.
     */
    public function testAVisitWithoutConsentDoesNotCount(): void
    {
        $request = Request::create('/');

        $resolver = new VisitorIdResolver($this->consentGate(false), new NullLogger());
        $resolver->resolveForRequest($request);

        self::assertFalse($request->attributes->get(VisitorIdResolver::PERSISTENT_ATTRIBUTE));
    }

    /**
     * Mit Einwilligung behält ein vorhandener, gültiger Cookie seine Kennung und gilt als dauerhaft.
     */
    public function testAnExistingCookieKeepsItsIdentity(): void
    {
        // 32 Hex-Zeichen genügen `Uuid::isValid()`; die 4 an Position 13 ahmt nur eine UUID v4 nach.
        $existing = 'aaaaaaaaaaaa4aaaaaaaaaaaaaaaaaaa';
        $request = Request::create('/');
        $request->cookies->set('rc_ab_visitor_id', $existing);

        $resolver = new VisitorIdResolver($this->consentGate(true), new NullLogger());
        $visitorId = $resolver->resolveForRequest($request);

        self::assertSame($existing, $visitorId);
        self::assertTrue($request->attributes->get(VisitorIdResolver::PERSISTENT_ATTRIBUTE));
    }

    /**
     * Ohne Einwilligung gilt auch ein vorhandener Cookie nicht mehr. Behielte er seine Kennung und gälte
     * als dauerhaft, wirkte ein Widerruf nicht.
     */
    public function testAnExistingCookieWithoutConsentIsDropped(): void
    {
        $existing = 'aaaaaaaaaaaa4aaaaaaaaaaaaaaaaaaa';
        $request = Request::create('/');
        $request->cookies->set('rc_ab_visitor_id', $existing);

        $resolver = new VisitorIdResolver($this->consentGate(false), new NullLogger());
        $visitorId = $resolver->resolveForRequest($request);

        self::assertNotSame($existing, $visitorId);
        self::assertFalse($request->attributes->get(VisitorIdResolver::PERSISTENT_ATTRIBUTE));
    }

    /**
     * Zwei Aufrufe ohne Einwilligung bekommen zwei verschiedene Kennungen; deshalb darf auf
     * ihnen nicht eingeteilt werden. Dass beide nicht als dauerhaft gelten, prüft dieser Fall
     * nicht, das zeigt `testAVisitWithoutConsentDoesNotCount()`.
     */
    public function testWithoutConsentTheIdentityChangesOnEveryRequest(): void
    {
        $resolver = new VisitorIdResolver($this->consentGate(false), new NullLogger());

        $first = $resolver->resolveForRequest(Request::create('/'));
        $second = $resolver->resolveForRequest(Request::create('/'));

        self::assertNotSame($first, $second);
    }

    private function consentGate(bool $allows): ConsentGate
    {
        return new class ($allows) implements ConsentGate {
            public function __construct(private readonly bool $allows)
            {
            }

            public function allowsTrackingCookie(Request $request): bool
            {
                return $this->allows;
            }
        };
    }
}
