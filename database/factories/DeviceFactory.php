<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'device_id' => 'android-'.Str::lower(Str::random(8)),
            'platform' => 'android',
            'app_version' => '1.0.0',
            'push_token' => null,
            'last_seen_at' => now(),
        ];
    }

    public function ios(): static
    {
        return $this->state(['platform' => 'ios', 'device_id' => 'ios-'.Str::lower(Str::random(8))]);
    }
}
