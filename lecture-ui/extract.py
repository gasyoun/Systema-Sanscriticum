#!/usr/bin/env python3
"""
extract.py — конвертер HTML-лекций в JSON.

Использование:
  python extract.py 00.html              # → data/00.json
  python extract.py 00.html -o out.json  # → указанный файл
  python extract.py *.html               # → data/*.json (пакетная обработка)
"""

import json
import re
import argparse
from pathlib import Path
from bs4 import BeautifulSoup, NavigableString, Tag


# ── Путь для сохранения по умолчанию ─────────────────────────────────────────

ROOT     = Path(__file__).parent
DATA_DIR = ROOT / "data"


# ── Парсинг метаданных ────────────────────────────────────────────────────────

def parse_meta(soup: BeautifulSoup) -> dict:
    meta = {}

    # Название курса и занятия из <p class="series">
    series_tag = soup.find("p", class_="series")
    if series_tag:
        parts = series_tag.get_text().split("·")
        if len(parts) >= 2:
            meta["course"]        = parts[0].replace("Курс", "").strip().strip("«»")
            meta["lesson_title"]  = parts[1].strip()
        else:
            meta["course"] = series_tag.get_text().strip()

    # Заголовок лекции
    h1 = soup.find("h1")
    if h1:
        meta["title"] = h1.get_text().strip()

    # Лектор из <p class="subtitle">
    subtitle_tag = soup.find("p", class_="subtitle")
    if subtitle_tag:
        text = subtitle_tag.get_text().strip()
        lecturer_m = re.search(r"Лектор:\s*(.+)", text)
        if lecturer_m:
            meta["lecturer"] = lecturer_m.group(1).strip()

    # Номер занятия из названия файла (заполняется в parse_html)
    meta.setdefault("lesson_number", 0)

    # Блок <p class="meta">
    meta_tag = soup.find("p", class_="meta")
    if meta_tag:
        raw = meta_tag.get_text(separator="\n")
        lines = [l.strip() for l in raw.split("\n") if l.strip()]

        # Организация (первая строка, если нет цифр)
        if lines and not re.search(r"\d{4}", lines[0]):
            meta["organization"] = lines[0]

        # Дата и участники
        for line in lines:
            date_m = re.search(r"(\d{1,2}\s+\w+\s+\d{4})", line)
            if date_m:
                meta["date_display"] = date_m.group(1)

            host_m = re.search(r"Ведущий:\s*(.+?)(?:\s*·|$)", line)
            if host_m:
                meta["host"] = host_m.group(1).strip()

        # Ссылки на видео
        video = {}
        for a in meta_tag.find_all("a"):
            href = a.get("href", "")
            txt  = a.get_text().strip()
            if txt == "YT":
                video["youtube"] = href
            elif txt == "RT":
                video["rutube"] = href
        if video:
            meta["video"] = video

    return meta


# ── Парсинг таймкодов ─────────────────────────────────────────────────────────

def build_toc_timecodes(soup: BeautifulSoup) -> dict:
    """
    Строит словарь {section_id: {yt, rt, au}} из nav.toc.
    Таймкоды в этом HTML хранятся в оглавлении, а не в самих заголовках.
    """
    toc_map = {}
    toc = soup.find("nav", class_="toc")
    if not toc:
        return toc_map

    for li in toc.find_all("li"):
        # Ссылка на якорь секции — первый <a> в строке
        anchor = li.find("a", href=re.compile(r"^#"))
        if not anchor:
            continue
        section_id = anchor["href"].lstrip("#")
        tc = {}

        for a in li.find_all("a"):
            href = a.get("href", "")
            cls  = a.get("class", [])
            t    = re.search(r"[?&]t=(\d+)", href)
            if not t:
                continue
            seconds = int(t.group(1))

            if "yt-toc" in cls:
                tc["yt"] = seconds
            elif "rt-toc" in cls:
                tc["rt"] = seconds
            elif "audio-toc" in cls:
                tc["au"] = seconds

        if tc:
            toc_map[section_id] = tc

    return toc_map


# ── Определение роли говорящего ───────────────────────────────────────────────

def detect_role(speaker_text: str) -> str:
    """Возвращает 'lecturer', 'host' или оригинальную строку."""
    t = speaker_text.upper()
    if "ЛЕКТОР" in t:
        return "lecturer"
    if "ВЕДУЩИЙ" in t:
        return "host"
    return speaker_text.strip()


# ── Парсинг блока диалога ─────────────────────────────────────────────────────

def parse_dialog(dialog_div: Tag) -> dict:
    turns = []
    for turn in dialog_div.find_all("div", class_="dialog-turn"):
        speaker_tag = turn.find("div", class_="dialog-speaker")
        text_tag    = turn.find("div", class_="dialog-text")
        if not speaker_tag or not text_tag:
            continue

        speaker_raw = speaker_tag.get_text().strip()
        paragraphs  = [p.get_text().strip() for p in text_tag.find_all("p") if p.get_text().strip()]

        turns.append({
            "role": detect_role(speaker_raw),
            "text": paragraphs[0] if len(paragraphs) == 1 else paragraphs,
        })

    return {"type": "dialog", "turns": turns}


# ── Парсинг содержимого секции ────────────────────────────────────────────────

def parse_section_content(nodes) -> list:
    """
    Обходит узлы между двумя заголовками и собирает блоки контента.
    Смежные <p> объединяются в один блок type=text.
    """
    content = []
    pending_paragraphs = []

    def flush_text():
        if pending_paragraphs:
            content.append({"type": "text", "paragraphs": list(pending_paragraphs)})
            pending_paragraphs.clear()

    for node in nodes:
        if isinstance(node, NavigableString):
            continue
        if not isinstance(node, Tag):
            continue

        tag = node.name

        # Диалог
        if "dialog" in node.get("class", []):
            flush_text()
            content.append(parse_dialog(node))

        # Интерджекция (короткая реплика ведущего внутри текста)
        elif tag == "p" and "interjection" in node.get("class", []):
            flush_text()
            speaker_span = node.find("span", class_="interjection-speaker")
            if speaker_span:
                speaker_text = speaker_span.get_text().strip().rstrip(":")
                speaker_span.decompose()
            else:
                speaker_text = "host"
            content.append({
                "type":    "interjection",
                "speaker": detect_role(speaker_text),
                "text":    node.get_text().strip(),
            })

        # Картинка
        elif tag == "figure":
            flush_text()
            img     = node.find("img")
            caption = node.find("figcaption")
            content.append({
                "type":    "figure",
                "src":     img["src"] if img else "",
                "alt":     img.get("alt", "") if img else "",
                "caption": caption.get_text().strip() if caption else "",
            })

        # Обычный абзац
        elif tag == "p":
            text = node.get_text().strip()
            if text:
                pending_paragraphs.append(text)

    flush_text()
    return content


# ── Главный парсер ────────────────────────────────────────────────────────────

def parse_html(html_path: Path) -> dict:
    soup = BeautifulSoup(html_path.read_text(encoding="utf-8"), "html.parser")

    meta = parse_meta(soup)
    # Номер занятия из имени файла (00.html → 0, 01.html → 1)
    num_m = re.search(r"(\d+)", html_path.stem)
    meta["lesson_number"] = int(num_m.group(1)) if num_m else 0
    video = meta.get("video", {})
    page    = soup.find("div", class_="page")

    # Собираем все заголовки h2/h3 внутри основного контента (не в nav.toc)
    toc = soup.find("nav", class_="toc")
    headings = []
    for tag in (page or soup).find_all(["h2", "h3"]):
        # Пропускаем заголовки внутри nav
        if toc and toc in tag.parents:
            continue
        headings.append(tag)

    toc_map  = build_toc_timecodes(soup)
    sections = []
    for i, heading in enumerate(headings):
        section_id = heading.get("id", f"section_{i}")
        level      = int(heading.name[1])

        # Чистый текст заголовка (без таймкод-ссылок и вложенных figure)
        heading_copy = BeautifulSoup(str(heading), "html.parser").find(heading.name)
        for span in heading_copy.find_all("span", class_="yt-link"):
            span.decompose()
        for fig in heading_copy.find_all("figure"):
            fig.decompose()
        title = heading_copy.get_text().strip()

        timecodes = toc_map.get(section_id, {})

        # Узлы между этим заголовком и следующим
        next_heading = headings[i + 1] if i + 1 < len(headings) else None
        sibling_nodes = []
        for sibling in heading.next_siblings:
            if sibling is next_heading:
                break
            sibling_nodes.append(sibling)

        content = parse_section_content(sibling_nodes)

        sections.append({
            "id":        section_id,
            "level":     level,
            "title":     title,
            "timecodes": timecodes,
            "content":   content,
        })

    return {"meta": meta, "sections": sections}


# ── Точка входа ───────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description="Конвертер HTML-лекций в JSON")
    parser.add_argument("files", nargs="+", type=Path, help="HTML-файлы для конвертации")
    parser.add_argument("-o", "--output", type=Path, help="Путь к выходному JSON (только для одного файла)")
    args = parser.parse_args()

    if args.output and len(args.files) > 1:
        parser.error("Флаг -o можно использовать только с одним входным файлом")

    DATA_DIR.mkdir(parents=True, exist_ok=True)

    for html_path in args.files:
        lecture = parse_html(html_path)

        if args.output:
            out_path = args.output
        else:
            out_path = DATA_DIR / html_path.with_suffix(".json").name

        out_path.write_text(
            json.dumps(lecture, ensure_ascii=False, indent=2),
            encoding="utf-8"
        )
        sections_count = len(lecture["sections"])
        print(f"  ✓  {html_path.name}  →  {out_path}  ({sections_count} секций)")

    print("Готово.")


if __name__ == "__main__":
    main()
