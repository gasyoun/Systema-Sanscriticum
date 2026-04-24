#!/usr/bin/env python3
"""
timecode_inserter.py — вставка тайм-кодов <time>N</time> в стенограмму по SRT.

Алгоритм:
  1. Парсим SRT → список (start_sec, normalized_text)
  2. Строим плоский список слов с привязкой к времени
  3. Для каждого заголовка h2/h3 берём якорные слова из текста после него
  4. Ищем позицию в плоском списке: максимальное пересечение множеств слов
     в скользящем окне (устойчиво к перестановкам, паразитным словам, OCR)
  5. Вставляем <time>N</time> перед заголовком

Использование (пакетный режим — сканирование папки):
    python timecode_inserter.py
    python timecode_inserter.py --folder my_folder
    python timecode_inserter.py --debug
    python timecode_inserter.py --threshold 0.4

    Скрипт ищет *.txt в подпапке timecodes/ (или --folder).
    Для каждого FileName.txt, если рядом есть FileName.srt,
    обрабатывает пару и сохраняет результат как FileName_+tc.txt.

Использование (одиночный режим — явные аргументы):
    python timecode_inserter.py transcript.txt subtitles.srt output.txt
"""

import re
import sys
import argparse
from pathlib import Path
from dataclasses import dataclass


# ══════════════════════════════════════════════════════════════════
# СТОП-СЛОВА — убираем из обоих текстов перед сравнением
# (паразитные слова устной речи, частицы, союзы)
# ══════════════════════════════════════════════════════════════════
STOPWORDS = {
    'э', 'ну', 'вот', 'значит', 'итак', 'так', 'да', 'нет',
    'ну-ка', 'ага', 'угу', 'же', 'бы', 'ли', 'и', 'а', 'но',
    'или', 'то', 'что', 'это', 'как', 'не', 'на', 'по', 'из',
    'за', 'об', 'от', 'до', 'со', 'во', 'при', 'для', 'под',
}


# ══════════════════════════════════════════════════════════════════
# ПАРСИНГ SRT
# ══════════════════════════════════════════════════════════════════

@dataclass
class Sub:
    idx: int
    start: float
    text_raw: str
    words: list  # нормализованные значимые слова


def tc2sec(tc: str) -> float:
    m = re.match(r'(\d+):(\d+):(\d+)[,.](\d+)', tc)
    if not m:
        return 0.0
    h, mn, s, ms = int(m[1]), int(m[2]), int(m[3]), int(m[4])
    return h * 3600 + mn * 60 + s + ms / 1000


def normalize(text: str) -> list[str]:
    """Нижний регистр, только буквы/цифры, без стоп-слов, слова > 2 символов."""
    text = re.sub(r'<[^>]+>', ' ', text)          # HTML-теги субтитров
    text = re.sub(r'[^\w\s]', ' ', text.lower())  # пунктуация
    words = [w for w in text.split()
             if len(w) > 2 and w not in STOPWORDS]
    return words


def parse_srt(path: Path) -> list[Sub]:
    with open(path, encoding='utf-8-sig') as f:
        raw = f.read()
    blocks = re.split(r'\r?\n\r?\n', raw.strip())
    result = []
    for block in blocks:
        lines = [l.strip() for l in block.splitlines() if l.strip()]
        if len(lines) < 3:
            continue
        try:
            idx = int(lines[0])
        except ValueError:
            continue
        tc = re.match(r'(.+?)\s*-->\s*(.+)', lines[1])
        if not tc:
            continue
        start = tc2sec(tc[1])
        text = ' '.join(lines[2:])
        result.append(Sub(
            idx=idx,
            start=start,
            text_raw=text,
            words=normalize(text),
        ))
    return result


# ══════════════════════════════════════════════════════════════════
# ПОСТРОЕНИЕ ПЛОСКОГО ИНДЕКСА СЛОВ
# ══════════════════════════════════════════════════════════════════

def build_flat(subs: list[Sub]) -> list[tuple[str, int, float]]:
    """
    Возвращает плоский список (слово, sub_index, start_sec).
    Overlapping-субтитры включаются как есть — поиск по множествам
    это учитывает автоматически.
    """
    flat = []
    for i, sub in enumerate(subs):
        for w in sub.words:
            flat.append((w, i, sub.start))
    return flat


# ══════════════════════════════════════════════════════════════════
# ПОИСК ЯКОРЯ
# ══════════════════════════════════════════════════════════════════

def find_anchor(
    anchor_words: list[str],
    flat: list[tuple],
    search_from: int = 0,
    window: int = 20,
    debug: bool = False,
) -> tuple[float, int, float, str]:
    """
    Скользящее окно по плоскому списку слов.
    Для каждой позиции считаем: сколько якорных слов присутствует
    в окне из `window` слов субтитров.

    Возвращает (score, flat_pos, start_sec, algo).

    algo — метка алгоритма, которым найдена позиция:
      'sliding_window' — полный перебор, лучшая позиция из всех
      'early_stop'     — перебор прерван досрочно при score ≥ 0.95
    """
    anchor_set = set(anchor_words)
    best_score = 0.0
    best_pos = search_from
    best_sec = 0.0
    n = len(anchor_words)
    algos = ['sliding_window']
    early_stopped = False

    for pos in range(search_from, len(flat) - window + 1):
        window_set = {flat[pos + j][0] for j in range(window)}
        hits = len(anchor_set & window_set)
        score = hits / n
        if score > best_score:
            best_score = score
            best_pos = pos
            best_sec = flat[pos][2]
        if best_score >= 0.95:
            early_stopped = True
            break  # ранняя остановка при отличном совпадении

    if early_stopped:
        algos.append('early_stop')

    algo_str = ', '.join(algos)

    if debug:
        window_words = [flat[best_pos + j][0] for j in range(min(window, len(flat) - best_pos))]
        hits_list = [w for w in anchor_words if w in set(window_words)]
        print(f"    якорь ({n} сл.): {anchor_words}")
        print(f"    совпало:        {hits_list}")
        print(f"    score: {best_score:.2f}  →  {int(best_sec//60):02d}:{int(best_sec%60):02d} ({int(best_sec)}s)")
        print(f"    algo: {algo_str}")

    return best_score, best_pos, best_sec, algo_str


# ══════════════════════════════════════════════════════════════════
# ОСНОВНАЯ ЛОГИКА
# ══════════════════════════════════════════════════════════════════

def extract_anchor_words(text_after: str, n: int = 10) -> list[str]:
    """Первые N значимых слов из текста после заголовка (без тегов ролей)."""
    clean = re.sub(r'\[/?[^\]]+\]', ' ', text_after)  # теги ролей
    clean = re.sub(r'<[^>]+>', ' ', clean)              # HTML
    return normalize(clean)[:n]


def find_timecodes_for_headings(
    matches: list,
    transcript: str,
    flat: list,
    threshold: float,
    window: int,
    anchor_n: int,
    debug: bool,
    skip_indices: set,
) -> dict:
    """
    Один проход по заголовкам.
    Возвращает dict: match_index → (char_pos, sec, score_pct, algo, title).
    skip_indices — индексы заголовков, которые уже найдены ранее.
    Поиск идёт вперёд; last_flat_pos сбрасывается между проходами,
    чтобы уже найденные заголовки служили якорями для порядка.
    """
    results = {}
    last_flat_pos = 0

    for i, m in enumerate(matches):
        if i in skip_indices:
            # Заголовок уже покрыт — восстанавливаем last_flat_pos из предыдущего прохода,
            # чтобы порядок не сломался (пропускаем, но позицию не трогаем)
            continue

        title = re.sub(r'<[^>]+>', '', m.group())
        snippet = transcript[m.end(): m.end() + 400]
        anchor = extract_anchor_words(snippet, n=anchor_n)

        if not anchor:
            continue  # нет якорных слов — не поможет ни один threshold

        if debug:
            print(f"\n── «{title}»  (threshold={threshold:.0%})")

        score, flat_pos, sec, algo = find_anchor(
            anchor, flat,
            search_from=last_flat_pos,
            window=window,
            debug=debug,
        )

        if score >= threshold:
            score_pct = round(score * 100)
            results[i] = (m.start(), int(sec), score_pct, algo, title)
            last_flat_pos = max(0, flat_pos - 3)

    return results


def insert_timecodes(
    transcript: str,
    subs: list[Sub],
    threshold: float = 0.4,
    threshold_min: float = 0.1,
    threshold_step: float = 0.05,
    window: int = 20,
    anchor_n: int = 10,
    debug: bool = False,
) -> str:
    """
    Адаптивный поиск тайм-кодов.

    Алгоритм:
      1. Запускаем поиск с threshold (по умолчанию 0.4).
      2. Для непокрытых заголовков снижаем threshold на threshold_step и повторяем.
      3. Останавливаемся, когда все покрыты или threshold < threshold_min.
    """
    flat = build_flat(subs)
    heading_re = re.compile(r'<h[23]>.*?</h[23]>', re.DOTALL)
    matches = list(heading_re.finditer(transcript))

    if not matches:
        print("  Заголовков не найдено.")
        return transcript

    # Заголовки без якорных слов — они никогда не будут покрыты
    no_anchor = set()
    for i, m in enumerate(matches):
        title = re.sub(r'<[^>]+>', '', m.group())
        snippet = transcript[m.end(): m.end() + 400]
        if not extract_anchor_words(snippet, n=anchor_n):
            print(f"  ⚠  «{title}» — нет якорных слов, пропуск")
            no_anchor.add(i)

    # Адаптивный цикл по threshold
    insertions = {}   # match_index → (char_pos, sec, score_pct, algo, title)
    current_threshold = threshold

    while current_threshold >= threshold_min:
        uncovered = [i for i in range(len(matches))
                     if i not in insertions and i not in no_anchor]
        if not uncovered:
            break

        if current_threshold < threshold:
            titles = [re.sub(r'<[^>]+>', '', matches[i].group()) for i in uncovered]
            print(f"\n  ↓ threshold → {current_threshold:.0%}  "
                  f"(непокрыто: {len(uncovered)})")
            if debug:
                for t in titles:
                    print(f"      · «{t}»")

        found = find_timecodes_for_headings(
            matches, transcript, flat,
            threshold=current_threshold,
            window=window,
            anchor_n=anchor_n,
            debug=debug,
            skip_indices=set(insertions.keys()) | no_anchor,
        )
        insertions.update(found)
        current_threshold = round(current_threshold - threshold_step, 10)

    # Итог после адаптивного поиска
    still_missing = [i for i in range(len(matches)) if i not in insertions]

    # ── Интерполяция/экстраполяция для оставшихся ────────────────
    if still_missing:
        print(f"\n  ~ интерполяция для {len(still_missing)} непокрытых заголовков")

        # Строим список опорных точек: (char_pos, sec) для найденных заголовков
        anchors = sorted(
            [(matches[i].start(), insertions[i][1]) for i in insertions],
            key=lambda x: x[0],
        )
        # Крайние точки: начало файла = 0с, конец файла = длительность SRT
        total_chars = len(transcript)
        total_sec   = subs[-1].start if subs else 0
        anchors = [(0, 0)] + anchors + [(total_chars, int(total_sec))]

        for i in still_missing:
            char_pos = matches[i].start()
            title    = re.sub(r'<[^>]+>', '', matches[i].group())

            # Найти ближайших левого и правого соседей в anchors
            left  = (0, 0)
            right = (total_chars, int(total_sec))
            for ac, at in anchors:
                if ac <= char_pos:
                    left = (ac, at)
                else:
                    right = (ac, at)
                    break

            lc, lt = left
            rc, rt = right
            if rc == lc:
                sec_interp = lt
            else:
                ratio = (char_pos - lc) / (rc - lc)
                sec_interp = int(lt + ratio * (rt - lt))

            algo_tag = 'interpolated'
            insertions[i] = (char_pos, sec_interp, 0, algo_tag, title)
            print(f"  ~  <time>{sec_interp:5d}</time>  "
                  f"({sec_interp//60:02d}:{sec_interp%60:02d})"
                  f"  [interpolated]  «{title}»")

    if not insertions:
        print("  Совпадений не найдено.")
        return transcript

    # Вставляем справа налево (по позиции в тексте)
    sorted_insertions = sorted(insertions.values(), key=lambda x: x[0], reverse=True)
    result = list(transcript)
    for char_pos, sec, score_pct, algo, title in sorted_insertions:
        if score_pct > 0:
            algo_field = f'{score_pct}%, {algo}'
        else:
            algo_field = algo  # interpolated / extrapolated — score не применим
        tag = f'<time>{sec}</time>\n<time_algo>{algo_field}</time_algo>\n'
        result.insert(char_pos, tag)
        if score_pct > 0:
            print(f"  ✓  <time>{sec:5d}</time>  ({sec//60:02d}:{sec%60:02d})"
                  f"  {score_pct:3d}%  «{title}»")

    covered = len(insertions)
    total   = len(matches)
    interp  = sum(1 for _, _, sp, _, _ in insertions.values() if sp == 0)
    print(f"\n  Итого: {covered}/{total} заголовков покрыто"
          f"  (из них интерполировано: {interp})")

    return ''.join(result)


# ══════════════════════════════════════════════════════════════════
# ПАКЕТНЫЙ РЕЖИМ — сканирование папки
# ══════════════════════════════════════════════════════════════════

def process_file(
    txt_path: Path,
    srt_path: Path,
    out_path: Path,
    threshold: float,
    threshold_min: float,
    threshold_step: float,
    window: int,
    anchor_n: int,
    debug: bool,
) -> bool:
    """Обрабатывает одну пару txt+srt. Возвращает True при успехе."""
    transcript = txt_path.read_text(encoding='utf-8')
    subs = parse_srt(srt_path)

    if not subs:
        print(f"  ⚠  SRT пустой или не распознан: {srt_path.name}")
        return False

    dur = subs[-1].start
    print(f"  Субтитров: {len(subs)}  |  "
          f"длительность: {int(dur//3600)}ч {int(dur%3600//60)}м {int(dur%60)}с")

    result = insert_timecodes(
        transcript, subs,
        threshold=threshold,
        threshold_min=threshold_min,
        threshold_step=threshold_step,
        window=window,
        anchor_n=anchor_n,
        debug=debug,
    )

    out_path.write_text(result, encoding='utf-8')
    print(f"  → сохранено: {out_path.name}")
    return True


def batch_mode(folder: Path, threshold: float, threshold_min: float,
               threshold_step: float, window: int, anchor_n: int, debug: bool):
    """Сканирует папку, обрабатывает все пары FileName.txt + FileName.srt."""
    if not folder.exists():
        print(f"Папка не найдена: {folder}")
        sys.exit(1)

    txt_files = sorted(folder.glob('*.txt'))
    # Исключаем уже обработанные файлы (_+tc.txt)
    txt_files = [f for f in txt_files if not f.stem.endswith('+tc')]

    if not txt_files:
        print(f"В папке {folder}/ не найдено .txt файлов.")
        sys.exit(0)

    found_pairs = 0
    processed = 0

    for txt_path in txt_files:
        srt_path = txt_path.with_suffix('.srt')
        out_path = txt_path.with_name(txt_path.stem + '_+tc.txt')

        if not srt_path.exists():
            print(f"[пропуск] {txt_path.name}  —  нет парного .srt")
            continue

        found_pairs += 1
        print(f"\n{'─'*60}")
        print(f"[{found_pairs}] {txt_path.name}  +  {srt_path.name}")

        ok = process_file(txt_path, srt_path, out_path, threshold, threshold_min,
                          threshold_step, window, anchor_n, debug)
        if ok:
            processed += 1

    print(f"\n{'═'*60}")
    print(f"Готово: обработано {processed} из {found_pairs} пар  "
          f"(всего .txt в папке: {len(txt_files)})")


# ══════════════════════════════════════════════════════════════════
# ТОЧКА ВХОДА
# ══════════════════════════════════════════════════════════════════

def main():
    ap = argparse.ArgumentParser(
        description=(
            "Вставка тайм-кодов в стенограмму по файлу субтитров SRT.\n\n"
            "Пакетный режим (без позиционных аргументов):\n"
            "  сканирует папку timecodes/, ищет пары FileName.txt + FileName.srt,\n"
            "  результат сохраняет как FileName_+tc.txt.\n\n"
            "Одиночный режим:\n"
            "  передайте transcript subtitles output явно."
        ),
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )

    # Позиционные аргументы — необязательные (одиночный режим)
    ap.add_argument("transcript", nargs='?', help="Стенограмма (.txt) — одиночный режим")
    ap.add_argument("subtitles",  nargs='?', help="Субтитры (.srt) — одиночный режим")
    ap.add_argument("output",     nargs='?', help="Выходной файл — одиночный режим")

    # Общие параметры
    ap.add_argument("--folder",    default="timecodes",
                    help="Папка для пакетного режима (по умолч. timecodes/)")
    ap.add_argument("--debug",     action="store_true",
                    help="Подробный вывод совпадений")
    ap.add_argument("--threshold",      type=float, default=0.4,
                    help="Начальный score (доля совпавших слов), по умолч. 0.4")
    ap.add_argument("--threshold-min",  type=float, default=0.1, dest="threshold_min",
                    help="Минимальный score при адаптивном снижении, по умолч. 0.1")
    ap.add_argument("--threshold-step", type=float, default=0.05, dest="threshold_step",
                    help="Шаг снижения threshold, по умолч. 0.05")
    ap.add_argument("--window",         type=int, default=20,
                    help="Размер скользящего окна в словах, по умолч. 20")
    ap.add_argument("--anchor-n",       type=int, default=10, dest="anchor_n",
                    help="Кол-во якорных слов, по умолч. 10")

    args = ap.parse_args()

    # ── Одиночный режим ──────────────────────────────────────────
    if args.transcript or args.subtitles or args.output:
        if not (args.transcript and args.subtitles and args.output):
            ap.error("В одиночном режиме нужно указать все три аргумента: "
                     "transcript subtitles output")

        txt_path = Path(args.transcript)
        srt_path = Path(args.subtitles)
        out_path = Path(args.output)

        transcript = txt_path.read_text(encoding='utf-8')
        subs = parse_srt(srt_path)
        dur = subs[-1].start if subs else 0
        print(f"Субтитров: {len(subs)}  |  "
              f"длительность: {int(dur//3600)}ч {int(dur%3600//60)}м {int(dur%60)}с\n")

        result = insert_timecodes(
            transcript, subs,
            threshold=args.threshold,
            threshold_min=args.threshold_min,
            threshold_step=args.threshold_step,
            window=args.window,
            anchor_n=args.anchor_n,
            debug=args.debug,
        )

        out_path.write_text(result, encoding='utf-8')
        print(f"\n✓ Сохранено: {out_path}")

    # ── Пакетный режим ───────────────────────────────────────────
    else:
        folder = Path(args.folder)
        print(f"Пакетный режим  |  папка: {folder}/")
        batch_mode(folder, args.threshold, args.threshold_min,
                   args.threshold_step, args.window, args.anchor_n, args.debug)


if __name__ == '__main__':
    main()
