<?php

return [
    'roles' => [
        'customer' => [
            'account.access','checkout.use','wallet.use','affiliate.use','games.enter','reviews.create','returns.create',
        ],
        'seller_staff' => [
            'account.access',
        ],
        'seller' => [
            'account.access','checkout.use','wallet.use','affiliate.use','games.enter','reviews.create','returns.create',
            'seller.overview.view','seller.orders.view','seller.orders.manage','seller.shipping.view','seller.shipping.manage',
            'seller.returns.view','seller.returns.manage','seller.catalog.view','seller.catalog.manage','seller.promotions.view','seller.promotions.manage',
            'seller.reviews.view','seller.reviews.reply','seller.finance.view','seller.payouts.view','seller.payouts.manage','seller.analytics.view',
            'seller.tax.view','seller.tax.manage','seller.settings.view','seller.settings.manage',
        ],
        'support' => [
            'admin.overview.view','orders.view','shipping.view','reviews.view',
        ],
        'finance' => [
            'admin.overview.view','orders.view','payments.view','payments.manage','finance.view','finance.manage','tax.view','tax.manage','analytics.view','analytics.manage',
            'acceptance.view','acceptance.sign',
        ],
        'moderator' => [
            'admin.overview.view','reviews.view','reviews.moderate','compliance.view','compliance.review',
        ],
        'admin' => [
            'admin.overview.view','users.view','users.manage','vendors.view','vendors.manage','catalog.view','catalog.manage','orders.view','orders.manage',
            'shipping.view','shipping.manage','payments.view','payments.manage','returns.view','returns.manage','finance.view','finance.manage',
            'promotions.view','promotions.manage','loyalty.view','loyalty.manage','games.view','games.manage','tax.view','tax.manage',
            'reviews.view','reviews.moderate','media.view','media.manage','compliance.view','compliance.review','security.events.view','audit.view',
            'risk.view','risk.manage','analytics.view','analytics.manage','notifications.view','notifications.manage','settings.view','settings.manage',
            'operations.view','operations.manage','acceptance.view','acceptance.manage','acceptance.sign',
        ],
        'super_admin' => ['*'],
    ],
];
