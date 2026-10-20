<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Core\Content\AbEvent;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Core\Content\AbEvent\AbEventCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbEvent\AbEventDefinition;
use Ruhrcoder\RcAbTesting\Core\Content\AbEvent\AbEventEntity;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * Hält Namen und Feldliste der Ereignis-Definition fest. Die Aggregatoren lesen die Tabelle
 * mit rohem SQL an der DAL vorbei; ein umbenanntes Feld fiele dort erst zur Laufzeit auf.
 */
final class AbEventDefinitionTest extends TestCase
{
    public function testEntityNameIsPinned(): void
    {
        self::assertSame('rc_ab_event', AbEventDefinition::ENTITY_NAME);
        self::assertSame('rc_ab_event', (new AbEventDefinition())->getEntityName());
    }

    public function testEntityAndCollectionClassesAreWired(): void
    {
        $definition = new AbEventDefinition();

        self::assertSame(AbEventEntity::class, $definition->getEntityClass());
        self::assertSame(AbEventCollection::class, $definition->getCollectionClass());
    }

    public function testFieldsArePinned(): void
    {
        self::assertEqualsCanonicalizing(
            [
                'id',
                'experimentId',
                'experiment',
                'variantId',
                'variant',
                'visitorId',
                'customerId',
                'customer',
                'eventType',
                'eventValue',
                'meta',
                'sessionId',
                'occurredAt',
            ],
            $this->collectFieldKeys(new AbEventDefinition()),
        );
    }

    /**
     * @return list<string>
     */
    private function collectFieldKeys(AbEventDefinition $definition): array
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
