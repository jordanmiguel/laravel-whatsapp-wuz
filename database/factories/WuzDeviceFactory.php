<?php

namespace JordanMiguel\Wuz\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use JordanMiguel\Wuz\Models\WuzDevice;

class WuzDeviceFactory extends Factory
{
    protected $model = WuzDevice::class;

    public function definition(): array
    {
        return [
            'device_id' => $this->faker->uuid(),
            'name' => $this->faker->word() . ' Device',
            'token' => $this->faker->sha256(),
            'connected' => false,
            'is_default' => false,
        ];
    }

    public function connected(): static
    {
        return $this->state([
            'connected' => true,
            'jid' => $this->faker->numerify('55##########') . '@s.whatsapp.net',
        ]);
    }

    public function default(): static
    {
        return $this->state([
            'is_default' => true,
        ]);
    }
}
