<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Mobile\RegisterDeviceRequest;
use App\Http\Resources\DeviceResource;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * `POST /mobile/devices` — enregistrement / rafraîchissement de l'appareil (B-07).
 *
 * Upsert sur `(user_id, device_id)` : met à jour `platform`, `model`, `app_version`, `push_token`
 * et `last_seen_at`. Si le client envoie `device_time`, la réponse porte l'offset mesuré
 * (`device_time − server_time`, ms) que le mobile recopie ensuite dans chaque soumission.
 */
class DeviceController extends ApiController
{
    public function store(RegisterDeviceRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        $device = Device::query()->firstOrNew([
            'user_id' => $user->id,
            'device_id' => $data['device_id'],
        ]);

        $device->forceFill([
            'user_id' => $user->id,
            'device_id' => $data['device_id'],
            'platform' => $data['platform'],
            'model' => $data['model'] ?? $device->model,
            'app_version' => $data['app_version'],
            'push_token' => array_key_exists('push_token', $data) ? $data['push_token'] : $device->push_token,
            'last_seen_at' => now(),
        ])->save();

        if (! empty($data['device_time'])) {
            $device->time_offset_ms = (int) round(
                (Carbon::parse($data['device_time'])->getPreciseTimestamp(3) - now()->getPreciseTimestamp(3))
            );
        }

        return $this->ok(new DeviceResource($device));
    }
}
