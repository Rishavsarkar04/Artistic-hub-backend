<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveTagRequest;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use App\Services\Catalog\TagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

/** Tags are shared by every product: renames and deletes apply everywhere at once. */
class TagController extends Controller
{
    public function __construct(private TagService $tagService) {}

    /** List all tags, by name. */
    public function index(): AnonymousResourceCollection
    {
        return TagResource::collection(Tag::orderBy('name')->get());
    }

    /** Create a tag. A name already used (ignoring case) returns 422. */
    public function store(SaveTagRequest $request): JsonResponse
    {
        return (new TagResource($this->tagService->createTag($request->validated('name'))))
            ->response()->setStatusCode(HttpStatus::HTTP_CREATED);
    }

    /** Rename a tag (everywhere it is used). */
    public function update(SaveTagRequest $request, Tag $tag): TagResource
    {
        return new TagResource($this->tagService->renameTag($tag, $request->validated('name')));
    }

    /** Delete a tag. It is removed from every variant; the variants stay. */
    public function destroy(Tag $tag): Response
    {
        $this->tagService->deleteTag($tag);

        return response()->noContent();
    }
}
