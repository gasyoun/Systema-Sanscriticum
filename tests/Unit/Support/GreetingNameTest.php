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

    /** @return array<string, array{0: ?string, 1: string}> */
    public static function shortNames(): array
    {
        return [
            'боевой случай: Фамилия Имя Отчество, Город' => ['Хара Галина Юрьевна, Москва', 'Хара Галина'],
            'ФИО' => ['Мухасанова Хадижа Абдурахмановна', 'Мухасанова Хадижа'],
            'Имя Отчество Фамилия' => ['Иван Иванович Иванов', 'Иван Иванов'],
            'чекаут: Фамилия Имя, Город' => ['Иванов Иван, Москва', 'Иванов Иван'],
            'Имя Фамилия' => ['Мария Петрова', 'Мария Петрова'],
            'двойная фамилия с дефисом' => ['Раева-Хара Галина Юрьевна', 'Раева-Хара Галина'],
            'город в скобках' => ['Анна (Казань)', 'Анна'],
            'хвост через тире' => ['Ольга — Санкт-Петербург', 'Ольга'],
            'после чистки осталось только отчество' => ['Юрьевна, Москва', 'Юрьевна'],
            'одно слово' => ['Сатьявати', 'Сатьявати'],
            'капс, латиница' => ['ALEX SMITH', 'Alex Smith'],
            'строчные' => ['хара галина', 'Хара Галина'],
            'e-mail вместо имени' => ['student@example.com', ''],
            'пусто' => ['', ''],
            'null' => [null, ''],
        ];
    }

    #[DataProvider('shortNames')]
    public function test_extracts_surname_and_first_name(?string $full, string $expected): void
    {
        $this->assertSame($expected, GreetingName::shortOf($full));
    }
}
