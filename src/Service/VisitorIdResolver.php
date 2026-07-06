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
 * Liest oder erzeugt die Besucher-ID für Sticky-Bucketing. Die ID ist eine
 * zufällige, PII-freie UUID. Der Lebenszyklus ist zweistufig: `resolveForRequest`
 * legt die ID im Request ab (Kernel-Request), `writeCookieIfNeeded` setzt den
 * Persistenz-Cookie auf die Response (Kernel-Response). Ohne Einwilligung lebt
 * die ID nur im Request (Test-Teilnahme ohne Wiederkehr-Persistenz).
 */
final class VisitorIdResolver
{
    public const COOKIE_NAME = 'rc_ab_visitor_id';
    public const REQUEST_ATTRIBUTE = 'rc_ab_visitor_id';
    public const NEW_FLAG_ATTRIBUTE = 'rc_ab_visitor_id_new';

    /**
     * True, wenn die Besucher-ID aus einem bereits persistierten Cookie stammt
     * (wiederkehrender Besucher). Frisch erzeugte IDs sind noch nicht persistiert
     * — daraus darf keine Zuordnung in die Datenbank geschrieben werden, sonst
     * erzeugen Bots und cookielose Requests Phantom-Stichproben.
     */
    public const PERSISTENT_ATTRIBUTE = 'rc_ab_visitor_persistent';

    private const COOKIE_MAX_AGE_SECONDS = 31536000; // 1 Jahr

    public function __construct(
        private readonly ConsentGate $consentGate,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Ermittelt die Besucher-ID und legt sie im Request-Attribut ab. Wird eine
     * ID neu erzeugt, merkt ein Flag-Attribut, dass die Response den Cookie
     * setzen soll.
     */
    public function resolveForRequest(Request $request): string
    {
        $existing = $this->readValidCookie($request);
        if ($existing !== null) {
            $request->attributes->set(self::REQUEST_ATTRIBUTE, $existing);
            $request->attributes->set(self::PERSISTENT_ATTRIBUTE, true);

            return $existing;
        }

        $visitorId = Uuid::randomHex();
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $visitorId);
        $request->attributes->set(self::NEW_FLAG_ATTRIBUTE, true);
        $request->attributes->set(self::PERSISTENT_ATTRIBUTE, false);

        return $visitorId;
    }

    /**
     * Setzt den Persistenz-Cookie, falls in diesem Request eine ID neu erzeugt
     * wurde und der ConsentGate das Setzen erlaubt.
     */
    public function writeCookieIfNeeded(Request $request, Response $response): void
    {
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
     * Besucher-ID, die `resolveForRequest` im Request abgelegt hat. Null, solange
     * der Request-Lebenszyklus noch keine ID aufgelöst hat.
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

        if (!\is_string($raw) || !Uuid::isValid($raw)) {
            // Korrupter Cookie-Wert: verworfen und als Audit-Spur protokolliert,
            // damit Manipulation oder Client-Bugs nachvollziehbar bleiben.
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
            ->withHttpOnly(false) // Storefront-JS muss die ID für custom-Events lesen können.
            ->withSameSite(Cookie::SAMESITE_LAX);
    }
}
