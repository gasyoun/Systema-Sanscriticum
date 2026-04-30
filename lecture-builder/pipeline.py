"""
pipeline.py — функции preprocess и render для микросервиса lecture-builder.

Переиспользует логику из ../lecture-ui/:
  - makejson2  — парсинг размеченного TXT в JSON
  - build      — рендер JSON в HTML через Jinja2
  - pymupdf    — конвертация PDF слайдов в JPG

Все функции работают с абсолютными путями и не делают предположений
о текущей рабочей директории — это безопасно вызывать из любого процесса.
"""

import json
import sys
from pathlib import Path
from typing import Optional

# Подключаем lecture-ui как библиотеку
LECTURE_UI = Path(__file__).resolve().parent.parent / 'lecture-ui'
if str(LECTURE_UI) not in sys.path:
    sys.path.insert(0, str(LECTURE_UI))

import makejson2  # type: ignore
import build as build_mod  # type: ignore


# ── PDF → JPG ────────────────────────────────────────────────────────────────

def convert_pdf_to_jpgs(
    pdf_path: Path,
    output_dir: Path,
    lesson_number: int,
    dpi: int = 150,
) -> list[str]:
    """
    Конвертирует страницы PDF в JPG с именами XX_NN.jpg, где XX — lesson_number.
    Возвращает список созданных filename (без пути).
    """
    import fitz  # pymupdf

    output_dir.mkdir(parents=True, exist_ok=True)
    zoom = dpi / 72
    matrix = fitz.Matrix(zoom, zoom)

    prefix = f'{int(lesson_number):02d}'
    created: list[str] = []

    doc = fitz.open(str(pdf_path))
    try:
        for i, page in enumerate(doc, start=1):
            pix = page.get_pixmap(matrix=matrix)
            filename = f'{prefix}_{i:02d}.jpg'
            pix.save(str(output_dir / filename))
            created.append(filename)
    finally:
        doc.close()

    return created


# ── Deepgram JSON → структурированный JSON лекции ───────────────────────────

def _looks_like_deepgram(data: dict) -> bool:
    """Грубая эвристика: распознаём ответ Deepgram по корневым полям."""
    if not isinstance(data, dict):
        return False
    if 'results' in data and isinstance(data['results'], dict):
        ch = data['results'].get('channels')
        if isinstance(ch, list) and ch and isinstance(ch[0].get('alternatives'), list):
            return True
    return False


def deepgram_to_lecture_json(data: dict, role: str = 'lecturer') -> dict:
    """
    Конвертер Deepgram → lecture.json (минимальный, без авторазбиения на разделы).

    Стратегия:
      - Берём alternatives[0].paragraphs.paragraphs[]
      - Каждый Deepgram-параграф = один speech-блок с одним абзацем
        (sentences объединяются в один текст для простоты UX редактора)
      - Всё это уезжает в одну секцию "Лекция" (level=2, id=s1)

    Редактор потом сам разобьёт на разделы через inline-правки заголовков.
    """
    alt = data['results']['channels'][0]['alternatives'][0]
    paras = (alt.get('paragraphs') or {}).get('paragraphs') or []

    content: list[dict] = []
    for p in paras:
        sentences = p.get('sentences') or []
        if not sentences:
            continue
        text = ' '.join(s.get('text', '').strip() for s in sentences if s.get('text'))
        if not text.strip():
            continue
        start = p.get('start') or sentences[0].get('start') or 0
        content.append({
            'type': 'speech',
            'role': role,
            'paragraphs': [
                {'t': int(start), 'text': text}
            ],
        })

    if not content:
        # Fallback: один блок с полным транскриптом (без таймкодов)
        transcript = (alt.get('transcript') or '').strip()
        if transcript:
            content.append({
                'type': 'speech',
                'role': role,
                'paragraphs': [{'t': 0, 'text': transcript}],
            })

    sections = [{
        'level': 2,
        'title': 'Лекция',
        'id': 's1',
        'timecodes': {'yt': 0, 'rt': 0, 'au': 0},
        'content': content,
    }]

    return {'meta': default_meta(), 'sections': sections}


# ── Мета: безопасные дефолты ─────────────────────────────────────────────────

def default_meta() -> dict:
    """Все поля meta, которые шаблон ожидает увидеть. Без них JS ломается."""
    return {
        'course': '',
        'lesson_title': '',
        'title': '',
        'lecturer': '',
        'host': '',
        'organization': '',
        'period': '',
        'date_display': '',
        'lesson_number': 0,
        'video': {'youtube': '', 'rutube': ''},
        'yt_offset': 0,
        'rt_offset': 0,
        'au_offset': 0,
    }


def merge_meta(existing: dict, override: dict | None) -> dict:
    """
    Глубокий merge: дефолты ← existing (что было в JSON) ← override (форма Laravel).
    Видеоблок (вложенный объект) тоже мержим, а не перезаписываем.
    """
    base = default_meta()
    base.update(existing or {})
    if not override:
        return base

    # Видео-вложенность — в override это может быть {video: {youtube, rutube}}
    over_video = override.get('video') if isinstance(override.get('video'), dict) else None
    for k, v in override.items():
        if v is None or k == 'video':
            continue
        base[k] = v
    if over_video:
        base.setdefault('video', {})
        for k, v in over_video.items():
            if v:
                base['video'][k] = v
    return base


# ── Распределение слайдов по секциям ─────────────────────────────────────────

def distribute_slides_into_lecture(lecture: dict, slides: list[str]) -> None:
    """
    Грубо распределяет слайды равномерно между speech-блоками первой секции.
    После инжекта слайды можно переставлять вручную через inline-редактор.

    Меняет lecture in-place.
    """
    if not slides or not lecture.get('sections'):
        return

    section = lecture['sections'][0]
    speeches = [i for i, b in enumerate(section.get('content', [])) if b.get('type') == 'speech']
    if not speeches:
        return

    n_slides = len(slides)
    n_speeches = len(speeches)
    # Берём шаг и вставляем перед каждым speeches[i*step]
    figures: list[tuple[int, dict]] = []
    for slide_i, fname in enumerate(slides):
        # Целевой индекс speech-блока (равномерно)
        target_speech = int(round(slide_i * n_speeches / max(n_slides, 1)))
        target_speech = min(target_speech, n_speeches - 1)
        insert_before = speeches[target_speech]
        figures.append((insert_before, {
            'type': 'figure',
            'src': f'./src/img/{fname}',
            'alt': '',
            'caption': '',
        }))

    # Вставляем с конца, чтобы индексы не сдвигались
    for insert_pos, fig_block in sorted(figures, key=lambda x: -x[0]):
        section['content'].insert(insert_pos, fig_block)


# ── TXT/JSON транскрипт → структурированный JSON лекции ──────────────────────

def transcript_text_to_lecture_json(text: str, meta_override: Optional[dict] = None) -> dict:
    """
    Парсит размеченный TXT (с <!--META--> и [Лектор]/<h2> разметкой) в lecture JSON.
    Если передан meta_override — её поля побеждают над разобранным META-блоком.
    """
    import re as _re

    meta = makejson2.parse_meta(text)
    if meta_override:
        meta.update({k: v for k, v in meta_override.items() if v is not None})

    text_no_meta = _re.sub(r'<!--\s*META.*?-->', '', text, flags=_re.DOTALL).strip()
    body, glossary_raw = makejson2.split_glossary(text_no_meta)

    glossary_header, glossary_terms = makejson2.split_glossary_header(glossary_raw or '')
    tag_labels = makejson2.parse_tag_labels(glossary_header)

    lesson_n = int(meta.get('lesson_number', 0)) if meta else 0
    yt_off = int(meta.get('yt_offset', 0)) if meta else 0
    rt_off = int(meta.get('rt_offset', 0)) if meta else 0
    au_off = int(meta.get('au_offset', 0)) if meta else 0

    tokens = makejson2.tokenize(body, lesson_n)
    sections = makejson2.build_sections(tokens, yt_off, rt_off, au_off)

    result: dict = {'sections': sections}
    if meta:
        result = {'meta': makejson2.build_meta_block(meta), **result}

    if glossary_raw:
        glossary = makejson2.parse_glossary_block(glossary_terms, tag_labels)
        if glossary:
            result['glossary'] = glossary

    return result


def transcript_file_to_lecture_json(transcript_path: Path, meta_override: Optional[dict] = None) -> dict:
    """Удобная обёртка: читает файл транскрипта (.txt) и возвращает lecture dict."""
    text = transcript_path.read_text(encoding='utf-8')
    return transcript_text_to_lecture_json(text, meta_override=meta_override)


# ── Полный preprocess (PDF + транскрипт → файлы в working_dir) ───────────────

def preprocess(
    working_dir: Path,
    raw_pdf: Optional[Path],
    raw_transcript: Path,
    lesson_number: int,
    meta_override: Optional[dict] = None,
) -> dict:
    """
    Основная функция препроцессинга:
      - PDF → working_dir/slides/XX_NN.jpg
      - transcript.txt → working_dir/data.json

    Возвращает {data_json: 'data.json', slides: [filename, ...]}.
    Все пути в ответе — относительные относительно working_dir.
    """
    working_dir.mkdir(parents=True, exist_ok=True)

    slides: list[str] = []
    if raw_pdf is not None and raw_pdf.exists():
        slides_dir = working_dir / 'slides'
        slides = convert_pdf_to_jpgs(raw_pdf, slides_dir, lesson_number)

    if raw_transcript.suffix.lower() == '.json':
        raw = json.loads(raw_transcript.read_text(encoding='utf-8'))
        # Распаковка обёртки n8n / Make / Zapier: [{"json": {...}}]
        if isinstance(raw, list) and raw and isinstance(raw[0], dict) and 'json' in raw[0]:
            raw = raw[0]['json']
        if isinstance(raw, dict) and 'sections' in raw:
            lecture = raw
        elif _looks_like_deepgram(raw):
            lecture = deepgram_to_lecture_json(raw)
        else:
            raise ValueError(
                'JSON не похож ни на lecture.json (нет sections), ни на Deepgram '
                '(нет results.channels). Поддерживаются: lecture.json, Deepgram JSON, .txt'
            )
    else:
        lecture = transcript_file_to_lecture_json(raw_transcript)

    # Гарантируем все ожидаемые шаблоном поля meta + накладываем override из формы
    lecture['meta'] = merge_meta(lecture.get('meta') or {}, meta_override)

    # Слайды: вставляем figure-блоки в секции, чтобы редактор увидел картинки
    if slides:
        distribute_slides_into_lecture(lecture, slides)

    data_path = working_dir / 'data.json'
    data_path.write_text(
        json.dumps(lecture, ensure_ascii=False, indent=2),
        encoding='utf-8',
    )

    return {
        'data_json': 'data.json',
        'slides': slides,
    }


# ── Render: data.json → HTML ─────────────────────────────────────────────────

def render(
    working_dir: Path,
    data_json: str = 'data.json',
    template_name: str = 'template.html.j2',
) -> dict:
    """
    Рендерит HTML лекции из data.json в working_dir/output/lecture.html.
    Возвращает {output: 'output/lecture.html'}.
    """
    data_path = working_dir / data_json
    if not data_path.exists():
        raise FileNotFoundError(f'Не найден {data_path}')

    tmpl_dir = LECTURE_UI / 'templates'
    env = build_mod.make_env(tmpl_dir)

    # Шаблон использует фильтр seconds_to_ts, которого нет в build.make_env
    # (он живёт в build2.py). Регистрируем поверх — реализация совместима.
    if 'seconds_to_ts' not in env.filters:
        env.filters['seconds_to_ts'] = build_mod.seconds_to_timestamp

    with open(data_path, encoding='utf-8') as f:
        lecture = json.load(f)

    lecture = build_mod.enrich(lecture)
    template = env.get_template(template_name)
    html = template.render(lecture=lecture)

    output_dir = working_dir / 'output'
    output_dir.mkdir(parents=True, exist_ok=True)
    output_path = output_dir / 'lecture.html'
    output_path.write_text(html, encoding='utf-8')

    return {'output': 'output/lecture.html'}
