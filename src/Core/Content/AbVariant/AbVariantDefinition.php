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
 * Definition für `rc_ab_variant` — eine Variante eines Experiments (Control oder
 * Treatment). Gewichtungen pro Experiment summieren in der Anwendungslogik auf 100;
 * DB-Schema setzt die Summen-Regel nicht durch.
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
            (new FkField('experiment_id', 'experimentId', AbExperimentDefinition::class))->addFlags(new ApiAware(), new Required()),
            (new StringField('technical_key', 'technicalKey', 64))->addFlags(new ApiAware(), new Required()),
            (new StringField('name', 'name'))->addFlags(new ApiAware(), new Required()),
            (new LongTextField('description', 'description'))->addFlags(new ApiAware()),
            (new IntField('weight', 'weight', 0))->addFlags(new ApiAware(), new Required()),
            (new BoolField('is_control', 'isControl'))->addFlags(new ApiAware(), new Required()),
            (new JsonField('config', 'config'))->addFlags(new ApiAware()),

            (new ManyToOneAssociationField('experiment', 'experiment_id', AbExperimentDefinition::class, 'id', false))
                ->addFlags(new ApiAware(), new CascadeDelete()),
        ]);
    }
}
