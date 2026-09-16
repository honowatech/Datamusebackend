<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schéma OpenAPI `MobileFormManifestItem` (GET /mobile/forms).
 *
 * La ressource enveloppe le tableau déjà construit par `MobileManifestService::build()` : l'ETag
 * est calculé sur ce même tableau, la représentation servie doit donc rester identique octet pour octet.
 */
class MobileFormManifestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return is_array($this->resource) ? $this->resource : (array) $this->resource;
    }
}
