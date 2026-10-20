<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Core\Content\AbVariant;

use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\CascadeDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * DAL-Definition von `rc_ab_variant`, einer Variante eines Experiments, Kontrolle oder Treatment.
 * Dass die Gewichte eines Experiments zusammen 100 ergeben, prüft der
 * `ExperimentIntegrityValidator` vor dem Start; Schema und DAL setzen die Summe nicht durch.
 */
final class AbVariantDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'rc_ab_variant';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return AbVariantEntity::class;
    }

    public function getCollectionClass(): string
    {
        return AbVariantCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new ApiAware(), new PrimaryKey(), new Required()),
(new FkField(
    'experiment_id',
    'experimentId',
    AbExperimentDefinition::class
))->addFlags(
    new ApiAware(),
    new Required()
),
            (new StringField('technical_key', 'technicalKey', 64))->addFlags(new ApiAware(), new Required()),
            (new StringField('name', 'name'))->addFlags(new ApiAware(), new Required()),
            (new LongTextField('description', 'description'))->addFlags(new ApiAware()),
            // Untergrenze 0: Ein negatives Gewicht weist die DAL schon beim Schreiben ab.
            (new IntField('weight', 'weight', 0))->addFlags(new ApiAware(), new Required()),
            (new BoolField('is_control', 'isControl'))->addFlags(new ApiAware(), new Required()),
            (new JsonField('config', 'config'))->addFlags(new ApiAware()),

            (new ManyToOneAssociationField('experiment', 'experiment_id', AbExperimentDefinition::class, 'id', false))
                ->addFlags(new ApiAware(), new CascadeDelete()),
        ]);
    }
}
