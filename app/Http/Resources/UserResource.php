<?php

namespace App\Http\Resources;

use App\Enums\UserRole;
use App\Models\User;
use App\Security\Rbac;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Defines the UserResource class and its project responsibilities.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /** Handles to array for the user resource workflow. */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role instanceof UserRole ? $this->role->value : $this->role,
            'permissions' => Rbac::permissionsForRole($this->role),
            'emailVerified' => $this->email_verified_at !== null,
            'profile' => $this->whenLoaded('profile', fn () => [
                'phone' => $this->profile?->phone,
                'phoneVerified' => $this->profile?->phone_verified_at !== null,
                'avatar' => $this->profile?->avatar_path,
                'dateOfBirth' => $this->profile?->date_of_birth?->toDateString(),
                'locale' => $this->profile?->locale,
                'timezone' => $this->profile?->timezone,
            ]),
            'verification' => [
                'phoneVerified' => $this->profile?->phone_verified_at !== null,
                'governmentIdVerified' => $this->kycVerifications()
                    ->where('type', 'government_id')
                    ->where('status', 'approved')
                    ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->exists(),
                'addressProofVerified' => $this->kycVerifications()
                    ->where('type', 'address_proof')
                    ->where('status', 'approved')
                    ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->exists(),
            ],
        ];
    }
}
