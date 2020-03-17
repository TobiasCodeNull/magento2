<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Sales\Model\Reorder;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\QuoteGraphQl\Model\Cart\CreateEmptyCartForCustomer;
use Magento\Sales\Helper\Reorder as ReorderHelper;
use Magento\Sales\Model\Order\Item;
use Magento\Sales\Model\OrderFactory;

/**
 * Allows customer to quickly reorder previously added products and put them to the Cart
 */
class Reorder
{

    /**#@+
     * Error message codes
     */
    private const ERROR_PRODUCT_NOT_FOUND = 'PRODUCT_NOT_FOUND';
    private const ERROR_INSUFFICIENT_STOCK = 'INSUFFICIENT_STOCK';
    private const ERROR_NOT_SALABLE = 'NOT_SALABLE';
    private const ERROR_REORDER_NOT_AVAILABLE = 'REORDER_NOT_AVAILABLE';
    private const ERROR_UNDEFINED = 'UNDEFINED';
    /**#@-*/

    /**
     * @var OrderFactory
     */
    private $orderFactory;

    /**
     * @var CartManagementInterface
     */
    private $cartManagement;

    /**
     * @var ReorderHelper
     */
    private $reorderHelper;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @var CreateEmptyCartForCustomer
     */
    private $createEmptyCartForCustomer;

    /**
     * @var CartRepositoryInterface
     */
    private $cartRepository;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var Data\Error[]
     */
    private $errors = [];

    /**
     * @param OrderFactory $orderFactory
     * @param CartManagementInterface $cartManagement
     * @param ReorderHelper $reorderHelper
     * @param \Psr\Log\LoggerInterface $logger
     * @param CreateEmptyCartForCustomer $createEmptyCartForCustomer
     * @param CartRepositoryInterface $cartRepository
     * @param ProductRepositoryInterface $productRepository
     */
    public function __construct(
        OrderFactory $orderFactory,
        CartManagementInterface $cartManagement,
        CreateEmptyCartForCustomer $createEmptyCartForCustomer,
        CartRepositoryInterface $cartRepository,
        ProductRepositoryInterface $productRepository,
        ReorderHelper $reorderHelper,
        \Psr\Log\LoggerInterface $logger
    ) {
        $this->orderFactory = $orderFactory;
        $this->cartManagement = $cartManagement;
        $this->createEmptyCartForCustomer = $createEmptyCartForCustomer;
        $this->cartRepository = $cartRepository;
        $this->productRepository = $productRepository;
        $this->reorderHelper = $reorderHelper;
        $this->logger = $logger;
    }

    /**
     * Allows customer to quickly reorder previously added products and put them to the Cart
     *
     * @param string $orderNumber
     * @param string $storeId
     * @return Data\ReorderOutput
     * @throws InputException Order is not found
     * @throws NoSuchEntityException The specified customer does not exist.
     */
    public function execute(string $orderNumber, string $storeId): Data\ReorderOutput
    {
        $order = $this->orderFactory->create()->loadByIncrementIdAndStoreId($orderNumber, $storeId);

        if (!$order->getId()) {
            throw new InputException(
                __('Cannot find order number "%1" in store "%2"', $orderNumber, $storeId)
            );
        }
        $customerId = $order->getCustomerId();
        $this->errors = [];

        try {
            /** @var \Magento\Quote\Model\Quote $cart */
            $cart = $this->cartManagement->getCartForCustomer($customerId);
        } catch (NoSuchEntityException $e) {
            $this->createEmptyCartForCustomer->execute($customerId);
            $cart = $this->cartManagement->getCartForCustomer($customerId);
        }
        if (!$this->reorderHelper->canReorder($order->getId())) {
            $this->addError(__('Reorder is not available.'), self::ERROR_REORDER_NOT_AVAILABLE);
            return $this->prepareOutput($cart);
        }

        $items = $order->getItemsCollection();
        foreach ($items as $item) {
            try {
                $this->addOrderItem($cart, $item);
            } catch (\Magento\Framework\Exception\LocalizedException $e) {
                $this->addError($e->getMessage());
            } catch (\Throwable $e) {
                $this->logger->critical($e);
                $this->addError(
                    __('We can\'t add this item to your shopping cart right now.'),
                    self::ERROR_UNDEFINED
                );
            }
        }

        try {
            $this->cartRepository->save($cart);
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->addError($e->getMessage());
        }

        return $this->prepareOutput($cart);
    }

    /**
     * Convert order item to quote item
     *
     * @param \Magento\Quote\Model\Quote $cart
     * @param Item $orderItem
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function addOrderItem(\Magento\Quote\Model\Quote $cart, $orderItem): void
    {
        /* @var $orderItem Item */
        if ($orderItem->getParentItem() === null) {
            $info = $orderItem->getProductOptionByCode('info_buyRequest');
            $info = new \Magento\Framework\DataObject($info);
            $info->setQty($orderItem->getQtyOrdered());

            try {
                $product = $this->productRepository->getById($orderItem->getProductId(), false, null, true);
            } catch (NoSuchEntityException $e) {
                $this->addError(
                    __('Could not find a product with ID "%1"', $orderItem->getProductId()),
                    self::ERROR_PRODUCT_NOT_FOUND
                );
                return;
            }
            $cart->addProduct($product, $info);
        }
    }

    /**
     * Add order line item error
     *
     * @param string $message
     * @param string|null $code
     * @return void
     */
    private function addError($message, string $code = null): void
    {
        $this->errors[] = new Data\Error(
            $message,
            $code ?? $this->getErrorCode((string)$message)
        );
    }

    /**
     * Get message error code. Ad-hoc solution based on message parsing.
     *
     * @param string $message
     * @return string
     */
    private function getErrorCode(string $message): string
    {
        $code = self::ERROR_UNDEFINED;
        switch ($message) {
            case false !== strpos($message, 'Product that you are trying to add is not available.'):
                $code = self::ERROR_NOT_SALABLE;
                break;
            case false !== strpos($message, 'The fewest you may purchase is'):
            case false !== strpos($message, 'The most you may purchase is'):
            case false !== strpos($message, 'The requested qty is not available'):
                $code = self::ERROR_INSUFFICIENT_STOCK;
                break;
        }

        return $code;
    }

    /**
     * Prepare output
     *
     * @param CartInterface $cart
     * @return Data\ReorderOutput
     */
    protected function prepareOutput(CartInterface $cart): Data\ReorderOutput
    {
        $output = new Data\ReorderOutput($cart, $this->errors);
        $this->errors = [];
        return $output;
    }
}
