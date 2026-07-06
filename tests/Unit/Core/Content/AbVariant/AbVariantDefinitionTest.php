<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Core\Content\AbVariant;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantDefinition;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantEntity;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

final class AbVariantDefinitionTest extends TestCase
{
    public function testEntityNameIsPinned(): void
    {
        self::assertSame('rc_ab_variant', AbVariantDefinition::ENTITY_NAME);
        self::assertSame('rc_ab_variant', (new AbVariantDefinition())->getEntityName());
    }

    public function testEntityAndCollectionClassesAreWired(): void
    {
        $definition = new AbVariantDefinition();

        self::assertSame(AbVariantEntity::class, $definition->getEntityClass());
        self::assertSame(AbVariantCollection::class, $definition->getCollectionClass());
    }

    public function testFieldsArePinned(): void
    {
        self::assertEqualsCanonicalizing(
            [
                'id',
                'experimentId',
                'experiment',
                'technicalKey',
                'name',
                'description',
                'weight',
                'isControl',
                'config',
            ],
            $this->collectFieldKeys(new AbVariantDefinition()),
        );
    }

    /**
     * @return list<string>
     */
    private function collectFieldKeys(AbVariantDefinition $definition): array
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
