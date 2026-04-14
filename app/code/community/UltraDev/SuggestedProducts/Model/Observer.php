<?php
declare(strict_types=1);

class UltraDev_SuggestedProducts_Model_Observer
{
    /**
     * Fired on catalog_product_load_after.
     * Injects auto-suggested IDs into related / upsell product collections
     * when the manual list is empty (or override_manual = 1).
     */
    public function injectSuggestedProducts(Varien_Event_Observer $observer): void
    {
        /** @var UltraDev_SuggestedProducts_Helper_Data $helper */
        $helper = Mage::helper('ultradev_suggestedproducts');

        if (!$helper->isEnabled()) {
            return;
        }

        /** @var Mage_Catalog_Model_Product $product */
        $product = $observer->getEvent()->getProduct();

        if (!$product instanceof Mage_Catalog_Model_Product || !$product->getId()) {
            return;
        }

        /** @var UltraDev_SuggestedProducts_Model_Suggester $suggester */
        $suggester = Mage::getSingleton('ultradev_suggestedproducts/suggester');

        // ── Related ──────────────────────────────────────────────────────────
        if ($helper->isRelatedEnabled()) {
            $override = $helper->getRelatedConfig('override_manual');
            $manual   = $product->getRelatedProductIds();

            if ($override || empty($manual)) {
                $ids = $suggester->getSuggestedIds($product, 'related');
                $product->setRelatedProductIds(
                    $override ? $ids : array_merge($manual, $ids)
                );
            }
        }

        // ── Upsell ───────────────────────────────────────────────────────────
        if ($helper->isUpsellEnabled()) {
            $override = $helper->getUpsellConfig('override_manual');
            $manual   = $product->getUpSellProductIds();

            if ($override || empty($manual)) {
                $ids = $suggester->getSuggestedIds($product, 'upsell');
                $product->setUpSellProductIds(
                    $override ? $ids : array_merge($manual, $ids)
                );
            }
        }
    }

    /**
     * Fired on checkout_cart_product_add_after.
     * Injects auto cross-sell IDs so the cart page suggestions are populated.
     */
    public function injectCrossSells(Varien_Event_Observer $observer): void
    {
        /** @var UltraDev_SuggestedProducts_Helper_Data $helper */
        $helper = Mage::helper('ultradev_suggestedproducts');

        if (!$helper->isCrosssellEnabled()) {
            return;
        }

        /** @var Mage_Sales_Model_Quote_Item $quoteItem */
        $quoteItem = $observer->getEvent()->getQuoteItem();
        $product   = $quoteItem->getProduct();

        if (!$product instanceof Mage_Catalog_Model_Product || !$product->getId()) {
            return;
        }

        $override = $helper->getCrosssellConfig('override_manual');
        $manual   = $product->getCrossSellProductIds();

        if ($override || empty($manual)) {
            /** @var UltraDev_SuggestedProducts_Model_Suggester $suggester */
            $suggester = Mage::getSingleton('ultradev_suggestedproducts/suggester');
            $ids       = $suggester->getSuggestedIds($product, 'crosssell');

            $product->setCrossSellProductIds(
                $override ? $ids : array_merge($manual, $ids)
            );
        }
    }
}
