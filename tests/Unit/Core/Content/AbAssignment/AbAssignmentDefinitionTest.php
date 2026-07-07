<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Core\Content\AbAssignment;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Core\Content\AbAssignment\AbAssignmentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbAssignment\AbAssignmentDefinition;
use Ruhrcoder\RcAbTesting\Core\Content\AbAssignment\AbAssignmentEntity;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

final class AbAssignmentDefinitionTest extends TestCase
{
    public function testEntityNameIsPinned(): void
    {
        self::assertSame('rc_ab_assignment', AbAssignmentDefinition::ENTITY_NAME);
        self::assertSame('rc_ab_assignment', (new AbAssignmentDefinition())->getEntityName());
    }

    public function testEntityAndCollectionClassesAreWired(): void
    {
        $definition = new AbAssignmentDefinition();

        self::assertSame(AbAssignmentEntity::class, $definition->getEntityClass());
        self::assertSame(AbAssignmentCollection::class, $definition->getCollectionClass());
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
                'salesChannelId',
                'salesChannel',
                'languageId',
                'language',
                'assignedAt',
                'lastSeenAt',
                'device',
            ],
            $this->collectFieldKeys(new AbAssignmentDefinition()),
        );
    }

    /**
     * @return list<string>
     */
    private function collectFieldKeys(AbAssignmentDefinition $definition): array
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
