#!/usr/bin/env python3
"""
build.py — сборщик HTML-лекций из JSON + Jinja2-шаблон.

Использование:
  python build.py                  # собрать все лекции из data/
  python build.py data/00.json     # собрать одну лекцию
  python build.py --template other.html.j2 data/00.json  # другой шаблон
"""

import json
import argparse
from pathlib import Path
from jinja2 import Environment, FileSystemLoader, select_autoescape


# ── Пути по умолчанию ────────────────────────────────────────────────────────

ROOT        = Path(__file__).parent
DATA_DIR    = ROOT / "data"
OUTPUT_DIR  = ROOT / "output"
TMPL_DIR    = ROOT / "templates"
TMPL_NAME   = "template.html.j2"


# ── Вспомогательные функции ───────────────────────────────────────────────────

def seconds_to_timestamp(seconds: int) -> str:
    """Конвертирует секунды в строку 'M:SS' или 'H:MM:SS'."""
    h = seconds // 3600
    m = (seconds % 3600) // 60
    s = seconds % 60
    if h:
        return f"{h}:{m:02d}:{s:02d}"
    return f"{m}:{s:02d}"


def build_yt_url(base_url: str, t: int) -> str:
    sep = "&" if "?" in base_url else "?"
    return f"{base_url}{sep}t={t}"


def build_rt_url(base_url: str, t: int) -> str:
    sep = "&" if "?" in base_url else "?"
    return f"{base_url}{sep}t={t}"


def enrich(lecture: dict) -> dict:
    """
    Добавляет производные поля в данные лекции перед рендером:
    - метки времени в читаемом виде
    - готовые ссылки для каждой секции
    - порядковые номера слайдов (figure-блоков)
    """
    video = lecture.get("meta", {}).get("video", {})
    yt_base = video.get("youtube", "")
    rt_base = video.get("rutube", "")

    slide_number = 0
    for section in lecture.get("sections", []):
        tc = section.get("timecodes", {})
        yt_t = tc.get("yt")
        rt_t = tc.get("rt")

        if yt_t is not None:
            section["_yt_url"]  = build_yt_url(yt_base, yt_t)
            section["_yt_time"] = seconds_to_timestamp(yt_t)
        if rt_t is not None:
            section["_rt_url"]  = build_rt_url(rt_base, rt_t)
            section["_rt_time"] = seconds_to_timestamp(rt_t)

        for block in section.get("content", []):
            if block.get("type") == "figure":
                slide_number += 1
                block["_slide_number"] = slide_number

    return lecture


def render(lecture_path: Path, env: Environment, template_name: str) -> str:
    """Загружает JSON, обогащает данные и рендерит шаблон."""
    with open(lecture_path, encoding="utf-8") as f:
        lecture = json.load(f)

    lecture = enrich(lecture)
    template = env.get_template(template_name)
    return template.render(lecture=lecture)


def build_one(lecture_path: Path, env: Environment, template_name: str):
    """Собирает одну лекцию и сохраняет HTML."""
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    html = render(lecture_path, env, template_name)
    out_path = OUTPUT_DIR / lecture_path.with_suffix(".html").name
    out_path.write_text(html, encoding="utf-8")
    print(f"  ✓  {lecture_path.name}  →  {out_path}")


def build_all(env: Environment, template_name: str):
    """Собирает все *.json из data/."""
    files = sorted(DATA_DIR.glob("*.json"))
    if not files:
        print("Нет JSON-файлов в data/")
        return
    for f in files:
        build_one(f, env, template_name)


# ── Точка входа ───────────────────────────────────────────────────────────────

# ── Кастомные фильтры Jinja2 ─────────────────────────────────────────────────

ROLE_LABELS = {
    "lecturer": "ЛЕКТОР",
    "host":     "ВЕДУЩИЙ",
}

def filter_upper_role(role: str) -> str:
    """'lecturer' → 'ЛЕКТОР', 'host' → 'ВЕДУЩИЙ', иначе — как есть."""
    return ROLE_LABELS.get(role, role.upper())


def filter_yt_id(url: str) -> str:
    """Извлекает video ID из любого формата YouTube URL."""
    import re
    m = re.search(r"(?:youtu\.be/|youtube\.com/(?:embed/|live/|watch\?v=))([A-Za-z0-9_-]{11})", url)
    return m.group(1) if m else url


def filter_rt_id(url: str) -> str:
    """Преобразует URL Rutube в embed-ссылку, сохраняя параметр ?p= для приватных видео."""
    import re
    result = re.sub(r"/video/(?:private/)?([a-f0-9]+)/", r"/play/embed/\1/", url)
    return result


def filter_lesson_audio(lesson_number) -> str:
    """Генерирует имя аудиофайла: 0 → '01.mp3', 1 → '01.mp3', 2 → '02.mp3'."""
    n = int(lesson_number) if lesson_number is not None else 1
    return f"{n:02d}.mp3"


def make_env(tmpl_dir: Path) -> Environment:
    env = Environment(
        loader=FileSystemLoader(str(tmpl_dir)),
        autoescape=select_autoescape(["html"]),
        trim_blocks=True,
        lstrip_blocks=True,
    )
    env.filters["upper_role"]   = filter_upper_role
    env.filters["yt_id"]        = filter_yt_id
    env.filters["rt_id"]        = filter_rt_id
    env.filters["lesson_audio"] = filter_lesson_audio
    return env


def main():
    parser = argparse.ArgumentParser(description="Сборщик лекций из JSON + Jinja2")
    parser.add_argument(
        "files", nargs="*", type=Path,
        help="JSON-файлы для сборки (по умолчанию — все в data/)"
    )
    parser.add_argument(
        "--template", default=TMPL_NAME,
        help=f"Имя шаблона в папке templates/ (по умолчанию: {TMPL_NAME})"
    )
    args = parser.parse_args()

    env = make_env(TMPL_DIR)

    print(f"Шаблон: {args.template}")
    if args.files:
        for f in args.files:
            build_one(f, env, args.template)
    else:
        build_all(env, args.template)

    print("Готово.")


if __name__ == "__main__":
    main()
