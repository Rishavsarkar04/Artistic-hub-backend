<?php

namespace App\Services\Media;

use App\Enums\MediaDirectory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The only place that reads or writes uploaded images. Uses the disk in
 * config('filesystems.media_disk'): local `public` in development, `s3` in production.
 * Files are publicly readable by URL; names are random, never the uploaded file name.
 */
final class MediaStorageService
{
    /**
     * Stores the file in $directory with a random name and the extension of its real content
     * (not the client's file name). Returns the stored path, e.g. "avatars/01jb….jpg".
     */
    public function store(UploadedFile $file, MediaDirectory $directory): string
    {
        $name = strtolower((string) Str::ulid()).'.'.$file->extension();

        return $this->disk()->putFileAs($directory->value, $file, $name);
    }

    public function url(string $path): string
    {
        return $this->disk()->url($path);
    }

    /**
     * Deletes the file. A failure is reported but never thrown: the database change that made the
     * file unused has already happened, and an orphaned file is harmless.
     */
    public function delete(string $path): void
    {
        try {
            $this->disk()->delete($path);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('filesystems.media_disk'));
    }
}
