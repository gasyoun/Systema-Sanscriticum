"""
ai.py — обёртка над Anthropic Claude API для задач обработки лекций.

Четыре задачи (из lecture-ui/readme.txt):
  1) structure_sections   — генерация заголовков H2/H3 + расстановка таймкодов
  2) correct_transcript   — корректура стенограммы (артефакты Whisper, опечатки)
  3) place_slides         — расстановка слайдов в нужные моменты доклада
  4) verify_timecodes     — сверка таймкодов с субтитрами YouTube (yt-dlp)

Контракт каждой функции:
  - на вход: lecture dict (как в data.json) + опц. user_hint (уточнение от редактора)
  - на выход: dict с ключами {ok, lecture, summary, error?}
  - lecture mutation in-memory (не пишем на диск — это делает server.py)

API-ключ: переменная окружения ANTHROPIC_API_KEY.
Загружается из lecture-builder/.env (опционально), либо берётся из process env.

Модель: по умолчанию claude-sonnet-4-6 (баланс цена/качество).
Можно переопределить через ANTHROPIC_MODEL или параметр model=.
"""

from __future__ import annotations

import json
import logging
import os
import re
from pathlib import Path
from typing import Any, Optional

try:
    from dotenv import load_dotenv
    load_dotenv(dotenv_path=Path(__file__).parent / '.env')
except ImportError:
    pass

import anthropic

log = logging.getLogger('lecture-builder.ai')

DEFAULT_MODEL = os.environ.get('ANTHROPIC_MODEL', 'claude-sonnet-4-6')
MAX_TOKENS = int(os.environ.get('ANTHROPIC_MAX_TOKENS', '16000'))


def _client() -> anthropic.Anthropic:
    key = os.environ.get('ANTHROPIC_API_KEY')
    if not key:
        raise RuntimeError(
            'ANTHROPIC_API_KEY не задан. Положите ключ в lecture-builder/.env '
            'или установите переменную окружения.'
        )
    return anthropic.Anthropic(api_key=key)


# ── Утилиты ─────────────────────────────────────────────────────────────────

def _extract_json(text: str) -> Any:
    """
    Извлекает JSON из ответа модели. Допускает обёртки ```json ... ``` и текст вокруг.
    """
    # 1) Прямой парс
    s = text.strip()
    try:
        return json.loads(s)
    except json.JSONDecodeError:
        pass

    # 2) Внутри markdown code-fence
    fence = re.search(r'```(?:json)?\s*([\s\S]+?)\s*```', s)
    if fence:
        try:
            return json.loads(fence.group(1))
        except json.JSONDecodeError:
            pass

    # 3) Первый «{» до последнего «}»
    start = s.find('{')
    end = s.rfind('}')
    if start != -1 and end > start:
        try:
            return json.loads(s[start:end + 1])
        except json.JSONDecodeError:
            pass

    raise ValueError('Не удалось извлечь JSON из ответа модели:\n' + s[:500])


def _flatten_speech(lecture: dict) -> list[dict]:
    """
    Сплющивает все speech-абзацы в линейный список с координатами.
    Удобно для промптов: один абзац = одна строка с таймкодом и индексами.
    """
    items: list[dict] = []
    for s_idx, section in enumerate(lecture.get('sections', [])):
        for b_idx, block in enumerate(section.get('content', [])):
            if block.get('type') != 'speech':
                continue
            for p_idx, para in enumerate(block.get('paragraphs', [])):
                items.append({
                    'section_idx': s_idx,
                    'block_idx': b_idx,
                    'para_idx': p_idx,
                    't': para.get('t') if isinstance(para, dict) else None,
                    'text': para.get('text') if isinstance(para, dict) else str(para),
                })
    return items


def _format_seconds(s: Optional[int]) -> str:
    if s is None:
        return '?:??'
    s = int(s)
    h = s // 3600
    m = (s % 3600) // 60
    sec = s % 60
    return f'{h}:{m:02d}:{sec:02d}' if h else f'{m}:{sec:02d}'


# ── Задача 1: разбиение на H2/H3 разделы ────────────────────────────────────

STRUCTURE_SYSTEM = """Ты — редактор-структуратор расшифровок лекций по индологии и санскриту.

Тебе дают плоский поток абзацев лекции с таймкодами. Твоя задача — предложить
разбиение на разделы (H2) и подразделы (H3), ничего не теряя из исходного текста.

Правила:
- Разделы (H2) — крупные смысловые блоки (вступление, основная часть 1, ..., итоги).
  Обычно 4-12 разделов на лекцию длительностью 1.5-2 часа.
- Подразделы (H3) — внутри H2, по смене подтемы. Не обязательно у каждого H2.
- Заголовки — короткие (3-7 слов), отражают содержание раздела.
- Каждый заголовок ПРИВЯЗАН к индексу абзаца, с которого начинается раздел.
- Не меняй текст абзацев. Только структурируй.

Формат ответа — строго JSON, без преамбул и пояснений вне JSON:
{
  "sections": [
    {"level": 2, "title": "Название раздела", "start_para": 0},
    {"level": 3, "title": "Подраздел", "start_para": 5},
    {"level": 2, "title": "Следующий раздел", "start_para": 23},
    ...
  ]
}

Где start_para — номер первого абзаца раздела из приложенного списка."""


def structure_sections(lecture: dict, user_hint: str = '', model: str = DEFAULT_MODEL) -> dict:
    """
    Разбивает плоскую лекцию на H2/H3 разделы. Возвращает обновлённый lecture.
    """
    items = _flatten_speech(lecture)
    if not items:
        return {'ok': False, 'error': 'Нет speech-абзацев для структурирования'}

    # Готовим промпт: список абзацев с индексом и таймкодом
    lines = []
    for i, it in enumerate(items):
        ts = _format_seconds(it['t'])
        # Сокращаем длинные абзацы для экономии токенов в промпте
        text = it['text'].strip()
        if len(text) > 600:
            text = text[:550] + '…'
        lines.append(f'[{i}] {ts}  {text}')
    paragraphs_block = '\n'.join(lines)

    user_msg = f"""Лекция содержит {len(items)} абзацев. Предложи разбиение на разделы.

{('Дополнительные указания редактора: ' + user_hint) if user_hint.strip() else ''}

Абзацы:
{paragraphs_block}"""

    log.info('AI structure_sections: %d paragraphs, hint=%r', len(items), user_hint)
    resp = _client().messages.create(
        model=model,
        max_tokens=MAX_TOKENS,
        system=STRUCTURE_SYSTEM,
        messages=[{'role': 'user', 'content': user_msg}],
    )
    text = ''.join(b.text for b in resp.content if b.type == 'text')
    parsed = _extract_json(text)

    proposed = parsed.get('sections') or []
    if not proposed:
        return {'ok': False, 'error': 'Модель не вернула sections', 'raw': text}

    new_lecture = _apply_structure(lecture, items, proposed)
    return {
        'ok': True,
        'lecture': new_lecture,
        'summary': f'Создано разделов: {len(proposed)} (H2: {sum(1 for s in proposed if s.get("level") == 2)}, '
                   f'H3: {sum(1 for s in proposed if s.get("level") == 3)})',
        'usage': {'input_tokens': resp.usage.input_tokens, 'output_tokens': resp.usage.output_tokens},
    }


def _apply_structure(lecture: dict, items: list[dict], proposed: list[dict]) -> dict:
    """
    Перестраивает lecture по предложенным разделам.
    Берём figures и speech блоки из исходной первой секции,
    распределяем по новым секциям согласно start_para.
    """
    # Сортируем proposed по start_para
    proposed = sorted(proposed, key=lambda s: int(s.get('start_para', 0)))

    # Все блоки исходной первой секции (speech + figures между ними)
    first_section = lecture['sections'][0] if lecture.get('sections') else {'content': []}
    all_blocks = list(first_section.get('content', []))

    # Карта para_global_idx → block_idx_in_all_blocks
    para_block_map: dict[int, int] = {}
    para_count = 0
    for b_idx, block in enumerate(all_blocks):
        if block.get('type') == 'speech':
            for _ in block.get('paragraphs', []):
                para_block_map[para_count] = b_idx
                para_count += 1

    new_sections: list[dict] = []
    for i, sec in enumerate(proposed):
        start_para = int(sec.get('start_para', 0))
        next_start = int(proposed[i + 1].get('start_para', len(items))) if i + 1 < len(proposed) else len(items)

        # Диапазон блоков для этой секции
        start_block = para_block_map.get(start_para, 0)
        end_block = para_block_map.get(next_start, len(all_blocks)) if next_start < len(items) else len(all_blocks)

        # Подцепляем фигуры, находящиеся в этом диапазоне
        section_content = all_blocks[start_block:end_block]

        # Таймкод секции = таймкод первого абзаца
        t = items[start_para]['t'] if 0 <= start_para < len(items) else 0
        t = int(t or 0)

        new_sections.append({
            'level': int(sec.get('level', 2)),
            'title': str(sec.get('title', f'Раздел {i + 1}')).strip(),
            'id': '',  # проставим ниже
            'timecodes': {'yt': t, 'rt': t, 'au': t},
            'content': section_content,
        })

    # Раздаём id (s1, s1_1, s2, …) — как в makejson2.build_sections
    h2 = 0
    h3 = 0
    for sec in new_sections:
        if sec['level'] == 2:
            h2 += 1
            h3 = 0
            sec['id'] = f's{h2}'
        else:
            h3 += 1
            sec['id'] = f's{h2}_{h3}'

    new_lecture = dict(lecture)
    new_lecture['sections'] = new_sections
    return new_lecture


# ── Задача 2: корректура текста ──────────────────────────────────────────────

CORRECT_SYSTEM = """Ты — корректор расшифровок лекций по индологии и санскриту.

Тебе присылают абзац стенограммы. Твоя задача — мягко исправить:
- Ошибки распознавания речи (Whisper, Deepgram): неверные слова, расщеплённые слова.
- Опечатки и нестандартное написание санскритских терминов (если очевидно из контекста).
- Пунктуацию (если она явно лишняя или отсутствует).

ВАЖНО:
- НЕ перефразируй — сохраняй авторский стиль и порядок слов.
- НЕ удаляй колебания, повторы, междометия лектора, если они являются частью смысла.
- НЕ объединяй и не разделяй абзацы.
- Если изменений нет — верни текст как есть.

Формат ответа — только JSON, без преамбулы:
{"text": "исправленный текст"}"""


def correct_transcript(lecture: dict, user_hint: str = '', model: str = DEFAULT_MODEL,
                       max_paragraphs: int = 0) -> dict:
    """
    Корректирует текст всех speech-абзацев. Каждый абзац — отдельный API-вызов
    (изолированно, чтобы не размывать контекст и избежать перефраза).

    max_paragraphs > 0 — ограничивает число обработанных абзацев (для тестов).
    """
    items = _flatten_speech(lecture)
    if not items:
        return {'ok': False, 'error': 'Нет speech-абзацев для корректуры'}

    if max_paragraphs and max_paragraphs > 0:
        items = items[:max_paragraphs]

    client = _client()
    new_lecture = json.loads(json.dumps(lecture))  # deep copy
    changed = 0
    total_input = 0
    total_output = 0

    hint = user_hint.strip()

    for it in items:
        original = it['text'].strip()
        user_msg = (
            (f'Дополнительно: {hint}\n\n' if hint else '') +
            f'Абзац стенограммы:\n\n{original}'
        )
        try:
            resp = client.messages.create(
                model=model,
                max_tokens=4000,
                system=CORRECT_SYSTEM,
                messages=[{'role': 'user', 'content': user_msg}],
            )
            text = ''.join(b.text for b in resp.content if b.type == 'text')
            parsed = _extract_json(text)
            new_text = (parsed.get('text') or '').strip()
            total_input += resp.usage.input_tokens
            total_output += resp.usage.output_tokens
        except Exception as e:
            log.warning('correct_transcript: skipping para %s: %s', it, e)
            continue

        if not new_text or new_text == original:
            continue

        # Применяем правку в new_lecture
        s, b, p = it['section_idx'], it['block_idx'], it['para_idx']
        para = new_lecture['sections'][s]['content'][b]['paragraphs'][p]
        if isinstance(para, dict):
            para['text'] = new_text
        else:
            new_lecture['sections'][s]['content'][b]['paragraphs'][p] = new_text
        changed += 1

    return {
        'ok': True,
        'lecture': new_lecture,
        'summary': f'Откорректировано абзацев: {changed} из {len(items)}',
        'usage': {'input_tokens': total_input, 'output_tokens': total_output},
    }


# ── Задача 3: расстановка слайдов ────────────────────────────────────────────

SLIDES_SYSTEM = """Ты — ассистент-редактор лекций.

Тебе присылают:
1) Список абзацев лекции (с таймкодом и текстом).
2) Список слайдов (имена файлов в порядке появления в презентации).

Твоя задача — для каждого слайда подобрать подходящий абзац, после которого
этот слайд должен появиться в тексте лекции. Слайды должны идти в исходном
порядке (XX_01, XX_02, ...). Учитывай содержание абзацев — лектор обычно
комментирует слайд непосредственно перед или сразу после его показа.

Формат ответа — только JSON:
{
  "placements": [
    {"slide": "01_01.jpg", "after_para": 0,  "caption": "что изображено"},
    {"slide": "01_02.jpg", "after_para": 4,  "caption": "..."},
    ...
  ]
}

Если для слайда нет очевидного места — поставь после ближайшего по тексту
абзаца, чтобы все слайды распределились. Не пропускай слайды."""


def place_slides(lecture: dict, slide_filenames: list[str], user_hint: str = '',
                 model: str = DEFAULT_MODEL) -> dict:
    """
    Перераспределяет figure-блоки по абзацам на основе анализа текста.
    """
    if not slide_filenames:
        return {'ok': False, 'error': 'Нет слайдов'}

    items = _flatten_speech(lecture)
    if not items:
        return {'ok': False, 'error': 'Нет speech-абзацев'}

    # Промпт: краткие абзацы (для экономии токенов) + список слайдов
    lines = []
    for i, it in enumerate(items):
        ts = _format_seconds(it['t'])
        text = it['text'].strip()
        if len(text) > 350:
            text = text[:300] + '…'
        lines.append(f'[{i}] {ts}  {text}')
    paragraphs_block = '\n'.join(lines)
    slides_block = '\n'.join(f'- {s}' for s in slide_filenames)

    hint_line = (f'Указания редактора: {user_hint.strip()}\n\n' if user_hint.strip() else '')
    user_msg = f"""{hint_line}Расставь {len(slide_filenames)} слайдов по {len(items)} абзацам.

Абзацы:
{paragraphs_block}

Слайды (в исходном порядке, не меняй порядок):
{slides_block}"""

    log.info('AI place_slides: %d slides, %d paragraphs', len(slide_filenames), len(items))
    resp = _client().messages.create(
        model=model,
        max_tokens=MAX_TOKENS,
        system=SLIDES_SYSTEM,
        messages=[{'role': 'user', 'content': user_msg}],
    )
    text = ''.join(b.text for b in resp.content if b.type == 'text')
    parsed = _extract_json(text)
    placements = parsed.get('placements') or []

    new_lecture = _apply_slide_placements(lecture, items, placements)
    return {
        'ok': True,
        'lecture': new_lecture,
        'summary': f'Размещено слайдов: {len(placements)}',
        'usage': {'input_tokens': resp.usage.input_tokens, 'output_tokens': resp.usage.output_tokens},
    }


def _apply_slide_placements(lecture: dict, items: list[dict], placements: list[dict]) -> dict:
    """
    Удаляет все существующие figure-блоки и вставляет новые согласно placements.
    Slide path формируется относительно ./src/img/ как и было раньше.
    """
    new_lecture = json.loads(json.dumps(lecture))

    # 1) Снимаем все figure-блоки из всех секций
    for sec in new_lecture.get('sections', []):
        sec['content'] = [b for b in sec.get('content', []) if b.get('type') != 'figure']

    # 2) Группируем placements по абзацу (после которого вставлять)
    by_para: dict[int, list[dict]] = {}
    for p in placements:
        para_n = int(p.get('after_para', 0))
        by_para.setdefault(para_n, []).append(p)

    # 3) Идём по plain-абзацам (как в _flatten_speech) и вставляем фигуры после
    para_count = 0
    for sec in new_lecture['sections']:
        new_content: list[dict] = []
        for block in sec['content']:
            new_content.append(block)
            if block.get('type') != 'speech':
                continue
            for _ in block.get('paragraphs', []):
                # Если для этого абзаца есть фигуры — добавляем сразу
                if para_count in by_para:
                    for fig in by_para[para_count]:
                        new_content.append({
                            'type': 'figure',
                            'src': f'./src/img/{fig["slide"]}',
                            'alt': str(fig.get('caption', '') or ''),
                            'caption': str(fig.get('caption', '') or ''),
                        })
                para_count += 1
        sec['content'] = new_content

    return new_lecture


# ── Задача 4: сверка таймкодов с YouTube-субтитрами ─────────────────────────

TIMECODES_SYSTEM = """Ты — ассистент-редактор лекций.

Тебе присылают:
1) Заголовки разделов лекции с предполагаемыми таймкодами (в секундах).
2) Полный поток субтитров YouTube с реальными таймкодами.

Твоя задача — для каждого заголовка найти ТОЧНЫЙ таймкод начала по субтитрам,
ориентируясь на содержание заголовка и контекст в субтитрах.

Формат ответа — только JSON:
{
  "timecodes": [
    {"section_id": "s1", "title": "...", "yt": 0,    "rt": 0,    "au": 0},
    {"section_id": "s2", "title": "...", "yt": 412,  "rt": 412,  "au": 412},
    ...
  ]
}

Сейчас не учитывай оффсеты между YT/RT/audio — ставь одно и то же число во все три."""


def verify_timecodes(lecture: dict, yt_url: str, user_hint: str = '',
                     model: str = DEFAULT_MODEL) -> dict:
    """
    Сверяет таймкоды заголовков с реальными субтитрами YouTube.
    """
    if not yt_url:
        return {'ok': False, 'error': 'Нет URL YouTube'}

    try:
        subs_text = _fetch_yt_subtitles(yt_url)
    except Exception as e:
        return {'ok': False, 'error': f'Не удалось получить субтитры YouTube: {e}'}

    if not subs_text:
        return {'ok': False, 'error': 'Субтитры пустые'}

    headings = [
        {
            'section_id': s.get('id') or '',
            'title': s.get('title') or '',
            'level': s.get('level', 2),
            'current_t': s.get('timecodes', {}).get('yt', 0),
        }
        for s in lecture.get('sections', [])
    ]

    headings_block = '\n'.join(
        f'- [{h["section_id"]}] H{h["level"]} «{h["title"]}» (текущий: {_format_seconds(h["current_t"])})'
        for h in headings
    )
    hint_line = (f'Указания редактора: {user_hint.strip()}\n\n' if user_hint.strip() else '')
    user_msg = f"""{hint_line}Заголовки лекции:
{headings_block}

Субтитры YouTube (формат: [время] текст):
{subs_text[:60000]}"""  # обрезаем на всякий случай

    log.info('AI verify_timecodes: %d headings, subs=%d chars', len(headings), len(subs_text))
    resp = _client().messages.create(
        model=model,
        max_tokens=8000,
        system=TIMECODES_SYSTEM,
        messages=[{'role': 'user', 'content': user_msg}],
    )
    text = ''.join(b.text for b in resp.content if b.type == 'text')
    parsed = _extract_json(text)
    timecodes = parsed.get('timecodes') or []

    new_lecture = json.loads(json.dumps(lecture))
    by_id = {tc.get('section_id'): tc for tc in timecodes}
    updated = 0
    for sec in new_lecture.get('sections', []):
        tc = by_id.get(sec.get('id'))
        if not tc:
            continue
        sec['timecodes'] = {
            'yt': int(tc.get('yt', 0)),
            'rt': int(tc.get('rt', tc.get('yt', 0))),
            'au': int(tc.get('au', tc.get('yt', 0))),
        }
        updated += 1

    return {
        'ok': True,
        'lecture': new_lecture,
        'summary': f'Обновлено таймкодов: {updated} из {len(headings)}',
        'usage': {'input_tokens': resp.usage.input_tokens, 'output_tokens': resp.usage.output_tokens},
    }


def _fetch_yt_subtitles(yt_url: str) -> str:
    """Скачивает русские субтитры YouTube через yt-dlp и возвращает плоский текст с таймкодами."""
    import tempfile
    import subprocess

    with tempfile.TemporaryDirectory() as tmp:
        outtpl = os.path.join(tmp, 'subs.%(ext)s')
        cmd = [
            'yt-dlp',
            '--skip-download',
            '--write-auto-subs',
            '--write-subs',
            '--sub-langs', 'ru,en',
            '--sub-format', 'vtt',
            '-o', outtpl,
            yt_url,
        ]
        result = subprocess.run(cmd, capture_output=True, text=True, timeout=120)
        if result.returncode != 0:
            raise RuntimeError(f'yt-dlp вернул {result.returncode}: {result.stderr[-500:]}')

        # Ищем любой .vtt файл
        vtt = next((p for p in Path(tmp).glob('*.vtt')), None)
        if not vtt:
            raise RuntimeError('yt-dlp не сохранил VTT')

        return _vtt_to_plain(vtt.read_text(encoding='utf-8'))


def _vtt_to_plain(vtt: str) -> str:
    """Превращает VTT в плоский текст вида '[m:ss] текст\\n…'."""
    lines = []
    cur_t: Optional[int] = None
    cur_text: list[str] = []

    def flush():
        if cur_t is not None and cur_text:
            txt = ' '.join(t.strip() for t in cur_text if t.strip())
            if txt:
                lines.append(f'[{_format_seconds(cur_t)}] {txt}')

    for line in vtt.splitlines():
        line = line.strip()
        if not line or line.startswith(('WEBVTT', 'NOTE', 'Kind:', 'Language:')):
            continue
        m = re.match(r'(\d+):(\d+):(\d+)[\.,]\d+\s+-->', line)
        if m:
            flush()
            cur_t = int(m.group(1)) * 3600 + int(m.group(2)) * 60 + int(m.group(3))
            cur_text = []
            continue
        # Отбрасываем теги вроде <c>, <00:00:01.000>
        cleaned = re.sub(r'<[^>]+>', '', line)
        cur_text.append(cleaned)

    flush()
    return '\n'.join(lines)
