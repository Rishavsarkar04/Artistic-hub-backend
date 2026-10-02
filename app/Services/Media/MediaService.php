<?php

namespace App\Services\Media;

use App\Enums\MediaCollection;
use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Uploads create unattached Media rows; owners' services attach them when the owner is saved.
 * Deleting media deletes the rows first and the files afterwards.
 */
final class MediaService
{
    public function __construct(private MediaStorageService $mediaStorageService) {}

    /**
     * Stores the file and records it, not yet attached to anything. If the row cannot be saved, the
     * file is deleted again. A row that is never attached is pruned after the retention window.
     */
    public function upload(UploadedFile $file, MediaCollection $collection, ?User $uploader): Media
    {
        $path = $this->mediaStorageService->store($file, $collection);

        try {
            $media = new Media;
            $media->collection = $collection;
            $media->disk = $this->mediaStorageService->currentDisk();
            $media->path = $path;
            $media->mime_type = $file->getMimeType();
            $media->size = $file->getSize();
            $media->uploaded_by = $uploader?->id;
            $media->save();

            return $media;
        } catch (Throwable $exception) {
            $this->mediaStorageService->delete($path);

            throw $exception;
        }
    }

    /**
     * Deletes one media row and then its file, as a step of its own (not inside a larger transaction).
     */
    public function delete(Media $media): void
    {
        $media->delete();
        $this->deleteFiles([$media]);
    }

    /**
     * Deletes many media rows in one query, keeping their files. Call inside the caller's transaction,
     * then call deleteFiles() with the same media after it commits, so a rollback never loses a file.
     *
     * @param  Collection<int, Media>  $media
     */
    public function deleteRows(Collection $media): void
    {
        if ($media->isNotEmpty()) {
            Media::whereKey($media->modelKeys())->delete();
        }
    }

    /**
     * Deletes the files of media whose rows were deleted in a transaction that has committed.
     *
     * @param  iterable<Media>  $media
     */
    public function deleteFiles(iterable $media): void
    {
        foreach ($media as $item) {
            $this->mediaStorageService->delete($item->path, $item->disk);
        }
    }
}
