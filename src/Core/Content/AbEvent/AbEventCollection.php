<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Core\Content\AbEvent;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<AbEventEntity>
 *
 * @method AbEventEntity[]    getIterator()
 * @method AbEventEntity[]    getElements()
 * @method AbEventEntity|null get(string $key)
 * @method AbEventEntity|null first()
 * @method AbEventEntity|null last()
 */
final class AbEventCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return AbEventEntity::class;
    }
}
