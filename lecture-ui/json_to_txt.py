#!/usr/bin/env python3
"""
Обратный конвертер: JSON → TXT/MD (формат для makejson.py).

Правила восстановления:
  type=text         → [Лекция] + абзацы
  type=interjection → [Реплика] + текст (роль не выводится отдельно)
  type=dialog       → [Роль] для каждого turn + текст
  type=figure       → <figure><img N><figcaption>...</figcaption></figure>

Роль не печатается повторно, если она та же самая, что и в предыдущем блоке
(за исключением смены режима лекция/диалог).

Таймкоды: используется yt-значение минус yt_offset → raw секунды.
"""

import json
import re
import sys
from pathlib import Path


# ---------------------------------------------------------------------------
# Вспомогательные функции
# ---------------------------------------------------------------------------

def role_to_marker(role: str) -> str:
    """Роль из JSON → метка [Роль] для .txt."""
    if role == 'host':
        return '[Ведущий]'
    if role == 'lecturer':
        return '[Лектор]'
    return f'[{role}]'


def extract_img_number(src: str) -> int:
    """./src/img/00_03.jpg → 3"""
    m = re.search(r'_(\d+)\.', src)
    return int(m.group(1)) if m else 0


def text_as_list(text) -> list:
    """Нормализует поле text: всегда список строк."""
    if isinstance(text, list):
        return text
    return [text]


# ---------------------------------------------------------------------------
# Восстановление META-блока
# ---------------------------------------------------------------------------

def build_meta(meta: dict, yt_offset: int, rt_offset: int, au_offset: int) -> str:
    lines = ['<!--', 'META']
    fields = [
        ('course',         meta.get('course', '')),
        ('lesson_title',   meta.get('lesson_title', '')),
        ('title',          meta.get('title', '')),
        ('lecturer',       meta.get('lecturer', '')),
        ('lesson_number',  str(meta.get('lesson_number', 0))),
        ('organization',   meta.get('organization', '')),
        ('period',         meta.get('period', '')),
        ('date_display',   meta.get('date_display', '')),
        ('host',           meta.get('host', '')),
    ]
    video = meta.get('video', {})
    if 'youtube' in video:
        fields.append(('youtube', video['youtube']))
    if 'rutube' in video:
        fields.append(('rutube', video['rutube']))
    fields.append(('yt_offset', str(yt_offset)))
    fields.append(('rt_offset', str(rt_offset)))
    fields.append(('au_offset', str(au_offset)))

    max_key = max(len(k) for k, _ in fields)
    for key, val in fields:
        lines.append(f'{key}:{" " * (max_key - len(key) + 1)}{val}')

    lines.append('-->')
    return '\n'.join(lines)


# ---------------------------------------------------------------------------
# Основной конвертер
# ---------------------------------------------------------------------------

def convert(input_path: str, output: str = None):
    data = json.loads(Path(input_path).read_text(encoding='utf-8'))
    meta = data['meta']
    sections = data['sections']

    out_lines: list[str] = []

    # META
    out_lines.append(build_meta(meta, 0, 0, 0))
    out_lines.append('')

    # Отслеживаем «текущий» режим и роль, чтобы не дублировать маркеры
    prev_mode = None   # 'text' | 'interjection' | 'dialog'
    prev_role = None   # строка-роль из JSON

    for sec in sections:
        level = sec.get('level', 2)
        title = sec.get('title', '')
        timecodes = sec.get('timecodes', {})
        content = sec.get('content', [])

        # Заголовок
        out_lines.append(f'<h{level}>{title}</h{level}>')
        out_lines.append('')

        # Таймкод берём напрямую из yt
        yt_val = timecodes.get('yt', 0)
        out_lines.append(f'<time>{yt_val}</time>')
        out_lines.append('')

        for block in content:
            btype = block.get('type')

            # ── figure ──────────────────────────────────────────────────
            if btype == 'figure':
                n = extract_img_number(block.get('src', ''))
                caption = block.get('caption', block.get('alt', ''))
                out_lines.append(f'<figure><img {n}><figcaption>{caption}</figcaption></figure>')
                out_lines.append('')
                # режим не меняется

            # ── text (лекция) ────────────────────────────────────────────
            elif btype == 'text':
                role = block.get('role', 'lecturer')
                # Нужен маркер [Лекция], если это лектор,
                # или маркер [Роль] если другой (редко, но предусмотрим)
                need_marker = (prev_mode != 'text') or (prev_role != role)
                if need_marker:
                    if role == 'lecturer':
                        out_lines.append('[Лекция]')
                    else:
                        out_lines.append(role_to_marker(role))
                    out_lines.append('')
                prev_mode = 'text'
                prev_role = role
                for para in block.get('paragraphs', []):
                    out_lines.append(para)
                    out_lines.append('')

            # ── interjection (реплика) ───────────────────────────────────
            elif btype == 'interjection':
                speaker = block.get('speaker', 'host')
                need_marker = (prev_mode != 'interjection') or (prev_role != speaker)
                if need_marker:
                    out_lines.append('[Реплика]')
                    out_lines.append('')
                prev_mode = 'interjection'
                prev_role = speaker
                text = block.get('text', '')
                out_lines.append(text)
                out_lines.append('')

            # ── dialog ──────────────────────────────────────────────────
            elif btype == 'dialog':
                turns = block.get('turns', [])
                for turn in turns:
                    role = turn.get('role', '')
                    marker = role_to_marker(role)
                    need_marker = (prev_mode != 'dialog') or (prev_role != role)
                    if need_marker:
                        out_lines.append(marker)
                        out_lines.append('')
                    prev_mode = 'dialog'
                    prev_role = role
                    for line in text_as_list(turn.get('text', '')):
                        out_lines.append(line)
                    out_lines.append('')

    # Убираем лишние пустые строки в конце
    while out_lines and out_lines[-1] == '':
        out_lines.pop()

    result = '\n'.join(out_lines) + '\n'

    stem = Path(input_path).stem
    if output:
        out_path = Path(output)
    else:
        out_path = Path(input_path).parent / f'{stem}_restored.txt'
    out_path.write_text(result, encoding='utf-8')
    print(f'Сохранено: {out_path}')


# ---------------------------------------------------------------------------
if __name__ == '__main__':
    import argparse
    parser = argparse.ArgumentParser(
        description='Конвертирует JSON стенограммы обратно в текстовый формат (.txt).'
    )
    parser.add_argument('input', help='Входной JSON-файл')
    parser.add_argument('-o', '--output', default=None,
                        help='Путь для выходного файла (default: ~/имя_restored.txt)')
    args = parser.parse_args()
    convert(args.input, output=args.output)
