<?php

namespace App\Services\Media;

use App\Enums\MediaCollection;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Low-level file operations on the media disk. Only MediaService and the Media model call it;
 * everything else works with Media rows. New files go to config('filesystems.media_disk'); existing
 * files are read and deleted on the disk recorded on their Media row.
 */
final class MediaStorageService
{
    /**
     * Stores the file in the collection's folder with a random name and the extension of its real
     * content (not the client's file name). Returns the stored path, e.g. "avatars/01jb….jpg".
     */
    public function store(UploadedFile $file, MediaCollection $collection): string
    {
        $name = strtolower((string) Str::ulid()).'.'.$file->extension();

        return $this->disk()->putFileAs($collection->directory(), $file, $name);
    }

    public function url(string $path, ?string $disk = null): string
    {
        return $this->disk($disk)->url($path);
    }

    public function exists(string $path, ?string $disk = null): bool
    {
        return $this->disk($disk)->exists($path);
    }

    /**
     * Deletes the file. A failure is reported but never thrown: the database change that made the
     * file unused has already happened, and an orphaned file is harmless.
     */
    public function delete(string $path, ?string $disk = null): void
    {
        try {
            $this->disk($disk)->delete($path);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** The disk new files are written to. */
    public function currentDisk(): string
    {
        return config('filesystems.media_disk');
    }

    private function disk(?string $disk = null): Filesystem
    {
        return Storage::disk($disk ?? $this->currentDisk());
    }
}
