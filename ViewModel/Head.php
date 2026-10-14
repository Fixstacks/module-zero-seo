<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Seo\ViewModel;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Review\Model\ReviewFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Zero\Seo\Model\Schema;

/**
 * <head> JSON-LD and hreflang: Product (AggregateOffer with variants) and
 * BreadcrumbList on products, BreadcrumbList on categories, Organization and
 * WebSite on home; hreflang (and x-default) where store views are languages
 * at URLs of their own (/ua/, /ua/en/).
 */
class Head implements ArgumentInterface
{
    public function __construct(
        private readonly Http $request,
        private readonly Registry $registry,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly ReviewFactory $reviewFactory
    ) {
    }

    /**
     * @return string[] JSON documents for <script type="application/ld+json">
     */
    public function getStructuredData(): array
    {
        if (!$this->isOn('structured_data')) {
            return [];
        }
        $schemas = match ($this->request->getFullActionName()) {
            'catalog_product_view' => $this->productPage(),
            'catalog_category_view' => $this->categoryPage(),
            'cms_index_index' => $this->homePage(),
            default => [],
        };

        return array_map(
            static fn (array $schema) => (string) json_encode(
                $schema,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
            ),
            array_values(array_filter($schemas))
        );
    }

    /**
     * @return array<int, array{hreflang: string, href: string}>
     */
    public function getAlternates(): array
    {
        if (!$this->isOn('hreflang') || $this->request->getFullActionName() === 'cms_noroute_index') {
            return [];
        }
        /** @var Store $current */
        $current = $this->storeManager->getStore();
        $group = $this->storeManager->getGroup($current->getStoreGroupId());
        $stores = array_filter($group->getStores(), static fn (Store $store) => $store->isActive());
        if (count($stores) < 2) {
            return [];
        }
        $links = [];
        $default = null;
        foreach ($stores as $store) {
            $href = strtok((string) $store->getCurrentUrl(false), '?');
            $language = strtolower(str_replace('_', '-', (string) $this->scopeConfig->getValue(
                'general/locale/code',
                ScopeInterface::SCOPE_STORE,
                $store->getId()
            )));
            $links[] = ['hreflang' => $language, 'href' => $href];
            if ((int) $store->getId() === (int) $group->getDefaultStoreId()) {
                $default = $href;
            }
        }
        // Only languages at URLs of their own: store views switched by cookie (one URL
        // for all) or two views in one language (a currency) would tell search engines nothing.
        if (count(array_unique(array_column($links, 'href'))) < count($links)
            || count(array_unique(array_column($links, 'hreflang'))) < count($links)
        ) {
            return [];
        }
        if ($default !== null) {
            $links[] = ['hreflang' => 'x-default', 'href' => $default];
        }

        return $links;
    }

    /**
     * @return array<int, array<string, mixed>|null>
     */
    private function productPage(): array
    {
        $product = $this->registry->registry('current_product');
        if (!$product instanceof Product) {
            return [];
        }
        /** @var Store $store */
        $store = $this->storeManager->getStore();
        $prices = [];
        $inStock = $product->isSaleable();
        if ($product->getTypeId() === Configurable::TYPE_CODE) {
            /** @var Configurable $type */
            $type = $product->getTypeInstance();
            foreach ($type->getUsedProducts($product) as $child) {
                if ($child->isSaleable()) {
                    $prices[] = (float) $child->getPriceInfo()->getPrice('final_price')->getAmount()->getValue();
                }
            }
        }
        if (!$prices) {
            $prices[] = (float) $product->getPriceInfo()->getPrice('final_price')->getAmount()->getValue();
        }
        // Price on Request (Zero_QuoteRequest): no price on the page, none in the data.
        if ($product->getData('zero_quote_only')) {
            $prices = [];
        }
        $this->reviewFactory->create()->getEntitySummary($product, (int) $store->getId());
        $summary = $product->getRatingSummary();
        $image = (string) $product->getImage();
        $mediaUrl = rtrim((string) $store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA), '/') . '/catalog/product';

        return [
            Schema::product([
                'name' => trim((string) $product->getName()),
                'url' => (string) $product->getProductUrl(),
                'sku' => (string) $product->getSku(),
                'description' => Schema::text(
                    (string) ($product->getShortDescription() ?: $product->getDescription()),
                    5000
                ),
                'images' => $image !== '' && $image !== 'no_selection' ? [$mediaUrl . '/' . ltrim($image, '/')] : [],
                'brand' => trim((string) $product->getAttributeText('manufacturer')) ?: $this->storeName($store),
                'currency' => (string) $store->getCurrentCurrencyCode(),
                'prices' => $prices,
                'in_stock' => (bool) $inStock,
                'rating' => $summary ? (float) $summary->getRatingSummary() / 20 : null,
                'reviews' => $summary ? (int) $summary->getReviewsCount() : 0,
            ]),
            Schema::breadcrumbs(array_merge(
                $this->trail($this->categoryOf($product, $store), $store, true),
                [['name' => trim((string) $product->getName()), 'url' => '']]
            )),
        ];
    }

    /**
     * @return array<int, array<string, mixed>|null>
     */
    private function categoryPage(): array
    {
        $category = $this->registry->registry('current_category');
        if (!$category instanceof Category) {
            return [];
        }

        return [Schema::breadcrumbs($this->trail($category, $this->storeManager->getStore(), false))];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function homePage(): array
    {
        /** @var Store $store */
        $store = $this->storeManager->getStore();
        $logo = (string) $this->scopeConfig->getValue('design/header/logo_src', ScopeInterface::SCOPE_STORE);

        return Schema::store(
            $this->storeName($store),
            (string) $store->getBaseUrl(),
            $logo !== ''
                ? rtrim((string) $store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA), '/') . '/logo/' . $logo
                : '',
            (string) $store->getUrl('catalogsearch/result')
        );
    }

    /**
     * Home, then the category's ancestors under the store's root, then the
     * category itself (linked when it isn't the page itself).
     *
     * @return array<int, array{name: string, url: string}>
     */
    private function trail(?Category $category, Store $store, bool $linkLast): array
    {
        $crumbs = [['name' => $this->storeName($store), 'url' => (string) $store->getBaseUrl()]];
        if ($category === null) {
            return $crumbs;
        }
        $rootId = (int) $store->getRootCategoryId();
        $ids = array_map('intval', explode('/', (string) $category->getPath()));
        $ids = array_slice($ids, (int) array_search($rootId, $ids, true) + 1);
        if (!$ids) {
            return $crumbs;
        }
        $categories = $this->categoryCollectionFactory->create()
            ->setStoreId((int) $store->getId())
            ->addAttributeToSelect('name')
            ->addIdFilter($ids)
            ->addUrlRewriteToResult();
        $byId = [];
        foreach ($categories as $item) {
            $byId[(int) $item->getId()] = $item;
        }
        foreach ($ids as $i => $id) {
            if (!isset($byId[$id])) {
                continue;
            }
            $last = $i === count($ids) - 1;
            $crumbs[] = [
                'name' => (string) $byId[$id]->getName(),
                'url' => !$last || $linkLast ? (string) $byId[$id]->getUrl() : '',
            ];
        }

        return $crumbs;
    }

    /**
     * The category the product was reached from, or its deepest one in this store.
     */
    private function categoryOf(Product $product, Store $store): ?Category
    {
        $current = $this->registry->registry('current_category');
        if ($current instanceof Category) {
            return $current;
        }
        $ids = array_map('intval', (array) $product->getCategoryIds());
        if (!$ids) {
            return null;
        }
        $category = $this->categoryCollectionFactory->create()
            ->setStoreId((int) $store->getId())
            ->addIdFilter($ids)
            ->addAttributeToFilter('is_active', 1)
            ->addFieldToFilter('path', ['like' => '1/' . (int) $store->getRootCategoryId() . '/%'])
            ->setOrder('level', 'DESC')
            ->setPageSize(1)
            ->getFirstItem();

        return $category->getId() ? $category : null;
    }

    private function storeName(Store $store): string
    {
        return trim((string) $this->scopeConfig->getValue(
            'general/store_information/name',
            ScopeInterface::SCOPE_STORE,
            $store->getId()
        )) ?: (string) $store->getFrontendName();
    }

    private function isOn(string $field): bool
    {
        return $this->scopeConfig->isSetFlag('zero_seo/general/' . $field, ScopeInterface::SCOPE_STORE);
    }
}
