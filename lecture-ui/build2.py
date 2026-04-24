#!/usr/bin/env python3
"""
build.py — сборщик HTML-лекций из JSON + Jinja2-шаблон.

Использование:
  python build.py                  # собрать все лекции из data/
  python build.py data/00.json     # собрать одну лекцию
  python build.py --template other.html.j2 data/00.json  # другой шаблон

Поддерживаемые форматы блоков контента:

  Новый формат (speech):
    {"type": "speech", "role": "СПИКЕР 00", "paragraphs": [{"text": "...", "t": 123}]}

  Старый формат (dialog / interjection / text) — поддерживается для обратной совместимости:
    {"type": "dialog", "turns": [{"role": "lecturer", "text": "..."}]}
    {"type": "interjection", "speaker": "host", "text": "..."}
    {"type": "text", "paragraphs": ["строка1", "строка2"]}

  figure — без изменений:
    {"type": "figure", "src": "...", "alt": "...", "caption": "..."}
"""

import re
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


def normalize_blocks(content: list) -> list:
    """
    Приводит блоки контента к единому внутреннему формату для шаблона.

    Новый формат speech остаётся как есть.
    Старые форматы (dialog, interjection, text) конвертируются в speech-блоки,
    чтобы шаблон работал с единственным типом речевого блока.

    Итоговые типы блоков, которые видит шаблон:
      - "speech"  : {"type": "speech", "role": str, "paragraphs": [{"text": str, "t": int|None}]}
      - "figure"  : без изменений
    """
    def norm_para(p):
        """Гарантирует, что абзац — словарь с ключами 'text' и 't'.
        Поддерживает два формата таймкода:
          {"t": N}                           — когда все offsets нулевые
          {"t_yt": N, "t_rt": N, "t_au": N} — когда offsets ненулевые
        Если 't' отсутствует — подставляет None, ссылка на видео не строится."""
        if isinstance(p, str):
            return {"text": p, "t": None}
        if not isinstance(p, dict):
            return {"text": str(p), "t": None}
        t = p.get("t") if p.get("t") is not None else p.get("t_yt")
        result = {"text": p.get("text", ""), "t": t}
        if "dg_ref" in p:
            result["dg_ref"] = p["dg_ref"]
        return result

    result = []
    for block in content:
        btype = block.get("type")

        # ── Новый формат: speech ────────────────────────────────────────────
        if btype == "speech":
            paras = [norm_para(p) for p in block.get("paragraphs", [])]
            result.append({
                "type":       "speech",
                "role":       block.get("role", ""),
                "paragraphs": paras,
            })

        # ── Старый формат: dialog ───────────────────────────────────────────
        elif btype == "dialog":
            for turn in block.get("turns", []):
                raw_text = turn.get("text", "")
                if isinstance(raw_text, list):
                    paras = [norm_para(p) for p in raw_text]
                else:
                    paras = [norm_para(raw_text)]
                result.append({
                    "type":       "speech",
                    "role":       turn.get("role", ""),
                    "paragraphs": paras,
                })

        # ── Старый формат: interjection ─────────────────────────────────────
        elif btype == "interjection":
            result.append({
                "type":       "speech",
                "role":       block.get("speaker", ""),
                "paragraphs": [norm_para(block.get("text", ""))],
            })

        # ── Старый формат: text ─────────────────────────────────────────────
        elif btype == "text":
            paras = [norm_para(p) for p in block.get("paragraphs", [])]
            # text-блоки могут не иметь роли — передаём пустую строку
            result.append({
                "type":       "speech",
                "role":       block.get("role", ""),
                "paragraphs": paras,
            })

        # ── figure: без изменений ────────────────────────────────────────────
        else:
            result.append(block)

    return result


def enrich(lecture: dict) -> dict:
    """
    Добавляет производные поля в данные лекции перед рендером:
    - метки времени в читаемом виде (с учётом офсетов yt_offset/rt_offset/au_offset)
    - готовые ссылки для каждой секции
    - порядковые номера слайдов (figure-блоков)
    - нормализация блоков контента к единому формату
    """
    meta     = lecture.get("meta", {})
    video    = meta.get("video", {})
    yt_base  = video.get("youtube", "")
    rt_base  = video.get("rutube", "")

    yt_offset = int(meta.get("yt_offset", 0))
    rt_offset = int(meta.get("rt_offset", 0))
    au_offset = int(meta.get("au_offset", 0))

    # Гарантируем наличие полей в meta — шаблон читает их для JS-переменных
    meta["yt_offset"] = yt_offset
    meta["rt_offset"] = rt_offset
    meta["au_offset"] = au_offset

    slide_number = 0
    for section in lecture.get("sections", []):
        tc   = section.get("timecodes", {})
        # Поддерживаем оба формата: единый {"t": N} и раздельный {"yt": N, "rt": N, "au": N}.
        # Единый "t" используется как фолбэк, если раздельный ключ отсутствует.
        t    = tc.get("t")
        yt_t = tc.get("yt") if tc.get("yt") is not None else t
        rt_t = tc.get("rt") if tc.get("rt") is not None else t
        au_t = tc.get("au") if tc.get("au") is not None else t

        if yt_t is not None:
            section["_yt_url"]  = build_yt_url(yt_base, yt_t + yt_offset)
            section["_yt_time"] = seconds_to_timestamp(yt_t + yt_offset)
        if rt_t is not None:
            section["_rt_url"]  = build_rt_url(rt_base, rt_t + rt_offset)
            section["_rt_time"] = seconds_to_timestamp(rt_t + rt_offset)
        if au_t is not None:
            section["_au_time"] = seconds_to_timestamp(au_t + au_offset)

        # Нормализуем блоки к единому формату
        section["content"] = normalize_blocks(section.get("content", []))

        # Нумеруем слайды после нормализации
        for block in section["content"]:
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


# ── Кастомные фильтры Jinja2 ─────────────────────────────────────────────────

ROLE_LABELS = {
    "lecturer": "ЛЕКТОР",
    "host":     "ВЕДУЩИЙ",
}

def filter_upper_role(role: str) -> str:
    """
    Нормализует роль для отображения:
      "lecturer"  → "ЛЕКТОР"
      "host"      → "ВЕДУЩИЙ"
      "СПИКЕР 00" → "СПИКЕР 00"  (уже в верхнем регистре, возвращается как есть)
      "татьяна"   → "ТАТЬЯНА"
    """
    return ROLE_LABELS.get(role, role.upper())


def filter_yt_id(url: str) -> str:
    """Извлекает video ID из любого формата YouTube URL."""
    m = re.search(r"(?:youtu\.be/|youtube\.com/(?:embed/|live/|watch\?v=))([A-Za-z0-9_-]{11})", url)
    return m.group(1) if m else url


def filter_rt_id(url: str) -> str:
    """Преобразует URL Rutube в embed-ссылку, сохраняя параметр ?p= для приватных видео."""
    return re.sub(r"/video/(?:private/)?([a-f0-9]+)/", r"/play/embed/\1/", url)


def filter_lesson_audio(lesson_number) -> str:
    """Генерирует имя аудиофайла: 0 → '00.mp3', 1 → '01.mp3', 2 → '02.mp3'."""
    n = int(lesson_number) if lesson_number is not None else 1
    return f"{n:02d}.mp3"


def filter_para_url(para: dict, base_yt: str, base_rt: str, base_au: str) -> dict:
    """
    Добавляет к объекту абзаца готовые ссылки на видео/аудио,
    если в абзаце есть таймкод 't'.
    Используется в шаблоне для создания кликабельных меток времени.
    """
    t = para.get("t")
    if t is None:
        return para
    result = dict(para)
    sep_yt = "&" if "?" in base_yt else "?"
    sep_rt = "&" if "?" in base_rt else "?"
    sep_au = "&" if "?" in base_au else "?"
    result["_yt_url"]  = f"{base_yt}{sep_yt}t={t}"
    result["_rt_url"]  = f"{base_rt}{sep_rt}t={t}"
    result["_au_url"]  = f"{base_au}{sep_au}t={t}"
    result["_time"]    = seconds_to_timestamp(t)
    return result


def make_env(tmpl_dir: Path) -> Environment:
    env = Environment(
        loader=FileSystemLoader(str(tmpl_dir)),
        autoescape=select_autoescape(["html"]),
        trim_blocks=True,
        lstrip_blocks=True,
    )
    env.filters["upper_role"]     = filter_upper_role
    env.filters["yt_id"]          = filter_yt_id
    env.filters["rt_id"]          = filter_rt_id
    env.filters["lesson_audio"]   = filter_lesson_audio
    env.filters["para_url"]       = filter_para_url
    env.filters["seconds_to_ts"]  = seconds_to_timestamp
    return env


# ── Точка входа ───────────────────────────────────────────────────────────────

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
