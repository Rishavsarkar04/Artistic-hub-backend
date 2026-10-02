<?php

namespace App\Enums;

/**
 * What an uploaded file is for. Each collection has its own folder on the media disk, and every
 * folder must be publicly readable in the S3 bucket policy (docs/setup.md, step 5b).
 */
enum MediaCollection: string
{
    case VariantPhoto = 'variant_photo';
    case Avatar = 'avatar';

    /** Folder on the media disk. */
    public function directory(): string
    {
        return match ($this) {
            self::VariantPhoto => 'variant-photos',
            self::Avatar => 'avatars',
        };
    }
}
