<?php
namespace App\Domain\Finance\Services;
use App\Enums\UserRole;
use App\Models\User;
use App\Models\Vendor;
/** Defines the VendorResolver class and its project responsibilities. */
class VendorResolver
{
    /** Handles for user for the vendor resolver workflow. */
    public function forUser(User $user):Vendor
    {
        $role=$user->role instanceof UserRole?$user->role->value:(string)$user->role;
        abort_unless(in_array($role,[UserRole::Seller->value,UserRole::Admin->value,UserRole::SuperAdmin->value],true),403);
        $vendor=Vendor::query()->where('owner_user_id',$user->id)->first();abort_unless($vendor,403,'No vendor account is linked to this user.');return $vendor;
    }
}
