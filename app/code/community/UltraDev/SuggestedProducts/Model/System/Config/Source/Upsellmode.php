<?php
declare(strict_types=1);

class UltraDev_SuggestedProducts_Model_System_Config_Source_Upsellmode
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'higher_price',   'label' => Mage::helper('ultradev_suggestedproducts')->__('Higher Price (Upsell)')],
            ['value' => 'same_category',  'label' => Mage::helper('ultradev_suggestedproducts')->__('Same Category')],
            ['value' => 'same_attribute', 'label' => Mage::helper('ultradev_suggestedproducts')->__('Same Manufacturer / Attribute')],
            ['value' => 'lower_price',    'label' => Mage::helper('ultradev_suggestedproducts')->__('Lower Price')],
            ['value' => 'random',         'label' => Mage::helper('ultradev_suggestedproducts')->__('Random')],
        ];
    }
}
