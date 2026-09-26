<?php

namespace Nistruct\ContentAI\Test\Integration;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product\Action as ProductAction;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Nistruct\ContentAI\Model\Eav\ProductAttributeValueResolver;
use Nistruct\ContentAI\Model\Media\ImageLabelService;
use PHPUnit\Framework\TestCase;

class ScopePersistenceTest extends TestCase
{
    /**
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     * @magentoDataFixture Magento/Store/_files/store.php
     * @magentoDbIsolation enabled
     */
    public function testInheritedAndLocalProductValues(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $product = $objectManager->get(ProductRepositoryInterface::class)->get('simple');
        $storeId = (int)$objectManager->get(StoreManagerInterface::class)->getStore('test')->getId();
        $resolver = $objectManager->get(ProductAttributeValueResolver::class);

        $inherited = $resolver->resolve((int)$product->getId(), 'description', $storeId);
        self::assertNull($inherited['store_value']);
        self::assertSame('Description with <b>html tag</b>', $inherited['effective_value']);
        self::assertTrue($inherited['is_inherited']);
        self::assertTrue($resolver->isMissingOwnValue((int)$product->getId(), 'description', $storeId));

        $objectManager->get(ProductAction::class)->updateAttributes(
            [(int)$product->getId()],
            ['description' => 'Localized description'],
            $storeId
        );
        $local = $resolver->resolve((int)$product->getId(), 'description', $storeId);
        self::assertSame('Localized description', $local['store_value']);
        self::assertFalse($local['is_inherited']);
        self::assertFalse($resolver->isMissingOwnValue((int)$product->getId(), 'description', $storeId));
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     * @magentoDataFixture Magento/Store/_files/store.php
     * @magentoDbIsolation enabled
     */
    public function testWebsiteScopedValueIsNotMissingFromSecondaryStore(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $repository = $objectManager->get(ProductRepositoryInterface::class);
        $product = $repository->get('simple');
        $storeManager = $objectManager->get(StoreManagerInterface::class);
        $secondaryStoreId = (int)$storeManager->getStore('test')->getId();
        $websiteDefaultStoreId = (int)$storeManager->getStore($secondaryStoreId)
            ->getWebsite()->getDefaultStore()->getId();

        $objectManager->get(ProductAction::class)->updateAttributes(
            [(int)$product->getId()],
            ['price' => '42.50'],
            $websiteDefaultStoreId
        );

        $resolver = $objectManager->get(ProductAttributeValueResolver::class);
        $values = $resolver->resolve((int)$product->getId(), 'price', $secondaryStoreId);
        self::assertSame('42.500000', (string)$values['store_value']);
        self::assertFalse($resolver->isMissingOwnValue((int)$product->getId(), 'price', $secondaryStoreId));
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/product_with_media_gallery.php
     * @magentoDataFixture Magento/Store/_files/store.php
     * @magentoDbIsolation enabled
     */
    public function testImageLabelUsesTargetStoreAndRoleImage(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $repository = $objectManager->get(ProductRepositoryInterface::class);
        $product = $repository->get('simple_product_with_media');
        $storeId = (int)$objectManager->get(StoreManagerInterface::class)->getStore('test')->getId();
        $roleFile = (string)$product->getMediaGalleryImages()->getFirstItem()->getFile();
        $objectManager->get(ProductAction::class)->updateAttributes(
            [(int)$product->getId()],
            ['image' => 'no_selection'],
            0
        );
        $objectManager->get(ProductAction::class)->updateAttributes(
            [(int)$product->getId()],
            ['image' => $roleFile],
            $storeId
        );
        self::assertSame(
            $roleFile,
            (string)$repository->getById((int)$product->getId(), false, $storeId, true)->getImage()
        );

        $labels = $objectManager->get(ImageLabelService::class);
        self::assertTrue($labels->apply((int)$product->getId(), 'image_label', 'Localized image label', $storeId));
        self::assertSame(
            'Localized image label',
            $labels->getOwnValue((int)$product->getId(), 'image_label', $storeId)
        );
    }
}
