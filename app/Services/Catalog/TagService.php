<?php

namespace App\Services\Catalog;

use App\Models\Tag;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Tags are shared by every product: a rename or delete applies everywhere at once. */
final class TagService
{
    /** @throws ValidationException when the name (or its slug) is taken */
    public function createTag(string $name): Tag
    {
        $tag = new Tag;
        $this->applyName($tag, $name);
        $tag->save();

        return $tag;
    }

    /** @throws ValidationException when the name (or its slug) is taken */
    public function renameTag(Tag $tag, string $name): Tag
    {
        $this->applyName($tag, $name);
        $tag->save();

        return $tag;
    }

    /** Removes the tag from every variant (the link rows cascade); variants are untouched. */
    public function deleteTag(Tag $tag): void
    {
        $tag->delete();
    }

    private function applyName(Tag $tag, string $name): void
    {
        $name = trim($name);
        $slug = Str::slug($name);

        $taken = Tag::query()
            ->when($tag->exists, fn ($query) => $query->whereKeyNot($tag->id))
            ->where(fn ($where) => $where->where('slug', $slug)->orWhereRaw('lower(name) = ?', [mb_strtolower($name)]))
            ->exists();

        if ($slug === '' || $taken) {
            throw ValidationException::withMessages([
                'name' => $slug === '' ? 'The tag name needs letters or numbers.' : "There's already a tag called {$name}.",
            ]);
        }

        $tag->fill(['name' => $name, 'slug' => $slug]);
    }
}
