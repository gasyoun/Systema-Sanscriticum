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

    /**
     * @dataProvider matchingHelpPhrases
     */
    public function test_recognises_help_intent(string $text): void
    {
        $this->assertTrue($this->service->matchesHelpIntent($text), "Должно распознать: {$text}");
    }

    /**
     * @dataProvider nonMatchingHelpPhrases
     */
    public function test_ignores_non_help_questions(string $text): void
    {
        $this->assertFalse($this->service->matchesHelpIntent($text), "Не должно перехватывать: {$text}");
    }

    public static function matchingHelpPhrases(): array
    {
        return [
            ['/help'],
            ['/menu'],
            ['Меню'],
            ['что ты умеешь?'],
            ['покажи список команд'],
        ];
    }

    public static function nonMatchingHelpPhrases(): array
    {
        return [
            // «помощь» намеренно не перехватывается self-service — это триггер
            // передачи живому куратору (HUMAN_TRIGGERS), а не бот-меню.
            ['позови на помощь'],
            ['Сколько стоит курс?'],
            [''],
        ];
    }

    /**
     * @dataProvider matchingHomeworkPhrases
     */
    public function test_recognises_homework_intent(string $text): void
    {
        $this->assertTrue($this->service->matchesHomeworkIntent($text), "Должно распознать: {$text}");
    }

    /**
     * @dataProvider nonMatchingHomeworkPhrases
     */
    public function test_ignores_non_homework_questions(string $text): void
    {
        $this->assertFalse($this->service->matchesHomeworkIntent($text), "Не должно перехватывать: {$text}");
    }

    public static function matchingHomeworkPhrases(): array
    {
        return [
            ['/homework'],
            ['мои задания'],
            ['Моё задание'],
            ['как там моя домашка?'],
            ['статус ДЗ'],
        ];
    }

    public static function nonMatchingHomeworkPhrases(): array
    {
        return [
            ['Сколько стоит курс?'],
            ['мои группы'],
            [''],
        ];
    }

    /**
     * @dataProvider matchingAttendanceNoticePhrases
     */
    public function test_recognises_attendance_notice_intent(string $text): void
    {
        $this->assertTrue(
            $this->service->matchesAttendanceNoticeIntent($text),
            "Должно распознать: {$text}",
        );
    }

    /**
     * @dataProvider nonMatchingAttendanceNoticePhrases
     */
    public function test_ignores_non_attendance_notice_questions(string $text): void
    {
        $this->assertFalse(
            $this->service->matchesAttendanceNoticeIntent($text),
            "Не должно перехватывать: {$text}",
        );
    }

    public static function matchingAttendanceNoticePhrases(): array
    {
        return [
            ['не смогу на урок'],
            ['/absent'],
            ['опоздаю'],
            ['уйду раньше'],
            ['возможно не буду'],
            ['проблемная связь'],
            ['буду на уроке'],
        ];
    }

    public static function nonMatchingAttendanceNoticePhrases(): array
    {
        return [
            ['Сколько стоит курс?'],
            ['мои группы'],
            ['привет'],
            [''],
        ];
    }
}
