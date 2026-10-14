<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Seo\Plugin;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\Page\Config;
use Magento\Store\Model\ScopeInterface;
use Zero\Seo\Model\Schema;

/**
 * A product or category page without its own meta description gets the start
 * of its description (short description first, for a product), so search
 * engines don't make one up from the page.
 */
class MetaDescription
{
    private const LENGTH = 160;

    public function __construct(
        private readonly Registry $registry,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @see Config::getDescription()
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetDescription(Config $subject, $result)
    {
        if (trim((string) $result) !== ''
            || !$this->scopeConfig->isSetFlag('zero_seo/general/meta_description', ScopeInterface::SCOPE_STORE)
        ) {
            return $result;
        }
        $product = $this->registry->registry('current_product');
        if ($product instanceof Product) {
            $text = Schema::text((string) ($product->getShortDescription() ?: $product->getDescription()), self::LENGTH);
        } else {
            $category = $this->registry->registry('current_category');
            $text = $category instanceof Category ? Schema::text((string) $category->getDescription(), self::LENGTH) : '';
        }

        return $text !== '' ? $text : $result;
    }
}
