<?php
declare(strict_types=1);

class UltraDev_SuggestedProducts_Helper_Data extends Mage_Core_Helper_Abstract
{
    public function isEnabled(): bool
    {
        return Mage::getStoreConfigFlag('ultradev_suggestedproducts/general/enabled');
    }

    public function isRelatedEnabled(): bool
    {
        return $this->isEnabled()
            && Mage::getStoreConfigFlag('ultradev_suggestedproducts/related/enabled');
    }

    public function isUpsellEnabled(): bool
    {
        return $this->isEnabled()
            && Mage::getStoreConfigFlag('ultradev_suggestedproducts/upsell/enabled');
    }

    public function isCrosssellEnabled(): bool
    {
        return $this->isEnabled()
            && Mage::getStoreConfigFlag('ultradev_suggestedproducts/crosssell/enabled');
    }

    public function getRelatedConfig(string $key): mixed
    {
        return Mage::getStoreConfig("ultradev_suggestedproducts/related/{$key}");
    }

    public function getUpsellConfig(string $key): mixed
    {
        return Mage::getStoreConfig("ultradev_suggestedproducts/upsell/{$key}");
    }

    public function getCrosssellConfig(string $key): mixed
    {
        return Mage::getStoreConfig("ultradev_suggestedproducts/crosssell/{$key}");
    }
}
