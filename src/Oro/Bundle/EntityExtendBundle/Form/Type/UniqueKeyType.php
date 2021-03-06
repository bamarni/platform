<?php

namespace Oro\Bundle\EntityExtendBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;

use Oro\Bundle\EntityConfigBundle\Config\Id\FieldConfigId;

class UniqueKeyType extends AbstractType
{
    /**
     * @var FieldConfigId[]
     */
    protected $fields;

    /**
     * @param array $fields
     */
    public function __construct(array $fields)
    {
        $this->fields = $fields;
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add(
            'name',
            'text',
            array(
                'label' => 'oro.entity_extend.form.name.label',
                'required' => true,
            )
        );

        $builder->add(
            'key',
            'choice',
            array(
                'label' => 'oro.entity_extend.form.key.label',
                'multiple' => true,
                'choices'  => $this->fields,
                'required' => true,
            )
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'oro_entity_extend_unique_key_type';
    }
}
