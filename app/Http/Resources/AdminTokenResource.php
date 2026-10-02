<?php

namespace App\Http\Resources;

use App\Data\IssuedToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An admin's new access token. Send it as `Authorization: Bearer <access_token>`.
 *
 * @property IssuedToken $resource
 */
class AdminTokenResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'token_type' => 'Bearer',
            'access_token' => $this->resource->accessToken,
            'expires_at' => $this->resource->expiresAt->toIso8601String(),
            'user' => new AdminResource($this->resource->user),
        ];
    }
}
