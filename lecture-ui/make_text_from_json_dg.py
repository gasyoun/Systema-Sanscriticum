#!/usr/bin/env python3
"""
make_text_from_json_dg.py — генерирует структурированный текстовый файл лекции.

Использование:
  python make_text_from_json_dg.py [--para]

Скрипт ищет файлы прямо в папке transcription/ (рядом со скриптом).
Для каждого filename.json ищет пару filename_toc.txt.
Если найден filename_fig.txt — вставляет фигуры после соответствующих параграфов.
Если найден filename_glossary.txt — вклеивает глоссарий в конец .txt.
  Если файл глоссария начинается с маркера <!-- GLOSSARY --> — вставляет как есть.
  Если маркера нет — добавляет его автоматически перед содержимым.
Если оба найдены — создаёт filename.txt в той же папке.
Если filename.txt уже существует — перезаписывает.

Опции:
  --para   Выводить номера параграфов (§N) в тексте, заголовках и фигурах.
           По умолчанию отключено.
"""

import json
import re
import argparse
from pathlib import Path

SPEAKER_LABELS = {0: 'СПИКЕР 00', 1: 'СПИКЕР 01', 2: 'СПИКЕР 02'}


def fmt_time(sec):
    sec = int(sec)
    return f'{sec//3600:02d}:{(sec%3600)//60:02d}:{sec%60:02d}'


def heading_line(level, title, timestamp, para_n, show_para):
    para_str = f' §{para_n}' if show_para else ''
    return f'\n<{level}>{title}</{level}>{para_str} [{timestamp}]\n'


def parse_toc(toc_path):
    toc = []
    pattern = re.compile(r'<(h[123])>(.*?)</\1>\s*§(\d+)')
    with open(toc_path, encoding='utf-8') as f:
        for line in f:
            m = pattern.search(line)
            if m:
                level = m.group(1)
                title = m.group(2).strip()
                para_n = int(m.group(3))
                toc.append((para_n, level, title))
    toc.sort(key=lambda x: x[0])
    return toc


def parse_fig(fig_path):
    """Парсит файл с фигурами вида: §N <figure>...</figure>
    Возвращает dict: {para_n: [figure_html, ...]}
    """
    fig_map = {}
    pattern = re.compile(r'^§(\d+)\s+(<figure>.*</figure>)\s*$')
    with open(fig_path, encoding='utf-8') as f:
        for line in f:
            if not line.lstrip().startswith('§'):
                continue
            m = pattern.search(line.strip())
            if m:
                para_n = int(m.group(1))
                figure = m.group(2)
                fig_map.setdefault(para_n, []).append(figure)
    return fig_map


def read_glossary_block(glossary_path):
    """Читает filename_glossary.txt и возвращает файл целиком.
    Если файл не найден — возвращает None.
    """
    if not glossary_path or not glossary_path.exists():
        return None
    text = glossary_path.read_text(encoding='utf-8').strip()
    return text if text else None


def process(json_path, toc_path, fig_path, glossary_path, out_path, show_para):
    with open(json_path, encoding='utf-8') as f:
        data = json.load(f)

    # Поддержка двух форматов Deepgram: список [{...}] и словарь {...}
    root = data[0] if isinstance(data, list) else data
    paras = root['results']['channels'][0]['alternatives'][0]['paragraphs']['paragraphs']
    print(f'  Параграфов: {len(paras)}')

    toc = parse_toc(toc_path)
    print(f'  Заголовков: {len(toc)}')

    fig_map = {}
    if fig_path and fig_path.exists():
        fig_map = parse_fig(fig_path)
        print(f'  Фигур: {sum(len(v) for v in fig_map.values())}')

    glossary_block = read_glossary_block(glossary_path)
    if glossary_block:
        print(f'  Глоссарий: найден ({glossary_path.name})')
        if not show_para:
            print(f'  Глоссарий: §N включены автоматически')
            show_para = True
    else:
        print(f'  Глоссарий: не найден, пропуск')

    for para_n, level, title in toc:
        if para_n >= len(paras):
            print(f'  ОШИБКА: §{para_n} не существует (всего параграфов: {len(paras)})')

    toc_map = {}
    for para_n, level, title in toc:
        toc_map.setdefault(para_n, []).append((level, title))

    lines = []
    prev_speaker = None

    for i, para in enumerate(paras):
        # Заголовки — ДО параграфа
        if i in toc_map:
            for level, title in toc_map[i]:
                if level == 'h1':
                    continue
                lines.append(heading_line(level, title, fmt_time(para['start']), i, show_para))
            prev_speaker = None

        speaker = para['speaker']
        spk_label = SPEAKER_LABELS.get(speaker, f'СПИКЕР {speaker:02d}')

        if speaker != prev_speaker:
            lines.append(f'\n[{spk_label}]\n')
            prev_speaker = speaker

        text = ' '.join(
            s['text'].strip()
            for s in para['sentences']
            if s.get('text', '').strip()
        )
        if text:
            para_str = f'§{i} ' if show_para else ''
            lines.append(f'{para_str}[{fmt_time(para["start"])}] {text}\n')

        # Фигуры — ПОСЛЕ параграфа, без номера §N
        if i in fig_map:
            for figure in fig_map[i]:
                lines.append(f'{figure}\n')

    # Глоссарий — в самом конце файла
    # Маркер <!-- GLOSSARY --> добавляем только если его нет в начале файла глоссария
    if glossary_block:
        if not re.match(r'<!--\s*GLOSSARY\s*-->', glossary_block):
            lines.append('\n<!-- GLOSSARY -->\n')
        else:
            lines.append('\n')
        lines.append(glossary_block)
        lines.append('\n')

    out = ''.join(lines)
    out_path.write_text(out, encoding='utf-8')
    print(f'  ✓ Сохранено: {out_path.name} ({len(out):,} символов)')


def main():
    parser = argparse.ArgumentParser(
        description='Генерирует текст лекции из пар filename.json + filename_toc.txt'
    )
    parser.add_argument(
        '--para', action='store_true', default=False,
        help='Выводить номера параграфов (§N) в тексте, заголовках и фигурах'
    )
    args = parser.parse_args()

    folder = Path(__file__).parent / 'transcription'
    if not folder.is_dir():
        print(f'ОШИБКА: папка не найдена: {folder}')
        return

    found = 0

    for json_path in sorted(folder.glob('*.json')):
        stem = json_path.stem
        toc_path      = folder / f'{stem}_toc.txt'
        fig_path      = folder / f'{stem}_fig.txt'
        glossary_path = folder / f'{stem}_glossary.txt'
        out_path      = folder / f'{stem}.txt'

        if not toc_path.exists():
            print(f'[ ] {stem}.json — нет {stem}_toc.txt, пропуск')
            continue

        if not fig_path.exists():
            print(f'[~] {stem} — нет {stem}_fig.txt, фигуры пропущены')
            fig_path = None

        print(f'[+] {stem}')
        process(json_path, toc_path, fig_path, glossary_path, out_path, args.para)
        found += 1

    print(f'\nГотово: обработано {found}')


if __name__ == '__main__':
    main()
