<?php

defined('_JEXEC') || die;

use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\CMS\Form\FormHelper;

FormHelper::loadFieldClass('list');

class JFormFieldRecacheComps extends ListField
{
    /**
     * The form field type.
     */
    protected $type = 'RecacheComps';

    /**
     * Method to get the field options.
     */
    protected function getOptions(): array
    {
        $options = [];
        if (!defined('LITESPEED_CACHE_HELPER')) {
            $options[] = (object) [
                            'value' => "com_virtuemart",
                            'text' => "com_virtuemart: Virtuemart",
                        ];

            $options[] = (object) [
                            'value' => "com_content",
                            'text' => "com_content: Articles",
                        ];
            return array_merge(parent::getOptions(), $options);
        }

        $helper = LITESPEED_CACHE_HELPER;
        $options = $helper->getCustomisedUrlComponents();
        return array_merge(parent::getOptions(), $options);
    }
}
