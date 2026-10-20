<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Core\Content\AbExperiment;

/**
 * Zulässige Werte für `rc_ab_experiment.status`. Der Lebenszyklus führt von `draft` über
 * `running`, mit `paused` als Zwischenhalt, zu `done` und `archived`. Die Verwaltungs-API prüft
 * den Ausgangszustand beim Starten, Pausieren, Beenden und Archivieren.
 *
 * Gespeichert als VARCHAR statt als ENUM der Datenbank, damit ein neuer Wert keine
 * Schemaänderung braucht.
 *
 * Zeichenketten-Konstanten statt Enum: Die Werte laufen als Zeichenketten durch die Datenschicht, die
 * Verwaltungs-API und die Vorlagen; ein Enum müsste an jeder dieser Grenzen in seinen Wert und zurück verwandelt
 * werden.
 */
final class AbExperimentStatus
{
    public const DRAFT = 'draft';
    public const RUNNING = 'running';
    public const PAUSED = 'paused';
    public const DONE = 'done';
    public const ARCHIVED = 'archived';

    /** @var list<string> */
    public const VALUES = [
        self::DRAFT,
        self::RUNNING,
        self::PAUSED,
        self::DONE,
        self::ARCHIVED,
    ];

    /**
     * Zustände, aus denen ein Experiment beendet werden kann. Ein Entwurf hat nie gemessen, und ein
     * beendetes oder archiviertes Experiment bekäme sonst ein neues Enddatum, das seine Laufzeit in der
     * Auswertung verfälscht. Verwaltung und `rc:ab:end` prüfen gegen dieselbe Liste.
     *
     * @var list<string>
     */
    public const ENDABLE = [
        self::RUNNING,
        self::PAUSED,
    ];

    private function __construct()
    {
    }
}
