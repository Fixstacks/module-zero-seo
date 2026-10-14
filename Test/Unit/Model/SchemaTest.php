<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Seo\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Zero\Seo\Model\Schema;

/**
 * schema.org objects for JSON-LD from the page's values.
 */
class SchemaTest extends TestCase
{
    public function testProductWithOnePrice(): void
    {
        $schema = Schema::product($this->product(['prices' => [3490.0, 3490.0]]));

        $this->assertSame('Product', $schema['@type']);
        $this->assertSame('Offer', $schema['offers']['@type']);
        $this->assertSame('3490', $schema['offers']['price']);
        $this->assertSame('UAH', $schema['offers']['priceCurrency']);
        $this->assertSame('https://schema.org/InStock', $schema['offers']['availability']);
        $this->assertSame(['@type' => 'Brand', 'name' => 'Kids Карамель'], $schema['brand']);
        $this->assertArrayNotHasKey('aggregateRating', $schema);
    }

    public function testVariantsAtDifferentPricesAreARange(): void
    {
        $schema = Schema::product($this->product(['prices' => [890.0, 990.5, 890.0], 'in_stock' => false]));

        $this->assertSame('AggregateOffer', $schema['offers']['@type']);
        $this->assertSame('890', $schema['offers']['lowPrice']);
        $this->assertSame('990.50', $schema['offers']['highPrice']);
        $this->assertSame(3, $schema['offers']['offerCount']);
        $this->assertSame('https://schema.org/OutOfStock', $schema['offers']['availability']);
    }

    public function testRatingOnlyWithReviews(): void
    {
        $rated = Schema::product($this->product(['rating' => 4.33, 'reviews' => 3]));
        $unrated = Schema::product($this->product(['rating' => 4.0, 'reviews' => 0]));

        $this->assertSame(['@type' => 'AggregateRating', 'ratingValue' => 4.3, 'bestRating' => 5, 'reviewCount' => 3], $rated['aggregateRating']);
        $this->assertArrayNotHasKey('aggregateRating', $unrated);
    }

    public function testBreadcrumbs(): void
    {
        $trail = Schema::breadcrumbs([
            ['name' => 'Kids Карамель', 'url' => 'https://shop.test/kids/'],
            ['name' => 'Одяг', 'url' => 'https://shop.test/kids/odiah.html'],
            ['name' => 'Комбінезон', 'url' => ''],
        ]);

        $this->assertSame(3, $trail['itemListElement'][2]['position']);
        $this->assertArrayNotHasKey('item', $trail['itemListElement'][2]);
        $this->assertSame('https://shop.test/kids/odiah.html', $trail['itemListElement'][1]['item']);
        $this->assertNull(Schema::breadcrumbs([['name' => 'Home', 'url' => 'https://shop.test/']]));
    }

    public function testStoreAndItsSearch(): void
    {
        [$organization, $site] = Schema::store('Zero', 'https://shop.test/', '', 'https://shop.test/catalogsearch/result/');

        $this->assertSame('Organization', $organization['@type']);
        $this->assertArrayNotHasKey('logo', $organization);
        $this->assertSame(
            'https://shop.test/catalogsearch/result/?q={search_term_string}',
            $site['potentialAction']['target']['urlTemplate']
        );
    }

    public function testTextFromHtml(): void
    {
        $this->assertSame('Тепло &amp; затишно. Друга фраза', Schema::text('<p>Тепло &amp;amp; затишно.</p><p>Друга&nbsp;фраза</p>'));
        $this->assertSame('Один два…', Schema::text('Один два три чотири', 12));
    }

    private function product(array $values): array
    {
        return $values + [
            'name' => 'Комбінезон «Сніжок»', 'url' => 'https://shop.test/kids/kombinezon.html', 'sku' => 'KK-KB-01',
            'description' => 'Теплий зимовий комбінезон.', 'images' => ['https://shop.test/a.jpg'],
            'brand' => 'Kids Карамель', 'currency' => 'UAH', 'prices' => [3490.0], 'in_stock' => true,
        ];
    }
}
