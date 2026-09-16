<?php

namespace App\Http\Resources;

use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schéma OpenAPI `Device` (POST /mobile/devices).
 *
 * `time_offset_ms` = `device_time − server_time` mesuré au moment de l'enregistrement ; null si le
 * client n'a pas envoyé `device_time`. Le champ est transient (posé par le contrôleur).
 *
 * @mixin Device
 */
class DeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'device_id' => $this->device_id,
            'platform' => $this->platform,
            'app_version' => $this->app_version,
            'model' => $this->model,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'time_offset_ms' => isset($this->resource->time_offset_ms) ? (int) $this->resource->time_offset_ms : null,
        ];
    }
}
