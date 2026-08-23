<?php

namespace App\Domain\Catalog\Services;

use App\Models\MediaLibraryAsset;
use App\Models\Vendor;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Resolves reusable media-library assets used by vendor storefront identity. */
class VendorStorefrontMediaService
{
    /** Resolves a requested logo from its stable asset id or the existing picker URL compatibility path. */
    public function resolveSelection(Vendor $vendor, ?string $publicId, ?string $legacyUrl = null): ?MediaLibraryAsset
    {
        if ($publicId !== null && trim($publicId) !== '') {
            return $this->validateLogoSelection($vendor, $publicId);
        }

        $legacyUrl = trim((string) $legacyUrl);
        if ($legacyUrl === '') {
            return null;
        }

        $asset = MediaLibraryAsset::query()
            ->where('status', 'active')
            ->where(/** Restricts compatibility matching to seller-visible media. */ function ($query) use ($vendor): void {
                $query->whereNull('vendor_id')->orWhere('vendor_id', $vendor->id);
            })
            ->get()
            ->first(/** Matches the current resolved storage URL without persisting that URL as identity. */ function (MediaLibraryAsset $candidate) use ($legacyUrl): bool {
                return Storage::disk($candidate->disk)->url($candidate->path) === $legacyUrl;
            });

        if (! $asset) {
            throw ValidationException::withMessages([
                'logoUrl' => ['Choose the store logo from your Media Library instead of entering an external image URL.'],
            ]);
        }

        return $asset;
    }

    /** Validates that a selected logo is active and visible to the vendor. */
    public function validateLogoSelection(Vendor $vendor, ?string $publicId): ?MediaLibraryAsset
    {
        if ($publicId === null || trim($publicId) === '') {
            return null;
        }

        $asset = MediaLibraryAsset::query()
            ->where('public_id', trim($publicId))
            ->where('status', 'active')
            ->where(/** Allows marketplace-global media or media owned by this seller. */ function ($query) use ($vendor): void {
                $query->whereNull('vendor_id')->orWhere('vendor_id', $vendor->id);
            })
            ->first();

        if (! $asset) {
            throw ValidationException::withMessages([
                'logoMediaAssetId' => ['Choose an active image from your Media Library or marketplace-global media.'],
            ]);
        }

        return $asset;
    }

    /** Resolves the currently stored logo asset without exposing stale or cross-vendor media. */
    public function logoAsset(Vendor $vendor): ?MediaLibraryAsset
    {
        $publicId = trim((string) (($vendor->metadata ?? [])['logoMediaAssetId'] ?? ''));
        if ($publicId === '') {
            return null;
        }

        return MediaLibraryAsset::query()
            ->where('public_id', $publicId)
            ->where('status', 'active')
            ->where(/** Keeps vendor identity scoped to reusable media the seller is allowed to use. */ function ($query) use ($vendor): void {
                $query->whereNull('vendor_id')->orWhere('vendor_id', $vendor->id);
            })
            ->first();
    }

    /** Returns a stable asset reference plus the currently resolved delivery URL. */
    public function logoPayload(Vendor $vendor): array
    {
        $asset = $this->logoAsset($vendor);

        return [
            'logoMediaAssetId' => $asset?->public_id,
            'logoUrl' => $asset ? Storage::disk($asset->disk)->url($asset->path) : null,
            'logoAlt' => $asset?->alt_text,
        ];
    }
}
