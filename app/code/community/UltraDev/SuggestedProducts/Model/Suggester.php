<?php
declare(strict_types=1);

/**
 * Core suggestion engine.
 *
 * Hierarchy for category-based modes:
 *   1. Same category as the product
 *   2. Parent category (if not enough results)
 *   3. Random store-wide (if still not enough)
 */
class UltraDev_SuggestedProducts_Model_Suggester
{
    /**
     * Return an array of product IDs to suggest for a given type.
     *
     * @param  Mage_Catalog_Model_Product $product
     * @param  string                     $type  'related'|'upsell'|'crosssell'
     * @return int[]
     */
    public function getSuggestedIds(Mage_Catalog_Model_Product $product, string $type): array
    {
        /** @var UltraDev_SuggestedProducts_Helper_Data $helper */
        $helper = Mage::helper('ultradev_suggestedproducts');

        $mode = (string) match ($type) {
            'related'   => $helper->getRelatedConfig('mode'),
            'upsell'    => $helper->getUpsellConfig('mode'),
            'crosssell' => $helper->getCrosssellConfig('mode'),
            default     => 'same_category',
        };

        $limit = (int) match ($type) {
            'related'   => $helper->getRelatedConfig('limit'),
            'upsell'    => $helper->getUpsellConfig('limit'),
            'crosssell' => $helper->getCrosssellConfig('limit'),
            default     => 4,
        };

        $ids = match ($mode) {
            'same_category'  => $this->byCategoryHierarchy($product, $limit, $type),
            'same_attribute' => $this->bySameAttribute($product, $limit, $type),
            'higher_price'   => $this->byHigherPrice($product, $limit),
            'lower_price'    => $this->byLowerPrice($product, $limit),
            'random'         => $this->byRandom($product, $limit, []),
            default          => $this->byCategoryHierarchy($product, $limit, $type),
        };

        return array_values(array_unique($ids));
    }

    // ─── Category hierarchy (main logic) ─────────────────────────────────────

    /**
     * 1) Same category → 2) Parent category → 3) Random
     */
    private function byCategoryHierarchy(
        Mage_Catalog_Model_Product $product,
        int $limit,
        string $type
    ): array {
        $exclude    = [(int) $product->getId()];
        $collected  = [];

        $categoryIds = $product->getCategoryIds();

        // ── Step 1: same categories (product can belong to multiple) ──────────
        if (!empty($categoryIds)) {
            $ids = $this->fromCategories($categoryIds, $limit, $exclude, $type);
            $collected  = array_merge($collected, $ids);
            $exclude    = array_merge($exclude, $ids);
        }

        // ── Step 2: parent categories (one level up) ──────────────────────────
        if (count($collected) < $limit) {
            $needed     = $limit - count($collected);
            $parentIds  = $this->getParentCategoryIds($categoryIds);

            if (!empty($parentIds)) {
                $ids       = $this->fromCategories($parentIds, $needed, $exclude, $type);
                $collected = array_merge($collected, $ids);
                $exclude   = array_merge($exclude, $ids);
            }
        }

        // ── Step 3: random fallback ───────────────────────────────────────────
        if (count($collected) < $limit) {
            $needed    = $limit - count($collected);
            $ids       = $this->byRandom($product, $needed, $exclude);
            $collected = array_merge($collected, $ids);
        }

        return array_slice(array_unique($collected), 0, $limit);
    }

    /**
     * Fetch product IDs from a list of category IDs, respecting exclusions.
     *
     * @param  int[]    $categoryIds
     * @param  int      $limit
     * @param  int[]    $excludeIds   Product IDs already collected (or the source product itself)
     * @param  string   $type
     * @return int[]
     */
    private function fromCategories(
        array $categoryIds,
        int $limit,
        array $excludeIds,
        string $type
    ): array {
        /** @var Mage_Catalog_Model_Resource_Product_Collection $collection */
        $collection = Mage::getResourceModel('catalog/product_collection');
        $collection
            ->addAttributeToFilter('status',
                Mage_Catalog_Model_Product_Status::STATUS_ENABLED)
            ->addAttributeToFilter('visibility', ['in' => [
                Mage_Catalog_Model_Product_Visibility::VISIBILITY_IN_CATALOG,
                Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH,
            ]])
            ->addAttributeToFilter('entity_id', ['nin' => $excludeIds])
            ->setPageSize($limit);

        // Join category membership
        $collection->getSelect()
            ->join(
                ['cat_prod' => $collection->getTable('catalog/category_product')],
                'cat_prod.product_id = e.entity_id AND cat_prod.category_id IN ('
                    . implode(',', array_map('intval', $categoryIds)) . ')',
                []
            )
            ->group('e.entity_id')
            ->order(new Zend_Db_Expr('RAND()'));

        return array_map('intval', $collection->getAllIds());
    }

    /**
     * Resolve one level of parent categories from a list of category IDs.
     *
     * @param  int[] $categoryIds
     * @return int[]
     */
    private function getParentCategoryIds(array $categoryIds): array
    {
        $parentIds = [];

        // Root category (level <= 1) and "Default Category" (level 2 in Magento)
        // should not be used as suggestion sources.
        $minLevel = 3;

        foreach ($categoryIds as $catId) {
            /** @var Mage_Catalog_Model_Category $category */
            $category = Mage::getModel('catalog/category')->load((int) $catId);

            if (!$category->getId()) {
                continue;
            }

            $parentId = (int) $category->getParentId();
            if (!$parentId) {
                continue;
            }

            /** @var Mage_Catalog_Model_Category $parent */
            $parent = Mage::getModel('catalog/category')->load($parentId);

            if ($parent->getId() && (int) $parent->getLevel() >= $minLevel) {
                $parentIds[] = $parentId;
            }
        }

        return array_values(array_unique($parentIds));
    }

    // ─── Other modes ─────────────────────────────────────────────────────────

    /**
     * Same manufacturer attribute, falling back to category hierarchy.
     */
    private function bySameAttribute(
        Mage_Catalog_Model_Product $product,
        int $limit,
        string $type
    ): array {
        $manufacturerId = $product->getManufacturer();

        if (!$manufacturerId) {
            return $this->byCategoryHierarchy($product, $limit, $type);
        }

        $exclude = [(int) $product->getId()];

        /** @var Mage_Catalog_Model_Resource_Product_Collection $collection */
        $collection = Mage::getResourceModel('catalog/product_collection');
        $collection
            ->addAttributeToFilter('status',
                Mage_Catalog_Model_Product_Status::STATUS_ENABLED)
            ->addAttributeToFilter('visibility', ['in' => [
                Mage_Catalog_Model_Product_Visibility::VISIBILITY_IN_CATALOG,
                Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH,
            ]])
            ->addAttributeToFilter('manufacturer', $manufacturerId)
            ->addAttributeToFilter('entity_id', ['nin' => $exclude])
            ->setPageSize($limit)
            ->setOrder('RAND()', '');

        $ids = array_map('intval', $collection->getAllIds());

        // Fill remainder with category hierarchy
        if (count($ids) < $limit) {
            $needed  = $limit - count($ids);
            $exclude = array_merge($exclude, $ids);

            $fill = $this->byCategoryHierarchy(
                $product,
                $needed,
                $type  // passes type so override_manual exclusion is consistent
            );

            // byCategoryHierarchy already excludes $product but not our $ids,
            // so filter duplicates
            $fill = array_diff($fill, $ids);
            $ids  = array_merge($ids, array_slice($fill, 0, $needed));
        }

        return array_slice(array_unique($ids), 0, $limit);
    }

    private function byHigherPrice(
        Mage_Catalog_Model_Product $product,
        int $limit
    ): array {
        $factor   = (float) Mage::getStoreConfig('ultradev_suggestedproducts/upsell/price_factor');
        $minPrice = $product->getFinalPrice() * max(1.0, $factor);

        /** @var Mage_Catalog_Model_Resource_Product_Collection $collection */
        $collection = Mage::getResourceModel('catalog/product_collection');
        $collection
            ->addAttributeToFilter('status',
                Mage_Catalog_Model_Product_Status::STATUS_ENABLED)
            ->addAttributeToFilter('visibility', ['in' => [
                Mage_Catalog_Model_Product_Visibility::VISIBILITY_IN_CATALOG,
                Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH,
            ]])
            ->addAttributeToFilter('entity_id', ['neq' => $product->getId()])
            ->addPriceData();

        $collection->getSelect()
            ->where('price_index.final_price >= ?', $minPrice)
            ->limit($limit)
            ->order('price_index.final_price ASC');

        return array_map('intval', $collection->getAllIds());
    }

    private function byLowerPrice(
        Mage_Catalog_Model_Product $product,
        int $limit
    ): array {
        $maxPrice = $product->getFinalPrice() * 0.9;

        /** @var Mage_Catalog_Model_Resource_Product_Collection $collection */
        $collection = Mage::getResourceModel('catalog/product_collection');
        $collection
            ->addAttributeToFilter('status',
                Mage_Catalog_Model_Product_Status::STATUS_ENABLED)
            ->addAttributeToFilter('visibility', ['in' => [
                Mage_Catalog_Model_Product_Visibility::VISIBILITY_IN_CATALOG,
                Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH,
            ]])
            ->addAttributeToFilter('entity_id', ['neq' => $product->getId()])
            ->addPriceData();

        $collection->getSelect()
            ->where('price_index.final_price <= ?', $maxPrice)
            ->limit($limit)
            ->order('price_index.final_price DESC');

        return array_map('intval', $collection->getAllIds());
    }

    /**
     * @param  int[] $excludeIds  IDs to exclude beyond the source product
     */
    private function byRandom(
        Mage_Catalog_Model_Product $product,
        int $limit,
        array $excludeIds = []
    ): array {
        $excludeIds[] = (int) $product->getId();

        /** @var Mage_Catalog_Model_Resource_Product_Collection $collection */
        $collection = Mage::getResourceModel('catalog/product_collection');
        $collection
            ->addAttributeToFilter('status',
                Mage_Catalog_Model_Product_Status::STATUS_ENABLED)
            ->addAttributeToFilter('visibility', ['in' => [
                Mage_Catalog_Model_Product_Visibility::VISIBILITY_IN_CATALOG,
                Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH,
            ]])
            ->addAttributeToFilter('entity_id', ['nin' => array_unique($excludeIds)])
            ->setPageSize($limit)
            ->setOrder('RAND()', '');

        return array_map('intval', $collection->getAllIds());
    }
}
