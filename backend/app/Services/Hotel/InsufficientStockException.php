<?php

namespace App\Services\Hotel;

/**
 * Thrown mid-transaction when an order needs more raw material than is in
 * stock — caught by OrderService, which marks the affected product SOLD OUT
 * outside the (now rolled-back) transaction and re-throws as a client-facing
 * 422. `menuItemId` or `addOnId` identifies which product ran out.
 */
class InsufficientStockException extends \RuntimeException
{
    public function __construct(
        public readonly ?int $menuItemId,
        string $message,
        public readonly ?int $addOnId = null,
    ) {
        parent::__construct($message);
    }
}
