<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/** Defines the User class and its project responsibilities. */
class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** Handles casts for the user workflow. */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    /** Handles profile for the user workflow. */
    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    /** Handles addresses for the user workflow. */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    /** Handles social accounts for the user workflow. */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /** Handles carts for the user workflow. */
    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class);
    }

    /** Handles checkout sessions for the user workflow. */
    public function checkoutSessions(): HasMany
    {
        return $this->hasMany(CheckoutSession::class);
    }

    /** Handles orders for the user workflow. */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** Handles payment intents for the user workflow. */
    public function paymentIntents(): HasMany
    {
        return $this->hasMany(PaymentIntent::class);
    }

    /** Handles saved payment methods for the user workflow. */
    public function savedPaymentMethods(): HasMany
    {
        return $this->hasMany(SavedPaymentMethod::class);
    }

    /** Handles wallet for the user workflow. */
    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    /** Handles wallet entries for the user workflow. */
    public function walletEntries(): HasMany
    {
        return $this->hasMany(WalletEntry::class);
    }

    /** Handles coin purchases for the user workflow. */
    public function coinPurchases(): HasMany
    {
        return $this->hasMany(CoinPurchase::class);
    }

    /** Handles affiliate account for the user workflow. */
    public function affiliateAccount(): HasOne
    {
        return $this->hasOne(AffiliateAccount::class);
    }

    /** Handles affiliate parent relationship for the user workflow. */
    public function affiliateParentRelationship(): HasOne
    {
        return $this->hasOne(AffiliateRelationship::class);
    }

    /** Handles affiliate children for the user workflow. */
    public function affiliateChildren(): HasMany
    {
        return $this->hasMany(AffiliateRelationship::class, 'parent_user_id');
    }

    /** Handles affiliate commissions for the user workflow. */
    public function affiliateCommissions(): HasMany
    {
        return $this->hasMany(AffiliateCommission::class, 'beneficiary_id');
    }

    /** Handles game entries for the user workflow. */
    public function gameEntries(): HasMany
    {
        return $this->hasMany(GameEntry::class);
    }

    /** Handles game wins for the user workflow. */
    public function gameWins(): HasMany
    {
        return $this->hasMany(GameDraw::class, 'winner_user_id');
    }

    /** Handles sent gifts for the user workflow. */
    public function sentGifts(): HasMany
    {
        return $this->hasMany(Gift::class, 'sender_user_id');
    }

    /** Handles received gifts for the user workflow. */
    public function receivedGifts(): HasMany
    {
        return $this->hasMany(Gift::class, 'recipient_user_id');
    }

    /** Handles gift sender profile for the user workflow. */
    public function giftSenderProfile(): HasOne
    {
        return $this->hasOne(GiftSenderProfile::class);
    }

    /** Handles return requests for the user workflow. */
    public function returnRequests(): HasMany
    {
        return $this->hasMany(ReturnRequest::class);
    }

    /** Handles reviews for the user workflow. */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /** Handles review coupons for the user workflow. */
    public function reviewCoupons(): HasMany
    {
        return $this->hasMany(ReviewRewardCoupon::class);
    }

    /** Handles kyc verifications for the user workflow. */
    public function kycVerifications(): HasMany
    {
        return $this->hasMany(KycVerification::class);
    }

    /** Handles devices for the user workflow. */
    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    /** Handles security events for the user workflow. */
    public function securityEvents(): HasMany
    {
        return $this->hasMany(SecurityEvent::class);
    }

    /** Handles mobile api sessions for the user workflow. */
    public function mobileApiSessions(): HasMany
    {
        return $this->hasMany(MobileApiSession::class);
    }

    /** Handles product alerts for the user workflow. */
    public function productAlerts(): HasMany
    {
        return $this->hasMany(ProductAlert::class);
    }

    /** Handles wishlist items for the user workflow. */
    public function wishlistItems(): HasMany
    {
        return $this->hasMany(WishlistItem::class);
    }

    /** Handles product views for the user workflow. */
    public function productViews(): HasMany
    {
        return $this->hasMany(ProductView::class);
    }

    /** Handles risk profile for the user workflow. */
    public function riskProfile(): HasOne { return $this->hasOne(RiskProfile::class); }
    /** Handles risk events for the user workflow. */
    public function riskEvents(): HasMany { return $this->hasMany(RiskEvent::class); }
    /** Handles risk cases for the user workflow. */
    public function riskCases(): HasMany { return $this->hasMany(RiskCase::class); }
    /** Handles risk holds for the user workflow. */
    public function riskHolds(): HasMany { return $this->hasMany(RiskHold::class); }
}
