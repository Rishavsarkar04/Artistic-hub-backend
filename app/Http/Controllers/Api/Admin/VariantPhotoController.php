<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\MediaCollection;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UploadVariantPhotosRequest;
use App\Http\Resources\MediaResource;
use App\Services\Catalog\VariantPhotoService;
use App\Services\Media\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

class VariantPhotoController extends Controller
{
    /**
     * Upload variant photos.
     *
     * multipart/form-data with `photos[]`: 1 to 8 files. Stores each one, not yet attached to any variant, and
     * returns them in the order sent, each with its media `id` (send the ids in the product save under
     * variants.*.photo_ids) and `url` (for the preview). If any file is invalid, none is stored (422 names the
     * file, e.g. photos.2). Photos never attached to a variant are deleted automatically after 24 hours.
     */
    public function store(UploadVariantPhotosRequest $request, MediaService $mediaService): JsonResponse
    {
        $media = collect($request->file('photos'))
            ->map(fn ($photo) => $mediaService->upload($photo, MediaCollection::VariantPhoto, $request->user()));

        return MediaResource::collection($media)->response()->setStatusCode(HttpStatus::HTTP_CREATED);
    }

    /**
     * Delete a variant photo.
     *
     * Deletes the photo and its file right away: one on a saved variant (its other photos keep their order), or
     * one uploaded and removed from the form before saving. Any admin can delete any variant photo; an id that is
     * not a variant photo returns 404.
     */
    public function destroy(string $media, VariantPhotoService $variantPhotoService): Response
    {
        $variantPhotoService->deletePhoto($media);

        return response()->noContent();
    }
}
