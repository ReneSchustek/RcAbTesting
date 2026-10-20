<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcAbTesting\Service\Consent\ConsentGate;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Liest oder erzeugt die Besucher-Kennung, an der die Zuteilung hängt: eine zufällige UUID ohne
 * Personenbezug. Sie entsteht in zwei Schritten. `resolveForRequest()` legt sie beim
 * Kernel-Request im Request ab, `writeCookieIfNeeded()` setzt beim Kernel-Response den Cookie.
 *
 * Ohne Einwilligung lebt eine neue Kennung nur für diesen Request. Der Besucher nimmt dann nicht
 * teil und sieht die Kontrollfassung (siehe `PERSISTENT_ATTRIBUTE`).
 */
final class VisitorIdResolver
{
    public const COOKIE_NAME = 'rc_ab_visitor_id';
    public const REQUEST_ATTRIBUTE = 'rc_ab_visitor_id';

    /** Markiert eine in diesem Request neu erzeugte Kennung, für die der Cookie noch fehlt. */
    public const NEW_FLAG_ATTRIBUTE = 'rc_ab_visitor_id_new';

    /**
     * True, wenn die Kennung den Request überlebt: Sie stammt aus einem vorhandenen Cookie, oder
     * sie ist neu und die Einwilligung erlaubt, den Cookie zu setzen. Nur dann wird zugeteilt und
     * gespeichert; eine Kennung, die beim nächsten Aufruf fehlt, erzeugte Stichproben aus
     * Einmal-Besuchern und Bots.
     */
    public const PERSISTENT_ATTRIBUTE = 'rc_ab_visitor_persistent';

    /**
     * Der Besucher hat ein Cookie aus einer früheren Einwilligung, die heute nicht mehr gilt. Die Antwort
     * löscht es (siehe `writeCookieIfNeeded()`).
     */
    public const REVOKED_ATTRIBUTE = 'rc_ab_visitor_revoked';

    /** Ein Jahr, damit auch lange Experimente einen wiederkehrenden Besucher wiedererkennen. */
    private const COOKIE_MAX_AGE_SECONDS = 31536000; // 1 Jahr

    public function __construct(
        private readonly ConsentGate $consentGate,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Legt die Kennung im Request ab und gibt sie zurück. Ist sie neu, merkt `NEW_FLAG_ATTRIBUTE`
     * vor, dass die Response den Cookie setzen soll.
     */
    public function resolveForRequest(Request $request): string
    {
        $consent = $this->consentGate->allowsTrackingCookie($request);
        $existing = $this->readValidCookie($request);

        // Ein vorhandenes Cookie gilt nur mit heute gültiger Einwilligung. Nach einem Widerruf liefe der
        // Besucher sonst ein Jahr lang unter seiner alten Kennung weiter, als dauerhaft gezählt. Ohne
        // Einwilligung bekommt er wie jeder andere eine flüchtige Kennung, und das alte Cookie wird gelöscht.
        if ($existing !== null && !$consent) {
            $request->attributes->set(self::REVOKED_ATTRIBUTE, true);
            $existing = null;
        }

        if ($existing !== null) {
            $request->attributes->set(self::REQUEST_ATTRIBUTE, $existing);
            $request->attributes->set(self::PERSISTENT_ATTRIBUTE, true);

            return $existing;
        }

        $visitorId = Uuid::randomHex();
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $visitorId);
        $request->attributes->set(self::NEW_FLAG_ATTRIBUTE, true);

        // Dauerhaft ist die neue Kennung genau dann, wenn der Cookie auch geschrieben wird, also
        // dieselbe Bedingung wie in `writeCookieIfNeeded()`. Gälte sie ohne Cookie als dauerhaft,
        // würfelte jeder Besucher ohne Einwilligung bei jedem Aufruf neu, welche Variante er
        // sieht, bei einem Seiten-Test also abwechselnd A und B.
        //
        // Einen Cookie-Blocker sieht der Server nicht: Mit Einwilligung gilt die Kennung als
        // dauerhaft, auch wenn der Browser den Cookie danach verwirft.
        $request->attributes->set(self::PERSISTENT_ATTRIBUTE, $consent);

        return $visitorId;
    }

    /**
     * Setzt den Cookie nur für eine in diesem Request neu erzeugte Kennung und nur mit
     * Einwilligung. Ein vorhandener Cookie wird nicht erneuert; seine Laufzeit zählt ab dem
     * ersten Besuch.
     */
    public function writeCookieIfNeeded(Request $request, Response $response): void
    {
        if ($request->attributes->get(self::REVOKED_ATTRIBUTE) === true) {
            $response->headers->clearCookie(
                self::COOKIE_NAME,
                '/',
                null,
                $request->isSecure(),
                false,
                Cookie::SAMESITE_LAX
            );

            return;
        }

        if ($request->attributes->get(self::NEW_FLAG_ATTRIBUTE) !== true) {
            return;
        }

        $visitorId = $this->getCurrentVisitorId($request);
        if ($visitorId === null || !$this->consentGate->allowsTrackingCookie($request)) {
            return;
        }

        $response->headers->setCookie($this->buildCookie($visitorId, $request->isSecure()));
    }

    /**
     * Die Kennung aus `resolveForRequest()`, oder null, wenn für diesen Request keine aufgelöst
     * wurde (etwa unter `/admin` und `/api`).
     */
    public function getCurrentVisitorId(Request $request): ?string
    {
        $value = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        return \is_string($value) ? $value : null;
    }

    private function readValidCookie(Request $request): ?string
    {
        $raw = $request->cookies->get(self::COOKIE_NAME);
        if ($raw === null) {
            return null;
        }

        if (!Uuid::isValid($raw)) {
            // Verworfen und protokolliert, damit Manipulation oder Fehler im Browser-Skript
            // auffallen. Der Wert selbst steht nicht im Protokoll.
            $this->logger->warning('Verworfener korrupter Besucher-Cookie {cookie}', [
                'cookie' => self::COOKIE_NAME,
            ]);

            return null;
        }

        return $raw;
    }

    private function buildCookie(string $visitorId, bool $secure): Cookie
    {
        return Cookie::create(self::COOKIE_NAME)
            ->withValue($visitorId)
            ->withExpires(time() + self::COOKIE_MAX_AGE_SECONDS)
            ->withSecure($secure)
            ->withHttpOnly(false) // Das Storefront-Skript liest die Kennung für `custom.*`-Ereignisse.
            ->withSameSite(Cookie::SAMESITE_LAX);
    }
}
