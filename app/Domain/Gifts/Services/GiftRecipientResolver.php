<?php

namespace App\Domain\Gifts\Services;

use App\Domain\Gifts\Exceptions\GiftException;
use App\Models\User;

/** Defines the GiftRecipientResolver class and its project responsibilities. */
class GiftRecipientResolver
{
    /** Handles resolve for the gift recipient resolver workflow. */
    public function resolve(User $sender, string $identifier): User
    {
        $value = trim($identifier);
        if ($value === '') throw new GiftException('Enter a recipient email or verified phone number.', 'recipient');

        $recipient = null;
        if (str_contains($value, '@')) {
            $recipient = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($value)])->first();
        } else {
            $recipient = User::query()->whereHas('profile', /** Inline callback for this operation. */ fn ($query) => $query->where('phone', $value)->whereNotNull('phone_verified_at'))->first();
            if (! $recipient) {
                $matches = User::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($value)])->limit(2)->get();
                if ($matches->count() === 1) $recipient = $matches->first();
            }
        }

        if (! $recipient) throw new GiftException('Recipient is unavailable for product gifting.', 'recipient');
        if ($recipient->id === $sender->id) throw new GiftException('You cannot send a product gift to yourself.', 'recipient');
        return $recipient;
    }
}
