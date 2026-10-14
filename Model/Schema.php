<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Seo\Model;

/**
 * schema.org objects as arrays for JSON-LD, from plain values (the view
 * model gathers them from the page): a product with its offer(s), a breadcrumb
 * trail, the store and its search.
 */
class Schema
{
    /**
     * @param array{name: string, url: string, sku: string, description: string, images: string[],
     *     brand: string, currency: string, prices: float[], in_stock: bool, rating?: float|null,
     *     reviews?: int} $product prices: one for a simple product, each variant's for a configurable one
     * @return array<string, mixed>
     */
    public static function product(array $product): array
    {
        $availability = 'https://schema.org/' . ($product['in_stock'] ? 'InStock' : 'OutOfStock');
        $prices = array_values(array_filter($product['prices'], static fn ($price) => $price > 0));
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product['name'],
            'url' => $product['url'],
            'sku' => $product['sku'],
        ];
        if ($product['description'] !== '') {
            $schema['description'] = $product['description'];
        }
        if ($product['images']) {
            $schema['image'] = array_values($product['images']);
        }
        if ($product['brand'] !== '') {
            $schema['brand'] = ['@type' => 'Brand', 'name' => $product['brand']];
        }
        if (count(array_unique($prices)) > 1) {
            $schema['offers'] = [
                '@type' => 'AggregateOffer',
                'lowPrice' => self::amount(min($prices)),
                'highPrice' => self::amount(max($prices)),
                'offerCount' => count($prices),
                'priceCurrency' => $product['currency'],
                'availability' => $availability,
            ];
        } elseif ($prices) {
            $schema['offers'] = [
                '@type' => 'Offer',
                'url' => $product['url'],
                'price' => self::amount($prices[0]),
                'priceCurrency' => $product['currency'],
                'availability' => $availability,
                'itemCondition' => 'https://schema.org/NewCondition',
            ];
        }
        if (($product['reviews'] ?? 0) > 0 && ($product['rating'] ?? 0) > 0) {
            $schema['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => round((float) $product['rating'], 1),
                'bestRating' => 5,
                'reviewCount' => (int) $product['reviews'],
            ];
        }

        return $schema;
    }

    /**
     * @param array<int, array{name: string, url: string}> $crumbs From the home page down; the last may have no URL
     * @return array<string, mixed>|null
     */
    public static function breadcrumbs(array $crumbs): ?array
    {
        if (count($crumbs) < 2) {
            return null;
        }
        $items = [];
        foreach (array_values($crumbs) as $i => $crumb) {
            $item = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $crumb['name']];
            if ($crumb['url'] !== '') {
                $item['item'] = $crumb['url'];
            }
            $items[] = $item;
        }

        return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }

    /**
     * The store (Organization) and its site search (WebSite with a SearchAction).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function store(string $name, string $url, string $logo, string $searchUrl): array
    {
        $organization = ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => $name, 'url' => $url];
        if ($logo !== '') {
            $organization['logo'] = $logo;
        }

        return [
            $organization,
            [
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                'name' => $name,
                'url' => $url,
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    'target' => ['@type' => 'EntryPoint', 'urlTemplate' => $searchUrl . '?q={search_term_string}'],
                    'query-input' => 'required name=search_term_string',
                ],
            ],
        ];
    }

    /**
     * Plain text from HTML, whitespace collapsed, cut at a word before $length.
     */
    public static function text(string $html, int $length = 0): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(
            (string) preg_replace('/<(br|\/p|\/li|\/h[1-6]|\/tr)\b[^>]*>/i', ' ', $html)
        ), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($length <= 0 || mb_strlen($text) <= $length) {
            return $text;
        }
        $cut = mb_substr($text, 0, $length - 1);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space > $length / 2 ? mb_substr($cut, 0, $space) : $cut, " ,.;:–—-") . '…';
    }

    /**
     * "3490" or "1290.50": a dot, two decimals when there are cents.
     */
    public static function amount(float $amount): string
    {
        return abs($amount - round($amount)) < 0.005 ? (string) (int) round($amount) : number_format($amount, 2, '.', '');
    }
}
