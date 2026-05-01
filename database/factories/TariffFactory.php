<?php

namespace Database\Factories;

use App\Models\Tariff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tariff>
 */
class TariffFactory extends Factory
{
    protected $model = Tariff::class;

    public function definition(): array
    {
        return [
            'title'        => 'Весь курс целиком',
            'type'         => 'full',
            'block_number' => null,
            'price'        => 12000,
            'is_active'    => true,
        ];
    }

    public function block(int $number): static
    {
        return $this->state(fn () => [
            'title'        => 'Блок ' . $number,
            'type'         => 'block',
            'block_number' => $number,
            'price'        => 4800,
        ]);
    }
}
