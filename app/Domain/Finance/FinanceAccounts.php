<?php
namespace App\Domain\Finance;
/** Defines the FinanceAccounts class and its project responsibilities. */
final class FinanceAccounts
{
    public const PAYMENT_CLEARING='asset.payment_clearing';
    public const COD_RECEIVABLE='asset.cod_receivable';
    public const COIN_LIABILITY='liability.vsn_coins';
    public const SELLER_PAYABLE='liability.seller_payable';
    public const SELLER_RECOVERY='asset.seller_recovery';
    public const PLATFORM_COMMISSION='revenue.platform_commission';
    public const SALES_TAX_PAYABLE='liability.sales_tax_payable';
    public const COUPON_SUBSIDY='expense.review_coupon_subsidy';
}
