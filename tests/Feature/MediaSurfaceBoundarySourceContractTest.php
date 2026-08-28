<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Locks the P2-C media boundary classification for non-catalog surfaces.
 *
 * First-party uploads persist storage identity (disk/path), while URL-shaped
 * fields are reserved for intentional provider-owned or navigation references.
 */
final class MediaSurfaceBoundarySourceContractTest extends TestCase
{
    /** Read a repository source file used by this architecture contract. */
    private function source(string $relativePath): string
    {
        $source = file_get_contents(base_path($relativePath));

        $this->assertIsString($source, "Expected source file {$relativePath} to be readable.");

        return $source;
    }

    /**
     * Review images, message attachments and KYC documents must keep canonical
     * identity as an owned storage location instead of an arbitrary delivery URL.
     */
    public function test_first_party_upload_surfaces_persist_owned_storage_identity(): void
    {
        $reviews = $this->source('app/Domain/Reviews/Actions/SubmitVerifiedReview.php');
        $this->assertStringContainsString('$image->store("reviews/', $reviews);
        $this->assertStringContainsString("'disk'=>\$disk", $reviews);
        $this->assertStringContainsString("'path'=>\$path", $reviews);
        $this->assertStringNotContainsString("'url'=>\$path", $reviews);

        $messages = $this->source('app/Domain/Messaging/Actions/SendConversationMessage.php');
        $this->assertStringContainsString('$file->storeAs("messages/', $messages);
        $this->assertStringContainsString("'disk'=>'local','path'=>\$path", $messages);
        $this->assertStringNotContainsString("'url'=>\$path", $messages);

        $kyc = $this->source('app/Domain/Kyc/Actions/SubmitKycVerification.php');
        $this->assertStringContainsString("config('vsn.kyc.document_disk', 'local')", $kyc);
        $this->assertStringContainsString('$file->storeAs($dir, $name.', $kyc);
        $this->assertStringContainsString("'document_front_path'", $kyc);
        $this->assertStringContainsString("'address_proof_path'", $kyc);
        $this->assertStringNotContainsString("'document_front_url'", $kyc);
        $this->assertStringNotContainsString("'address_proof_url'", $kyc);
    }

    /** The dormant profile avatar path must not silently become a URL editor. */
    public function test_profile_update_does_not_accept_avatar_path_or_url_identity(): void
    {
        $request = $this->source('app/Http/Requests/Profile/UpdateProfileRequest.php');
        $controller = $this->source('app/Http/Controllers/Api/V1/ProfileController.php');

        $this->assertStringNotContainsString("'avatar_path'", $request);
        $this->assertStringNotContainsString("'avatar_url'", $request);
        $this->assertStringNotContainsString("'avatar_path'", $controller);
        $this->assertStringNotContainsString("'avatar_url'", $controller);
    }

    /**
     * URL-shaped persistence remains allowed where the external/navigation
     * semantics are explicit rather than being first-party media identity.
     */
    public function test_allowed_url_fields_are_explicit_provider_or_navigation_references(): void
    {
        $shipping = $this->source('app/Domain/Shipping/Actions/CreateShipment.php');
        $this->assertStringContainsString('createShipment($shipment', $shipping);
        $this->assertStringContainsString("'label_url'=>\$result->labelUrl", $shipping);

        $notifications = $this->source('app/Domain/Notifications/Actions/PublishMarketplaceNotification.php');
        $this->assertStringContainsString("'action_url' => \$actionUrl", $notifications);

        $messages = $this->source('app/Domain/Messaging/Actions/SendConversationMessage.php');
        $this->assertStringContainsString("'/messages?conversation='", $messages);
    }
}
