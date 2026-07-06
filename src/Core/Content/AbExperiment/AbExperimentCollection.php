<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Core\Content\AbExperiment;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<AbExperimentEntity>
 *
 * @method AbExperimentEntity[]    getIterator()
 * @method AbExperimentEntity[]    getElements()
 * @method AbExperimentEntity|null get(string $key)
 * @method AbExperimentEntity|null first()
 * @method AbExperimentEntity|null last()
 */
final class AbExperimentCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return AbExperimentEntity::class;
    }
}
