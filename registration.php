<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 *
 * Zero_Seo: what search engines read beyond the page itself: structured data
 * (schema.org JSON-LD: products with their offers, breadcrumbs, the store and
 * its search), hreflang links between a store's languages, canonical URLs
 * for products and categories, and a meta description where a page has none.
 */
use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(ComponentRegistrar::MODULE, 'Zero_Seo', __DIR__);
