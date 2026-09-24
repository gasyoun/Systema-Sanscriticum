<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Чистый small talk без вопроса: 'greeting' | 'thanks' | null.
 *
 * «Чистый» = после вырезания приветственных/благодарных оборотов и
 * вежливых обстоятельств («большое», «вам») не остаётся содержательного
 * слова. «Намасте, сколько стоит курс?» — НЕ small talk.
 *
 * Общий для TG-автоответа, веб-чата и роутера тредов Helpdesk.
 */
final class SupportSmallTalk
{
    public static function kind(string $text): ?string
    {
        $normalized = mb_strtolower(trim($text));
        $stripped = preg_replace('~[^\p{L}\p{N}\s]~u', ' ', $normalized) ?? '';
        $stripped = (string) preg_replace('~\s+~u', ' ', trim($stripped));

        if ($stripped === '') {
            return null;
        }

        $thanksWords = ['спасибо', 'спс', 'благодарю', 'благодарочка', 'thanks', 'thank you'];
        // Всё на «нам…»: намасте/намо/намах и производные школы.
        $greetingWords = ['привет', 'здравствуйте', 'добрый день', 'добрый вечер',
            'доброе утро', 'hello', 'hi', 'добрый'];
        $courtesyWords = ['большое', 'огромное', 'вам', 'тебе', 'пожалуйста', 'всем'];

        $isGreeting = false;
        $isThanks = false;
        $hasContent = false;

        foreach (explode(' ', $stripped) as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            if (in_array($token, $thanksWords, true)) {
                $isThanks = true;

                continue;
            }

            if (in_array($token, $greetingWords, true) || str_starts_with($token, 'нам')) {
                $isGreeting = true;

                continue;
            }

            if (in_array($token, $courtesyWords, true)) {
                continue;
            }

            $hasContent = true;
        }

        if ($hasContent) {
            return null;
        }
        if ($isGreeting) {
            return 'greeting';
        }
        if ($isThanks) {
            return 'thanks';
        }

        return null;
    }
}
