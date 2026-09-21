<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\GreetingName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GreetingNameTest extends TestCase
{
    /** @return array<string, array{0: ?string, 1: string}> */
    public static function names(): array
    {
        return [
            'ФИО (боевой случай 21-09)' => ['Мухасанова Хадижа Абдурахмановна', 'Хадижа'],
            'чекаут: Фамилия Имя, Город' => ['Иванов Иван, Москва', 'Иван'],
            'Имя Фамилия' => ['Мария Петрова', 'Мария'],
            'Фамилия Имя, имя похоже на фамилию' => ['Петрова Марина', 'Марина'],
            'город в скобках' => ['Анна (Казань)', 'Анна'],
            'капс, латиница' => ['ALEX SMITH', 'Alex'],
            'строчные' => ['хадижа', 'Хадижа'],
            'двойное имя' => ['Анна-Мария Иванова', 'Анна-Мария'],
            'одно слово не из словаря' => ['Сатьявати', 'Сатьявати'],
            'Фамилия Имя Отчество, имя не из словаря' => ['Абдуллаева Сабрина Руслановна', 'Сабрина'],
            'Имя Отчество' => ['Сабрина Руслановна', 'Сабрина'],
            'Фамилия Имя, имя не из словаря' => ['Кузнецова Эсфирь', 'Эсфирь'],
            'хвост через тире' => ['Ольга — Санкт-Петербург', 'Ольга'],
            'ё' => ['Семён Лебедев', 'Семён'],
            'e-mail вместо имени' => ['student@example.com', 'Друг'],
            'пусто' => ['', 'Друг'],
            'null' => [null, 'Друг'],
            'одни цифры' => ['12345', 'Друг'],
        ];
    }

    #[DataProvider('names')]
    public function test_extracts_first_name(?string $full, string $expected): void
    {
        $this->assertSame($expected, GreetingName::of($full));
    }

    public function test_custom_fallback(): void
    {
        $this->assertSame('друг', GreetingName::of('  ', 'друг'));
    }
}
