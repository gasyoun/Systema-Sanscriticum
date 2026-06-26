"""
Регрессия рендера template.html.j2: абзацы, созданные in-browser редактором
лекций (split_para / add-block), не имеют ключа `t` — шаблон должен рендерить
их без таймкода и без ошибки. До фикса `{% if para.t is not none %}` падал на
Undefined ('dict object' has no attribute 't').

Запуск: cd lecture-ui && python -m pytest tests/
"""
from pathlib import Path

from jinja2 import Environment, FileSystemLoader, select_autoescape

TMPL_DIR = Path(__file__).resolve().parent.parent / "templates"


def _env() -> Environment:
    env = Environment(
        loader=FileSystemLoader(str(TMPL_DIR)),
        autoescape=select_autoescape(["html"]),
        trim_blocks=True,
        lstrip_blocks=True,
    )
    # Стаб-фильтры (реальные — в build2.py); достаточно для рендера шаблона.
    env.filters["upper_role"] = lambda r: str(r).upper() if r else ""
    env.filters["seconds_to_ts"] = lambda s: f"{int(s) // 60}:{int(s) % 60:02d}"
    env.filters["yt_id"] = lambda *a: ""
    env.filters["rt_id"] = lambda *a: ""
    env.filters["lesson_audio"] = lambda *a: ""
    return env


def _lecture(paragraphs):
    return {
        "meta": {
            "title": "Лекция", "lecturer": "Лектор", "host": "Ведущий",
            "date_display": "1 января 2026", "period": "2026",
            "organization": "ОРС", "lesson_number": 1,
            "video": {"youtube": "", "rutube": ""},
        },
        "sections": [{
            "id": "s1", "level": 2, "title": "Раздел 1", "timecodes": {},
            "content": [{"type": "speech", "role": "host", "paragraphs": paragraphs}],
        }],
        "glossary": [],
    }


def _render(paragraphs) -> str:
    return _env().get_template("template.html.j2").render(lecture=_lecture(paragraphs))


def test_editor_created_paragraph_without_timecode_renders():
    html = _render([
        {"text": "С таймкодом", "t": 65, "dg_ref": 0},  # из makejson2
        {"text": "Создан в редакторе, без ключа t"},     # split_para / add-block
    ])

    # Оба абзаца на месте, таймкод-ссылка только у первого, без падения рендера.
    assert "С таймкодом" in html
    assert "Создан в редакторе, без ключа t" in html
    assert html.count('class="para-timecode"') == 1


def test_block_of_only_editor_paragraphs_renders():
    # Целиком новый speech-блок (add-block) без таймкодов вообще.
    html = _render([{"text": "Новый абзац один"}, {"text": "Новый абзац два"}])

    assert "Новый абзац один" in html
    assert "Новый абзац два" in html
    assert html.count('class="para-timecode"') == 0
