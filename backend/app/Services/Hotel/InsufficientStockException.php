<?php

namespace App\Services\Hotel;

/**
 * Thrown mid-transaction when an order needs more raw material than is in
 * stock — caught by OrderService, which marks the item SOLD OUT outside the
 * (now rolled-back) transaction and re-throws as a client-facing 422.
 */
class InsufficientStockException extends \RuntimeException
{
    public function __construct(public readonly int $menuItemId, string $message)
    {
        parent::__construct($message);
    }
}
