<?php

declare(strict_types=1);

namespace App\Services\Lecture;

use Illuminate\Support\Facades\Storage;
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
            if ($sectionId === null || !isset($byId[$sectionId])) {
                throw new InvalidArgumentException("patch[{$i}]: неизвестный section_id={$sectionId}");
            }

            $sIdx = $byId[$sectionId];
            $field = $edit['field'] ?? 'text';
            $value = $edit['value'] ?? null;

            if ($value === null) {
                throw new InvalidArgumentException("patch[{$i}]: пустое value");
            }

            // Правка заголовка секции
            if ($field === 'title' && !isset($edit['block_index'])) {
                $sections[$sIdx]['title'] = $value;
                continue;
            }

            $blockIndex = $edit['block_index'] ?? null;
            if ($blockIndex === null) {
                throw new InvalidArgumentException("patch[{$i}]: нужен block_index для field={$field}");
            }

            if (!isset($sections[$sIdx]['content'][$blockIndex])) {
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
                    if (!isset($block['paragraphs'][$paraIndex])) {
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
        if (!is_file($absoluteDataJsonPath)) {
            throw new RuntimeException("data.json не найден: {$absoluteDataJsonPath}");
        }

        $raw = file_get_contents($absoluteDataJsonPath);
        if ($raw === false) {
            throw new RuntimeException("Не удалось прочитать {$absoluteDataJsonPath}");
        }

        $lecture = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($lecture)) {
            throw new RuntimeException("data.json содержит не-объект");
        }

        // Бэкап ДО изменений
        $backupDir = dirname($absoluteDataJsonPath) . DIRECTORY_SEPARATOR . 'backups';
        if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
            throw new RuntimeException("Не удалось создать {$backupDir}");
        }
        $backupPath = $backupDir . DIRECTORY_SEPARATOR . date('Ymd_His') . '.json';
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
        if (!isset($block['paragraphs'][$paraIndex])) {
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

        if ($turnIndex === null || !isset($block['turns'][$turnIndex])) {
            throw new InvalidArgumentException("patch[{$i}]: нет turn {$turnIndex} в dialog");
        }

        $turn = &$block['turns'][$turnIndex];

        if ($paraIndex === null) {
            $turn['text'] = $value;
        } elseif (is_array($turn['text'] ?? null)) {
            if (!array_key_exists($paraIndex, $turn['text'])) {
                throw new InvalidArgumentException("patch[{$i}]: нет абзаца {$paraIndex} в реплике");
            }
            $turn['text'][$paraIndex] = $value;
        } else {
            throw new InvalidArgumentException("patch[{$i}]: реплика — строка, para_index не применим");
        }
    }
}
