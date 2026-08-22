<?php
namespace App\Enums;
/** Defines the WalletTransactionType enum and its project responsibilities. */
enum WalletTransactionType: string
{
    case CoinPurchase = 'coin_purchase';
    case Transfer = 'transfer';
    case Gift = 'gift';
    case GiftLevelReward = 'gift_level_reward';
    case DailyCheckin = 'daily_checkin';
    case StreakBonus = 'streak_bonus';
    case AffiliateCommission = 'affiliate_commission';
    case CheckoutRedemption = 'checkout_redemption';
    case GameEntry = 'game_entry';
    case Refund = 'refund';
    case Reversal = 'reversal';
    case Expiration = 'expiration';
    case GameReward = 'game_reward';
    case AdminAdjustment = 'admin_adjustment';
}
