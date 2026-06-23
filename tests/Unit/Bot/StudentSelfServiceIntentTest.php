<?php

declare(strict_types=1);

namespace Tests\Unit\Bot;

use App\Services\Bot\StudentSelfService;
use Tests\TestCase;

class StudentSelfServiceIntentTest extends TestCase
{
    private StudentSelfService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StudentSelfService;
    }

    /**
     * @dataProvider matchingPhrases
     */
    public function test_recognises_group_intent(string $text): void
    {
        $this->assertTrue($this->service->matchesGroupsIntent($text), "Должно распознать: {$text}");
    }

    /**
     * @dataProvider nonMatchingPhrases
     */
    public function test_ignores_other_questions(string $text): void
    {
        $this->assertFalse($this->service->matchesGroupsIntent($text), "Не должно перехватывать: {$text}");
    }

    public static function matchingPhrases(): array
    {
        return [
            ['мои группы'],
            ['/groups'],
            ['Мои Группы'],
            ['В каких я группах?'],
            ['моё расписание'],
            ['Покажи мои курсы'],
        ];
    }

    public static function nonMatchingPhrases(): array
    {
        return [
            ['Сколько стоит курс?'],
            ['привет'],
            ['как оплатить'],
            ['а есть группа в телеграме?'],
            [''],
        ];
    }
}
