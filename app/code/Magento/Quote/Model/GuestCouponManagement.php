<?php
/**
 *
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Quote\Model;

use Magento\Quote\Api\GuestCouponManagementInterface;
use Magento\Quote\Model\QuoteIdMask;
use Magento\Quote\Model\QuoteIdMaskFactory;

/**
 * Coupon management object.
 */
class GuestCouponManagement extends CouponManagement implements GuestCouponManagementInterface
{
    /**
     * Constructs a coupon read service object.
     *
     * @param \Magento\Quote\Model\QuoteRepository $quoteRepository Quote repository.
     */
    public function __construct(
        \Magento\Quote\Model\QuoteRepository $quoteRepository,
        QuoteIdMaskFactory $quoteIdMaskFactory
    ) {
        $this->quoteIdMaskFactory = $quoteIdMaskFactory;
        parent::__construct(
            $quoteRepository
        );
    }

    /**
     * {@inheritdoc}
     */
    public function get($cartId)
    {
        /** @var $quoteIdMask QuoteIdMask */
        $quoteIdMask = $this->quoteIdMaskFactory->create()->load($cartId, 'masked_id');
        return parent::get($quotedIdMask->getId());
    }

    /**
     * {@inheritdoc}
     */
    public function set($cartId, $couponCode)
    {
        /** @var $quoteIdMask QuoteIdMask */
        $quoteIdMask = $this->quoteIdMaskFactory->create()->load($cartId, 'masked_id');
        return parent::get($quotedIdMask->getId(), $couponCode);
    }

    /**
     * {@inheritdoc}
     */
    public function remove($cartId)
    {
        /** @var $quoteIdMask QuoteIdMask */
        $quoteIdMask = $this->quoteIdMaskFactory->create()->load($cartId, 'masked_id');
        return parent::remove($quoteIdMask->getId());
    }
}
