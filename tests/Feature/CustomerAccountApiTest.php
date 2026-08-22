<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Defines the CustomerAccountApiTest class and its project responsibilities. */
class CustomerAccountApiTest extends TestCase
{
    use RefreshDatabase;

    /** Verifies customer can update owned address and make it default. */
    public function test_customer_can_update_owned_address_and_make_it_default(): void
    {
        $user = User::create(['name'=>'Customer','email'=>'account-address@example.com','password'=>'StrongPass123','role'=>'customer']);
        $first = $user->addresses()->create([
            'label'=>'Home','recipient_name'=>'Customer','phone'=>'03000000000','line1'=>'Old home','city'=>'Lahore','country_code'=>'PK','is_default'=>true,
        ]);
        $second = $user->addresses()->create([
            'label'=>'Office','recipient_name'=>'Customer','phone'=>'03000000000','line1'=>'Old office','city'=>'Lahore','country_code'=>'PK','is_default'=>false,
        ]);

        $this->actingAs($user)->putJson('/api/v1/addresses/'.$second->id, [
            'label'=>'Office','recipient_name'=>'Customer Updated','phone'=>'03111111111','line1'=>'New office','line2'=>null,
            'city'=>'Karachi','state'=>'Sindh','postal_code'=>'74000','country_code'=>'PK','is_default'=>true,
        ])->assertOk()->assertJsonPath('data.is_default', true)->assertJsonPath('data.city', 'Karachi');

        $this->assertFalse((bool) $first->fresh()->is_default);
        $this->assertTrue((bool) $second->fresh()->is_default);
    }

    /** Verifies customer cannot update another users address. */
    public function test_customer_cannot_update_another_users_address(): void
    {
        $owner = User::create(['name'=>'Owner','email'=>'address-owner@example.com','password'=>'StrongPass123','role'=>'customer']);
        $attacker = User::create(['name'=>'Other','email'=>'address-other@example.com','password'=>'StrongPass123','role'=>'customer']);
        $address = $owner->addresses()->create([
            'label'=>'Home','recipient_name'=>'Owner','phone'=>'03000000000','line1'=>'Private','city'=>'Lahore','country_code'=>'PK','is_default'=>true,
        ]);

        $this->actingAs($attacker)->putJson('/api/v1/addresses/'.$address->id, [
            'label'=>'Home','recipient_name'=>'Changed','phone'=>'03000000000','line1'=>'Changed','city'=>'Lahore','country_code'=>'PK','is_default'=>true,
        ])->assertNotFound();
    }

    /** Verifies customer can change password with current password. */
    public function test_customer_can_change_password_with_current_password(): void
    {
        $user = User::create(['name'=>'Customer','email'=>'password-change@example.com','password'=>'StrongPass123','role'=>'customer']);

        $this->actingAs($user)->putJson('/api/v1/security/password', [
            'current_password'=>'StrongPass123',
            'password'=>'NewStrongPass456',
            'password_confirmation'=>'NewStrongPass456',
        ])->assertOk()->assertJsonPath('data.ok', true);

        $this->assertTrue(Hash::check('NewStrongPass456', $user->fresh()->password));
    }

    /** Verifies wrong current password cannot change password. */
    public function test_wrong_current_password_cannot_change_password(): void
    {
        $user = User::create(['name'=>'Customer','email'=>'password-wrong@example.com','password'=>'StrongPass123','role'=>'customer']);

        $this->actingAs($user)->putJson('/api/v1/security/password', [
            'current_password'=>'WrongPassword123',
            'password'=>'NewStrongPass456',
            'password_confirmation'=>'NewStrongPass456',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('StrongPass123', $user->fresh()->password));
    }

    /** Verifies customer account routes are present in react application. */
    public function test_customer_account_routes_are_present_in_react_application(): void
    {
        $app = file_get_contents(resource_path('js/App.jsx'));
        foreach (['/account','profile','addresses','orders/:id','wishlist','wallet','payment-methods','verification','security','notifications','messages','returns'] as $route) {
            $this->assertStringContainsString($route, $app);
        }
    }
}
