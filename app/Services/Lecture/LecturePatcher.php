<?php

declare(strict_types=1);

namespace App\Services\Lecture;

use InvalidArgumentException;
use RuntimeException;

/**
 * PHP-порт editor_server.apply_patch из lecture-ui.
 *
 * Применяет список правок к лекционному JSON и сохраняет результат с бэкапом.
 * Принципиально не вызывает Python — Python-сервис вызывается только для
 * финального рендера через LectureBuilderClient::render().
 */
class LecturePatcher
{
    /**
     * Структура одной правки:
     *   section_id   — id секции (s1, s2, ...)
     *   block_index  — индекс блока внутри section.content
     *   turn_index   — индекс реплики (для dialog) или null (для speech/interjection)
     *   para_index   — индекс абзаца или null (для строкового text)
     *   field        — что меняем: text|title|caption (по умолчанию text)
     *   value        — новое значение
     *
     * @param  list<array{section_id: string, block_index?: int, turn_index?: ?int,
     *                    para_index?: ?int, field?: string, value: string}>  $patches
     */
    public function apply(array $lecture, array $patches): array
    {
        $sections = $lecture['sections'] ?? [];
        $byId = [];
        foreach ($sections as $idx => $section) {
            $id = $section['id'] ?? null;
            if ($id !== null) {
                $byId[$id] = $idx;
            }
        }

        foreach ($patches as $i => $edit) {
            $sectionId = $edit['section_id'] ?? null;
            if ($sectionId === null || ! isset($byId[$sectionId])) {
                throw new InvalidArgumentException("patch[{$i}]: неизвестный section_id={$sectionId}");
            }

            $sIdx = $byId[$sectionId];

            // Структурные операции (Phase B): перемещение/удаление блоков,
            // разбиение/слияние абзацев. Меняют состав section.content, поэтому
            // их шлют по одной (см. LectureDraftController::patch). Отличаются
            // ключом `op`; обычные правки полей идут прежним путём ниже.
            if (isset($edit['op'])) {
                $sections[$sIdx] = $this->applyStructural($sections[$sIdx], $edit, $i);

                continue;
            }

            $field = $edit['field'] ?? 'text';
            $value = $edit['value'] ?? null;

            if ($value === null) {
                throw new InvalidArgumentException("patch[{$i}]: пустое value");
            }

            // Правка заголовка секции
            if ($field === 'title' && ! isset($edit['block_index'])) {
                $sections[$sIdx]['title'] = $value;

                continue;
            }

            $blockIndex = $edit['block_index'] ?? null;
            if ($blockIndex === null) {
                throw new InvalidArgumentException("patch[{$i}]: нужен block_index для field={$field}");
            }

            if (! isset($sections[$sIdx]['content'][$blockIndex])) {
                throw new InvalidArgumentException("patch[{$i}]: нет блока {$blockIndex} в секции {$sectionId}");
            }

            $block = &$sections[$sIdx]['content'][$blockIndex];
            $type = $block['type'] ?? null;

            switch ($type) {
                case 'speech':
                    $this->patchSpeech($block, $edit, $i);
                    break;

                case 'dialog':
                    $this->patchDialog($block, $edit, $i);
                    break;

                case 'interjection':
                    $block['text'] = $value;
                    break;

                case 'text':
                    $paraIndex = $edit['para_index'] ?? null;
                    if ($paraIndex === null) {
                        throw new InvalidArgumentException("patch[{$i}]: для text нужен para_index");
                    }
                    if (! isset($block['paragraphs'][$paraIndex])) {
                        throw new InvalidArgumentException("patch[{$i}]: нет абзаца {$paraIndex}");
                    }
                    $block['paragraphs'][$paraIndex] = $value;
                    break;

                case 'figure':
                    if ($field === 'caption') {
                        $block['caption'] = $value;
                    } elseif ($field === 'alt') {
                        $block['alt'] = $value;
                    } else {
                        throw new InvalidArgumentException("patch[{$i}]: для figure разрешены только caption/alt");
                    }
                    break;

                default:
                    throw new InvalidArgumentException("patch[{$i}]: неизвестный type={$type}");
            }

            unset($block);
        }

        $lecture['sections'] = $sections;

        return $lecture;
    }

    /**
     * Применяет патч к файлу data.json и пишет бэкап.
     * Возвращает абсолютный путь к созданному бэкапу.
     */
    public function applyToFile(string $absoluteDataJsonPath, array $patches): string
    {
        if (! is_file($absoluteDataJsonPath)) {
            throw new RuntimeException("data.json не найден: {$absoluteDataJsonPath}");
        }

        $raw = file_get_contents($absoluteDataJsonPath);
        if ($raw === false) {
            throw new RuntimeException("Не удалось прочитать {$absoluteDataJsonPath}");
        }

        $lecture = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($lecture)) {
            throw new RuntimeException('data.json содержит не-объект');
        }

        // Бэкап ДО изменений
        $backupDir = dirname($absoluteDataJsonPath).DIRECTORY_SEPARATOR.'backups';
        if (! is_dir($backupDir) && ! mkdir($backupDir, 0775, true) && ! is_dir($backupDir)) {
            throw new RuntimeException("Не удалось создать {$backupDir}");
        }
        $backupPath = $backupDir.DIRECTORY_SEPARATOR.date('Ymd_His').'.json';
        file_put_contents($backupPath, $raw);

        $updated = $this->apply($lecture, $patches);

        $written = file_put_contents(
            $absoluteDataJsonPath,
            json_encode($updated, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
        if ($written === false) {
            throw new RuntimeException("Не удалось записать {$absoluteDataJsonPath}");
        }

        return $backupPath;
    }

    private function patchSpeech(array &$block, array $edit, int $i): void
    {
        $paraIndex = $edit['para_index'] ?? null;
        $value = $edit['value'];

        if ($paraIndex === null) {
            throw new InvalidArgumentException("patch[{$i}]: для speech нужен para_index");
        }
        if (! isset($block['paragraphs'][$paraIndex])) {
            throw new InvalidArgumentException("patch[{$i}]: нет абзаца {$paraIndex} в speech");
        }

        $para = &$block['paragraphs'][$paraIndex];
        if (is_array($para)) {
            $para['text'] = $value;
        } else {
            $para = $value;
        }
    }

    private function patchDialog(array &$block, array $edit, int $i): void
    {
        $turnIndex = $edit['turn_index'] ?? null;
        $paraIndex = $edit['para_index'] ?? null;
        $value = $edit['value'];

        if ($turnIndex === null || ! isset($block['turns'][$turnIndex])) {
            throw new InvalidArgumentException("patch[{$i}]: нет turn {$turnIndex} в dialog");
        }

        $turn = &$block['turns'][$turnIndex];

        if ($paraIndex === null) {
            $turn['text'] = $value;
        } elseif (is_array($turn['text'] ?? null)) {
            if (! array_key_exists($paraIndex, $turn['text'])) {
                throw new InvalidArgumentException("patch[{$i}]: нет абзаца {$paraIndex} в реплике");
            }
            $turn['text'][$paraIndex] = $value;
        } else {
            throw new InvalidArgumentException("patch[{$i}]: реплика — строка, para_index не применим");
        }
    }

    // ==========================================
    // СТРУКТУРНЫЕ ОПЕРАЦИИ (Phase B)
    // ==========================================
    // Работают по собранной форме лекции: блоки speech/figure внутри section.content,
    // абзацы speech.paragraphs — объекты {text, t?, dg_ref?} либо строки.

    /**
     * @param  array{op: string, block_index?: int, to_index?: int, para_index?: int}  $edit
     */
    private function applyStructural(array $section, array $edit, int $i): array
    {
        $content = array_values($section['content'] ?? []);
        $op = $edit['op'];

        switch ($op) {
            case 'move_block':
                $from = $this->blockIndex($content, $edit, $i);
                $to = $this->clampIndex((int) ($edit['to_index'] ?? $from), count($content) - 1);
                $moved = array_splice($content, $from, 1);
                array_splice($content, $to, 0, $moved);
                break;

            case 'delete_block':
                $idx = $this->blockIndex($content, $edit, $i);
                array_splice($content, $idx, 1);
                break;

            case 'split_para':
                $idx = $this->blockIndex($content, $edit, $i);
                $content[$idx] = $this->splitParagraph($content[$idx], $edit, $i);
                break;

            case 'merge_para':
                $idx = $this->blockIndex($content, $edit, $i);
                $content[$idx] = $this->mergeParagraph($content[$idx], $edit, $i);
                break;

            default:
                throw new InvalidArgumentException("patch[{$i}]: неизвестная структурная операция op={$op}");
        }

        $section['content'] = array_values($content);

        return $section;
    }

    /** Валидирует и возвращает block_index в пределах content. */
    private function blockIndex(array $content, array $edit, int $i): int
    {
        $idx = $edit['block_index'] ?? null;
        if (! is_int($idx) || ! array_key_exists($idx, $content)) {
            throw new InvalidArgumentException("patch[{$i}]: нет блока {$idx}");
        }

        return $idx;
    }

    private function clampIndex(int $idx, int $max): int
    {
        return max(0, min($idx, max(0, $max)));
    }

    /**
     * Разбивает абзац speech-блока по символьному offset на два. Таймкод/dg_ref
     * остаются у первой половины; вторая получает чистый текст (новый абзац).
     */
    private function splitParagraph(array $block, array $edit, int $i): array
    {
        if (($block['type'] ?? null) !== 'speech' || ! isset($block['paragraphs'])) {
            throw new InvalidArgumentException("patch[{$i}]: split_para применим только к speech");
        }

        $p = $edit['para_index'] ?? null;
        $offset = (int) ($edit['offset'] ?? 0);
        if (! is_int($p) || ! array_key_exists($p, $block['paragraphs'])) {
            throw new InvalidArgumentException("patch[{$i}]: нет абзаца {$p} для split");
        }

        $para = $block['paragraphs'][$p];
        $text = is_array($para) ? ($para['text'] ?? '') : (string) $para;

        $left = rtrim(mb_substr($text, 0, $offset));
        $right = ltrim(mb_substr($text, $offset));
        if ($left === '' || $right === '') {
            throw new InvalidArgumentException("patch[{$i}]: split в начале/конце абзаца — нечего разбивать");
        }

        $first = is_array($para) ? array_merge($para, ['text' => $left]) : $left;
        $second = ['text' => $right]; // новый абзац без таймкода/dg_ref

        array_splice($block['paragraphs'], $p, 1, [$first, $second]);

        return $block;
    }

    /**
     * Сливает абзац speech-блока с предыдущим (текст через пробел). Метаданные
     * (таймкод/dg_ref) предыдущего абзаца сохраняются.
     */
    private function mergeParagraph(array $block, array $edit, int $i): array
    {
        if (($block['type'] ?? null) !== 'speech' || ! isset($block['paragraphs'])) {
            throw new InvalidArgumentException("patch[{$i}]: merge_para применим только к speech");
        }

        $p = $edit['para_index'] ?? null;
        if (! is_int($p) || ! array_key_exists($p, $block['paragraphs'])) {
            throw new InvalidArgumentException("patch[{$i}]: нет абзаца {$p} для merge");
        }
        if ($p === 0) {
            throw new InvalidArgumentException("patch[{$i}]: первый абзац нельзя слить с предыдущим");
        }

        $textOf = static function ($para): string {
            return is_array($para) ? ($para['text'] ?? '') : (string) $para;
        };

        $prev = $block['paragraphs'][$p - 1];
        $mergedText = rtrim($textOf($prev)).' '.ltrim($textOf($block['paragraphs'][$p]));

        if (is_array($prev)) {
            $prev['text'] = $mergedText;
        } else {
            $prev = $mergedText;
        }
        $block['paragraphs'][$p - 1] = $prev;
        array_splice($block['paragraphs'], $p, 1);

        return $block;
    }
}
