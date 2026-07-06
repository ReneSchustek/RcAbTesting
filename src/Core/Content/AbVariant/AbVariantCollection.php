<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Core\Content\AbVariant;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<AbVariantEntity>
 *
 * @method AbVariantEntity[]    getIterator()
 * @method AbVariantEntity[]    getElements()
 * @method AbVariantEntity|null get(string $key)
 * @method AbVariantEntity|null first()
 * @method AbVariantEntity|null last()
 */
final class AbVariantCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return AbVariantEntity::class;
    }
}
