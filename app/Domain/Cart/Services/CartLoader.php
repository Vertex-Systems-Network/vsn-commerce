<?php

namespace App\Domain\Cart\Services;

use App\Models\Cart;

/** Defines the CartLoader class and its project responsibilities. */
class CartLoader
{
    /** Handles relations for the cart loader workflow. */
    public static function relations(): array
    {
        return [
            'items.product.vendor',
            'items.product.images',
            'items.variant.inventories',
        ];
    }

    /** Handles load for the cart loader workflow. */
    public function load(Cart $cart): Cart
    {
        return $cart->load(self::relations());
    }
}
