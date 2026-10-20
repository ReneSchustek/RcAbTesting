<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Service\AbEventType;

/**
 * Prüft die Liste zulässiger Ereignistypen und die Grenze zwischen Server- und Browsertypen.
 * Ließe `isClientTrackable()` einen Typ aus `KNOWN` durch, könnte jeder Besucher über den
 * offenen Tracking-Endpunkt Bestellungen samt Umsatz melden.
 */
final class AbEventTypeTest extends TestCase
{
    #[DataProvider('validTypes')]
    public function testValidTypesAreAccepted(string $eventType): void
    {
        self::assertTrue(AbEventType::isValid($eventType));
    }

    #[DataProvider('invalidTypes')]
    public function testInvalidTypesAreRejected(string $eventType): void
    {
        self::assertFalse(AbEventType::isValid($eventType));
    }

    public function testCustomEventsAreClientTrackable(): void
    {
        self::assertTrue(AbEventType::isClientTrackable('custom.newsletter_signup'));
    }

    #[DataProvider('serverOnlyTypes')]
    public function testServerSideTypesAreNotClientTrackable(string $eventType): void
    {
        // Jeder Typ aus `KNOWN` ist für den Server gültig, darf aber nicht aus dem Browser kommen.
        self::assertTrue(AbEventType::isValid($eventType));
        self::assertFalse(AbEventType::isClientTrackable($eventType));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function serverOnlyTypes(): iterable
    {
        foreach (AbEventType::KNOWN as $known) {
            yield $known => [$known];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validTypes(): iterable
    {
        foreach (AbEventType::KNOWN as $known) {
            yield $known => [$known];
        }

        yield 'custom event' => ['custom.newsletter_signup'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTypes(): iterable
    {
        yield 'empty' => [''];
        yield 'unbekannt' => ['page.scrolled'];
        yield 'custom ohne suffix' => ['custom.'];
        // Der Vergleich mit `KNOWN` ist zeichengenau, auch in der Großschreibung.
        yield 'falsche schreibweise' => ['Page.Viewed'];
        // Beginnt mit „custom", aber nicht mit dem Präfix samt Punkt.
        yield 'präfix-fake' => ['customx.thing'];
    }
}
