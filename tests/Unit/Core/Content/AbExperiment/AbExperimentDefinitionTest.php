<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Core\Content\AbExperiment;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentDefinition;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * Schema-Pinning: fixiert Tabellen-Namen, Entity-/Collection-Klassen und die
 * Liste der DAL-Felder. Bricht, sobald jemand eine Migration ohne abgestimmte
 * Definitions-Änderung einspielt.
 */
final class AbExperimentDefinitionTest extends TestCase
{
    public function testEntityNameIsPinned(): void
    {
        self::assertSame('rc_ab_experiment', AbExperimentDefinition::ENTITY_NAME);
        self::assertSame('rc_ab_experiment', (new AbExperimentDefinition())->getEntityName());
    }

    public function testEntityAndCollectionClassesAreWired(): void
    {
        $definition = new AbExperimentDefinition();

        self::assertSame(AbExperimentEntity::class, $definition->getEntityClass());
        self::assertSame(AbExperimentCollection::class, $definition->getCollectionClass());
    }

    public function testFieldsArePinned(): void
    {
        self::assertEqualsCanonicalizing(
            [
                'id',
                'technicalKey',
                'name',
                'hypothesis',
                'status',
                'testType',
                'primaryMetric',
                'secondaryMetrics',
                'decisionMetric',
                'trafficAllocationPct',
                'minSampleSize',
                'targetSignificance',
                'salesChannelId',
                'salesChannel',
                'ruleId',
                'rule',
                'startedAt',
                'endedAt',
                'scheduledStartAt',
                'scheduledEndAt',
                'winnerVariantId',
                'winnerVariant',
                'createdById',
                'createdBy',
                'notes',
                'variants',
            ],
            $this->collectFieldKeys(new AbExperimentDefinition()),
        );
    }

    /**
     * Holt die nicht-kompilierten Felder via Reflection — vermeidet die DAL-Registry,
     * damit der Test ohne Symfony-Container läuft.
     *
     * @return list<string>
     */
    private function collectFieldKeys(AbExperimentDefinition $definition): array
    {
        $method = new \ReflectionMethod($definition, 'defineFields');
        $method->setAccessible(true);

        /** @var FieldCollection $fields */
        $fields = $method->invoke($definition);
        $keys = [];
        foreach ($fields->getElements() as $field) {
            $keys[] = $field->getPropertyName();
        }

        return $keys;
    }
}
