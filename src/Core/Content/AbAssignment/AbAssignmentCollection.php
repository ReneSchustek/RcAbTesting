<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Core\Content\AbAssignment;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<AbAssignmentEntity>
 *
 * @method AbAssignmentEntity[]    getIterator()
 * @method AbAssignmentEntity[]    getElements()
 * @method AbAssignmentEntity|null get(string $key)
 * @method AbAssignmentEntity|null first()
 * @method AbAssignmentEntity|null last()
 */
final class AbAssignmentCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return AbAssignmentEntity::class;
    }
}
