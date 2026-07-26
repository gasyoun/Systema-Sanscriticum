#!/usr/bin/env python3
"""Render docs/materials/kossovich-arzamas/SOURCE.md -> body.html (import payload).

SOURCE.md is deliberately HTML-free (org hook bans HTML in .md), so this
script owns all markup decisions:

  ## N. Title                      -> <h2>N. Title</h2>
  ### Title                        -> <h3>
  ![alt](/images/...)              -> <figure><img ...>
      *caption line right after*   ->   <figcaption>caption</figcaption></figure>
  > quote                          -> <blockquote>
  - item / 1. item                 -> <ul>/<ol>
  **b** *i* [t](u) `c`             -> inline strong/em/a/code
  --- (thematic break)             -> <hr>

Everything before the first ## goes in as the lead paragraph(s).
Only a controlled Markdown subset is supported on purpose: the converter
must never guess. Unknown constructs raise, so the payload stays reviewable.
"""

import json
import re
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
SRC = HERE / "SOURCE.md"
META = HERE / "meta.json"
OUT = HERE / "body.html"

sys.stdout.reconfigure(encoding="utf-8")
sys.stderr.reconfigure(encoding="utf-8")


def esc(text: str) -> str:
    return text.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")


INLINE_LINK = re.compile(r"\[([^\]]+)\]\(([^)\s]+)\)")
INLINE_STRONG = re.compile(r"\*\*(.+?)\*\*")
INLINE_EM = re.compile(r"(?<![*\w])\*([^*]+)\*(?![*\w])")
INLINE_CODE = re.compile(r"`([^`]+)`")


def inline(text: str) -> str:
    text = esc(text)
    text = INLINE_CODE.sub(r"<code>\1</code>", text)
    text = INLINE_LINK.sub(r'<a href="\2">\1</a>', text)
    text = INLINE_STRONG.sub(r"<strong>\1</strong>", text)
    text = INLINE_EM.sub(r"<em>\1</em>", text)
    return text


IMG = re.compile(r"^!\[([^\]]*)\]\(([^)\s]+)\)\s*$")


def render(source: str) -> tuple[str, int]:
    lines = source.splitlines()
    out: list[str] = []
    para: list[str] = []
    quote: list[str] = []
    ul: list[str] = []
    ol: list[str] = []
    h2_count = 0

    def flush_para() -> None:
        if para:
            out.append("<p>" + inline(" ".join(para)) + "</p>")
            para.clear()

    def flush_quote() -> None:
        if quote:
            out.append("<blockquote><p>" + inline(" ".join(quote)) + "</p></blockquote>")
            quote.clear()

    def flush_lists() -> None:
        if ul:
            out.append("<ul>" + "".join(f"<li>{inline(i)}</li>" for i in ul) + "</ul>")
            ul.clear()
        if ol:
            out.append("<ol>" + "".join(f"<li>{inline(i)}</li>" for i in ol) + "</ol>")
            ol.clear()

    def flush_all() -> None:
        flush_para()
        flush_quote()
        flush_lists()

    i = 0
    while i < len(lines):
        line = lines[i]
        stripped = line.strip()

        if not stripped:
            flush_all()
        elif stripped.startswith("## "):
            flush_all()
            h2_count += 1
            out.append(f"<h2>{inline(stripped[3:].strip())}</h2>")
        elif stripped.startswith("### "):
            flush_all()
            out.append(f"<h3>{inline(stripped[4:].strip())}</h3>")
        elif stripped == "---":
            flush_all()
            out.append("<hr>")
        elif IMG.match(stripped):
            flush_all()
            alt, src = IMG.match(stripped).groups()
            caption = None
            j = i + 1
            while j < len(lines) and not lines[j].strip():
                j += 1
            if j < len(lines):
                nxt = lines[j].strip()
                if nxt.startswith("*") and nxt.endswith("*") and not nxt.startswith("**"):
                    caption = nxt[1:-1]
                    i = j
            fig = f'<figure><img src="{src}" alt="{esc(alt)}" loading="lazy">'
            if caption:
                fig += f"<figcaption>{inline(caption)}</figcaption>"
            fig += "</figure>"
            out.append(fig)
        elif stripped.startswith("> "):
            flush_para()
            flush_lists()
            quote.append(stripped[2:])
        elif stripped.startswith("- "):
            flush_para()
            flush_quote()
            if ol:
                flush_lists()
            ul.append(stripped[2:])
        elif re.match(r"^\d+\.\s", stripped):
            flush_para()
            flush_quote()
            if ul:
                flush_lists()
            ol.append(re.sub(r"^\d+\.\s+", "", stripped))
        elif stripped.startswith("# ") and not out and not para:
            pass  # the single leading h1 is the article title; the blade renders it
        elif stripped.startswith("#"):
            raise SystemExit(f"line {i + 1}: unsupported heading level: {stripped[:40]}")
        else:
            flush_quote()
            flush_lists()
            para.append(stripped)
        i += 1
    flush_all()
    return "\n".join(out), h2_count


def main() -> None:
    source = SRC.read_text(encoding="utf-8")
    meta = json.loads(META.read_text(encoding="utf-8"))
    body, h2_count = render(source)

    words = len(re.findall(r"[\wЀ-ӿ]+", re.sub(r"<[^>]+>", " ", body)))
    reading_time = max(1, round(words / 200))

    OUT.write_text(body + "\n", encoding="utf-8")
    print(f"body.html written: {len(body)} chars, {h2_count} h2, "
          f"{words} words, ~{reading_time} min")
    if h2_count < 12:
        raise SystemExit(f"ACCEPT FAIL (H1696): only {h2_count} h2 (need >=12)")
    expected = meta.get("expected_min_h2", 12)
    if h2_count < expected:
        raise SystemExit(f"meta.json expected_min_h2={expected}, got {h2_count}")


if __name__ == "__main__":
    main()
