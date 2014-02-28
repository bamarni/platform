<?php

namespace Oro\Bundle\EntityExtendBundle\Tools;

use Symfony\Component\Filesystem\Filesystem;

use Oro\Bundle\EntityConfigBundle\Config\Config;
use Oro\Bundle\EntityConfigBundle\Config\ConfigInterface;

use Oro\Bundle\EntityBundle\ORM\OroEntityManager;
use Oro\Bundle\EntityExtendBundle\EntityConfig\ExtendScope;
use Oro\Bundle\EntityExtendBundle\Mapping\ExtendClassMetadataFactory;

class ExtendConfigDumper
{
    const ENTITY         = 'Extend\\Entity\\';
    const TABLE_PREFIX   = 'oro_extend_';
    const FIELD_PREFIX   = 'field_';
    const DEFAULT_PREFIX = 'default_';

    /**
     * @var string
     */
    protected $cacheDir;

    /**
     * @var OroEntityManager
     */
    protected $em;

    /**
     * @param OroEntityManager $em
     * @param string           $cacheDir
     */
    public function __construct(OroEntityManager $em, $cacheDir)
    {
        $this->cacheDir = $cacheDir;
        $this->em       = $em;
    }

    /**
     * @param null $className
     */
    public function updateConfig($className = null)
    {
        $this->clear();

        $extendProvider = $this->em->getExtendConfigProvider();

        $extendConfigs = $className
            ? [$extendProvider->getConfig($className)]
            : $extendProvider->getConfigs();

        foreach ($extendConfigs as $extendConfig) {
            $this->checkSchema($extendConfig);
        }

        $this->clear();
    }

    public function dump()
    {
        $schemas        = [];
        $extendProvider = $this->em->getExtendConfigProvider();
        $extendConfigs  = $extendProvider->getConfigs();
        foreach ($extendConfigs as $extendConfig) {
            $schema = $extendConfig->get('schema');
            if ($schema) {
                $schemas[$extendConfig->getId()->getClassName()] = $schema;
            }
        }

        $generator = new Generator($this->cacheDir);
        $generator->generate($schemas);
    }

    public function clear()
    {
        $filesystem   = new Filesystem();
        $baseCacheDir = ExtendClassLoadingUtils::getEntityBaseCacheDir($this->cacheDir);
        if ($filesystem->exists($baseCacheDir)) {
            $filesystem->remove([$baseCacheDir]);
        }

        $filesystem->mkdir(ExtendClassLoadingUtils::getEntityCacheDir($this->cacheDir));

        /** @var ExtendClassMetadataFactory $metadataFactory */
        $metadataFactory = $this->em->getMetadataFactory();
        $metadataFactory->clearCache();
    }

    /**
     * @param ConfigInterface $extendConfig
     *
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    protected function checkSchema(ConfigInterface $extendConfig)
    {
        if (!$extendConfig->is('is_extend') || !$extendConfig->is('upgradeable')) {
            return;
        }

        $extendProvider = $this->em->getExtendConfigProvider();
        $className      = $extendConfig->getId()->getClassName();
        $doctrine       = [];

        if (strpos($className, self::ENTITY) !== false) {
            $type       = 'Custom';
            $entityName = $className;
            $tableName  = $extendConfig->get('table');
            if (!$tableName) {
                $tableName = self::TABLE_PREFIX
                    . strtolower(str_replace('\\', '', str_replace(self::ENTITY, '', $entityName)));
            }
            $doctrine[$entityName] = [
                'type'   => 'entity',
                'table'  => $tableName,
                'fields' => [
                    'id' => ['type' => 'integer', 'id' => true, 'generator' => ['strategy' => 'AUTO']]
                ],
            ];
        } else {
            $entityName            = $extendConfig->get('extend_class');
            $type                  = 'Extend';
            $doctrine[$entityName] = [
                'type'   => 'mappedSuperclass',
                'fields' => [],
            ];
        }

        $entityState = $extendConfig->get('state');

        $schema             = $extendConfig->get('schema');
        $properties         = array();
        $relationProperties = $schema ? $schema['relation'] : array();
        $defaultProperties  = array();
        $addRemoveMethods   = array();

        if ($fieldConfigs = $extendProvider->getConfigs($className)) {
            foreach ($fieldConfigs as $fieldConfig) {
                if ($fieldConfig->is('extend')) {
                    $fieldName = self::FIELD_PREFIX . $fieldConfig->getId()->getFieldName();
                    $fieldType = $fieldConfig->getId()->getFieldType();

                    if (in_array($fieldType, ['oneToMany', 'manyToOne', 'manyToMany', 'optionSet'])) {
                        $relationProperties[$fieldName] = $fieldConfig->getId()->getFieldName();
                        if ($fieldType != 'manyToOne') {
                            $defaultName = self::DEFAULT_PREFIX . $fieldConfig->getId()->getFieldName();

                            $defaultProperties[$defaultName] = $defaultName;
                        }
                    } else {
                        $properties[$fieldName] = $fieldConfig->getId()->getFieldName();

                        $doctrine[$entityName]['fields'][$fieldName]['code']      = $fieldName;
                        $doctrine[$entityName]['fields'][$fieldName]['type']      = $fieldType;
                        $doctrine[$entityName]['fields'][$fieldName]['nullable']  = true;
                        $doctrine[$entityName]['fields'][$fieldName]['length']    = $fieldConfig->get('length');
                        $doctrine[$entityName]['fields'][$fieldName]['precision'] = $fieldConfig->get('precision');
                        $doctrine[$entityName]['fields'][$fieldName]['scale']     = $fieldConfig->get('scale');
                    }
                }

                if (!$fieldConfig->is('state', ExtendScope::STATE_DELETED)) {
                    $fieldConfig->set('state', ExtendScope::STATE_ACTIVE);
                }

                if ($fieldConfig->is('state', ExtendScope::STATE_DELETED)) {
                    $fieldConfig->set('is_deleted', true);
                }

                $extendProvider->persist($fieldConfig);
            }
        }

        $extendProvider->flush();

        $extendConfig->set('state', $entityState);
        if ($extendConfig->is('state', ExtendScope::STATE_DELETED)) {
            $extendConfig->set('is_deleted', true);

            $extendProvider->map(
                function (Config $config) use ($extendProvider) {
                    $config->set('is_deleted', true);
                    $extendProvider->persist($config);
                },
                $className
            );
        } else {
            $extendConfig->set('state', ExtendScope::STATE_ACTIVE);
        }

        $relations = $extendConfig->get('relation') ? : [];
        foreach ($relations as &$relation) {
            if ($relation['field_id']) {
                $relation['assign'] = true;
                if ($relation['field_id']->getFieldType() != 'manyToOne'
                    && $relation['target_field_id']
                ) {
                    $fieldName = self::FIELD_PREFIX . $relation['field_id']->getFieldName();

                    $addRemoveMethods[$fieldName]['self']   = $relation['field_id']->getFieldName();
                    $addRemoveMethods[$fieldName]['target'] = $relation['target_field_id']->getFieldName();
                    $addRemoveMethods[$fieldName]['is_target_addremove']
                                                            = $relation['field_id']->getFieldType() == 'manyToMany';
                }

                $this->checkRelation($relation['target_entity'], $relation['field_id']);
            }
        }
        $extendConfig->set('relation', $relations);

        $schema = [
            'class'     => $className,
            'entity'    => $entityName,
            'type'      => $type,
            'property'  => $properties,
            'relation'  => $relationProperties,
            'default'   => $defaultProperties,
            'addremove' => $addRemoveMethods,
            'doctrine'  => $doctrine,
        ];

        if ($type == 'Extend') {
            $schema['parent']  = get_parent_class($className);
            $schema['inherit'] = get_parent_class($schema['parent']);
        }

        $extendConfig->set('schema', $schema);

        $extendProvider->persist($extendConfig);
        $extendProvider->flush();
    }

    protected function checkRelation($targetClass, $fieldId)
    {
        $extendProvider = $this->em->getExtendConfigProvider();
        $targetConfig   = $extendProvider->getConfig($targetClass);

        $relations = $targetConfig->get('relation') ? : [];
        $schema    = $targetConfig->get('schema') ? : [];

        foreach ($relations as &$relation) {
            if ($relation['target_field_id'] == $fieldId) {
                $relation['assign'] = true;
                $relationFieldId    = $relation['field_id'];

                if ($relationFieldId && count($schema)) {
                    $schema['relation'][self::FIELD_PREFIX . $relationFieldId->getFieldName()] =
                        $relationFieldId->getFieldName();
                }
            }
        }

        $targetConfig->set('relation', $relations);
        $targetConfig->set('schema', $schema);

        $extendProvider->persist($targetConfig);
    }

    /**
     * Get Entity Identifier By a class name
     *
     * @param $className
     * @return string
     */
    protected function getEntityIdentifier($className)
    {
        // Extend entity always have "id" identifier
        if (strpos($className, self::ENTITY) !== false) {
            return 'id';
        }

        return $this->em->getClassMetadata($className)->getSingleIdentifierColumnName();
    }
}
