<?php

namespace App\Services\Hotel;

/**
 * Thrown mid-transaction when an order needs more raw material than is in
 * stock — caught by OrderService, which marks the affected menu item SOLD OUT
 * outside the (now rolled-back) transaction and re-throws as a client-facing
 * 422. Exactly one of `menuItemId`, `addOnId`, `productId` identifies which
 * line ran out — a product has no sold_out column to flip (availability is
 * derived from stock_qty), so its branch only reports the failure.
 */
class InsufficientStockException extends \RuntimeException
{
    public function __construct(
        public readonly ?int $menuItemId,
        string $message,
        public readonly ?int $addOnId = null,
        public readonly ?int $productId = null,
    ) {
        parent::__construct($message);
    }
}
