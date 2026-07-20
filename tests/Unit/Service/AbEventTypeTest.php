<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Service\AbEventType;

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
        // Funnel-/Order-Typen der KNOWN-Liste duerfen nicht aus dem Browser
        // kommen (Arbeitspaket AB33) — sie bleiben serverseitig gueltig (isValid), sind
        // aber nicht client-trackbar.
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
        yield 'leer' => [''];
        yield 'unbekannt' => ['page.scrolled'];
        yield 'custom ohne suffix' => ['custom.'];
        yield 'falsche schreibweise' => ['Page.Viewed'];
        yield 'präfix-fake' => ['customx.thing'];
    }
}
