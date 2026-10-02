<?php

namespace App\Enums;

/**
 * Folders on the media disk. Every folder here must also be publicly readable in the S3 bucket
 * policy (docs/setup.md, step 5b); add a case and update the policy together.
 */
enum MediaDirectory: string
{
    case Avatars = 'avatars';
    case Products = 'products';
}
