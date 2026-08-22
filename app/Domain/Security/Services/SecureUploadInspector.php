<?php

namespace App\Domain\Security\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/** Defines the SecureUploadInspector class and its project responsibilities. */
class SecureUploadInspector
{
    /** Handles inspect for the secure upload inspector workflow. */
    public function inspect(UploadedFile $file, array $allowedMimeTypes, ?int $maxBytes = null, bool $imageDimensions = false): array
    {
        abort_unless($file->isValid(), 422, 'The uploaded file is not valid.');
        $mime = strtolower((string) $file->getMimeType());
        if (! in_array($mime, $allowedMimeTypes, true)) {
            throw ValidationException::withMessages(['file' => ['The uploaded file type is not allowed.']]);
        }
        $bytes = (int) $file->getSize();
        $maxBytes ??= (int) config('vsn.security.uploads.max_file_bytes', 10_485_760);
        if ($bytes <= 0 || $bytes > $maxBytes) {
            throw ValidationException::withMessages(['file' => ['The uploaded file exceeds the allowed size.']]);
        }

        $result = ['mime' => $mime, 'bytes' => $bytes, 'sha256' => hash_file('sha256', $file->getRealPath())];
        if ($imageDimensions && str_starts_with($mime, 'image/')) {
            $dimensions = @getimagesize($file->getRealPath());
            if (! is_array($dimensions) || empty($dimensions[0]) || empty($dimensions[1])) {
                throw ValidationException::withMessages(['file' => ['The uploaded image could not be inspected safely.']]);
            }
            $width = (int) $dimensions[0];
            $height = (int) $dimensions[1];
            $maxDimension = (int) config('vsn.security.uploads.max_image_dimension', 12_000);
            $maxPixels = (int) config('vsn.security.uploads.max_image_pixels', 50_000_000);
            if ($width > $maxDimension || $height > $maxDimension || ($width * $height) > $maxPixels) {
                throw ValidationException::withMessages(['file' => ['The uploaded image dimensions are too large.']]);
            }
            $result['width'] = $width;
            $result['height'] = $height;
        }
        return $result;
    }
}
