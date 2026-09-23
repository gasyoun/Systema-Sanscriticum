#!/usr/bin/env python3
"""Пакетное распознавание уроков локальным whisper и заливка стенограмм в LMS.

Конвейер целиком крутится на станции: запись качает и распознаёт локальный
whisper-сервер (faster-whisper на видеокарте), наружу уходит только готовая
стенограмма — в кабинет, по тому же секрету, что и у n8n.

    # 1. Очередь с прода (уроки с записью, но без стенограммы):
    ssh vps-samskrte92 "cd /var/www/html && php artisan lessons:transcript-queue --teacher=2" > queue.json

    # 2. Прогон (можно прерывать: состояние в state.json, повтор продолжит с места):
    python scripts/transcribe_lessons.py queue.json --state state.json

    # 3. Разведка без записи в LMS:
    python scripts/transcribe_lessons.py queue.json --dry-run --limit 1

Переменные окружения:
    WHISPER_URL       адрес whisper-сервера (по умолчанию http://127.0.0.1:8090)
    WHISPER_API_KEY   ключ whisper; иначе читается из secrets/whisper_api_key
    LMS_URL           адрес кабинета (по умолчанию https://samskrte.ru)
    LESSON_SYNC_SECRET секрет приёмки стенограмм (заголовок X-Secret-Key)

Формат кабинета — JSON в стиле Deepgram: слова с таймкодами в
results.channels[0].alternatives[0].words[]. Именно слова читает
App\\Support\\TranscriptParser, собирая из них предложения по знакам препинания.

Загрузка идёт с quiet=1: стенограмма привязывается без событий модели, иначе
сотня уроков разом уедет в нарезку клипов в ВК и в черновики статей.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path
from typing import Any

WHISPER_URL = os.environ.get("WHISPER_URL", "http://127.0.0.1:8090").rstrip("/")
LMS_URL = os.environ.get("LMS_URL", "https://samskrte.ru").rstrip("/")
POLL_SECONDS = 20
PAGE_SIZE = 1000


def whisper_key() -> str:
    key = os.environ.get("WHISPER_API_KEY", "").strip()
    if key:
        return key
    for candidate in (Path("secrets/whisper_api_key"), Path.home() / ".whisper_api_key",
                      Path("F:/git/media-ai/secrets/whisper_api_key")):
        if candidate.is_file():
            return candidate.read_text(encoding="utf-8").strip()
    sys.exit("Нет ключа whisper: задайте WHISPER_API_KEY или положите secrets/whisper_api_key")


def request(url: str, *, method: str = "GET", headers: dict[str, str] | None = None,
            payload: Any = None, timeout: int = 120) -> Any:
    # Только http/https: ссылка на запись приходит из базы, а urllib открыл бы и
    # file:///etc/passwd — тогда «стенограммой» урока стал бы локальный файл.
    scheme = urllib.parse.urlparse(url).scheme.lower()
    if scheme not in ("http", "https"):
        raise ValueError(f"недопустимая схема {scheme or '(пусто)'} в адресе: {url[:80]}")

    data = None if payload is None else json.dumps(payload, ensure_ascii=False).encode("utf-8")
    req = urllib.request.Request(url, data=data, method=method, headers=headers or {})
    if data is not None:
        req.add_header("Content-Type", "application/json")
    # nosemgrep: python.lang.security.audit.dynamic-urllib-use-detected.dynamic-urllib-use-detected
    with urllib.request.urlopen(req, timeout=timeout) as response:
        body = response.read().decode("utf-8")
    return json.loads(body) if body.strip().startswith(("{", "[")) else body


def transcribe(url: str, model: str, key: str, quiet_log: bool = False) -> dict[str, Any]:
    """Поставить задачу в whisper и дождаться её. Возвращает {segments, duration, ...}."""
    auth = {"Authorization": f"Bearer {key}"}
    job = request(f"{WHISPER_URL}/transcribe", method="POST", headers=auth,
                  payload={"url": url, "model": model, "language": "ru"})
    job_id = job["job_id"]

    while True:
        state = request(f"{WHISPER_URL}/jobs/{job_id}", headers=auth)
        status = state.get("status")
        if status == "completed":
            break
        if status in {"failed", "error"}:
            raise RuntimeError(f"whisper: {state.get('error') or 'задача провалилась'}")
        if not quiet_log:
            stage = state.get("stage") or status
            print(f"    {stage} {round(float(state.get('progress') or 0) * 100)}%", end="\r", flush=True)
        time.sleep(POLL_SECONDS)

    segments = state.get("segments")
    if state.get("segments_omitted") or segments is None:
        # Длинная лекция: сегменты забираются страницами (см. whisper_server.py).
        segments, offset = [], 0
        while True:
            page = request(f"{WHISPER_URL}/jobs/{job_id}/segments?offset={offset}&limit={PAGE_SIZE}", headers=auth)
            chunk = page.get("segments", page if isinstance(page, list) else [])
            segments.extend(chunk)
            if len(chunk) < PAGE_SIZE:
                break
            offset += len(chunk)

    state["segments"] = segments
    return state


def to_deepgram(result: dict[str, Any]) -> dict[str, Any]:
    """Ответ whisper -> JSON в формате, который читает кабинет.

    Берём слова с таймкодами (whisper_server с word_timestamps=True). Если слов
    нет — раскладываем слова сегмента равномерно внутри его отрезка: кабинету
    нужны слова, а посегментная разбивка дала бы одно «предложение» на минуту.
    """
    words: list[dict[str, Any]] = []

    for segment in result.get("segments", []):
        segment_words = segment.get("words") or []
        if segment_words:
            for word in segment_words:
                text = str(word.get("word", "")).strip()
                if not text:
                    continue
                words.append({
                    "word": text.strip(".,!?;:»«—-").lower() or text,
                    "punctuated_word": text,
                    "start": round(float(word.get("start", segment.get("start", 0))), 3),
                    "end": round(float(word.get("end", segment.get("end", 0))), 3),
                    "confidence": round(float(word.get("probability", 0.9)), 4),
                })
            continue

        chunks = str(segment.get("text", "")).split()
        if not chunks:
            continue
        start = float(segment.get("start", 0))
        end = float(segment.get("end", start))
        step = (end - start) / len(chunks) if end > start else 0.0
        for index, text in enumerate(chunks):
            words.append({
                "word": text.strip(".,!?;:»«—-").lower() or text,
                "punctuated_word": text,
                "start": round(start + index * step, 3),
                "end": round(start + (index + 1) * step, 3),
                "confidence": 0.5,  # таймкод синтетический — честно помечаем
            })

    transcript = " ".join(w["punctuated_word"] for w in words)

    return {
        "metadata": {
            "model": result.get("model"),
            "duration": result.get("duration"),
            "language": result.get("language"),
            "source": "whisper-local",
            "created": time.strftime("%Y-%m-%dT%H:%M:%S%z"),
        },
        "results": {"channels": [{"alternatives": [{"transcript": transcript, "words": words}]}]},
    }


def upload(lesson_id: int, payload: dict[str, Any], secret: str) -> dict[str, Any]:
    return request(
        f"{LMS_URL}/api/lessons/{lesson_id}/transcript",
        method="POST",
        headers={"X-Secret-Key": secret},
        payload={"transcript": payload, "quiet": 1},
        timeout=300,
    )


def load_state(path: Path) -> dict[str, Any]:
    if path.is_file():
        return json.loads(path.read_text(encoding="utf-8"))
    return {"done": {}, "failed": {}}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("queue", type=Path, help="JSON от lessons:transcript-queue")
    parser.add_argument("--state", type=Path, default=Path("transcribe-state.json"))
    parser.add_argument("--model", default="large-v3")
    parser.add_argument("--limit", type=int)
    parser.add_argument("--only", type=int, action="append", default=[], help="только эти lesson_id")
    parser.add_argument("--source", choices=["rutube", "youtube", "auto"], default="auto")
    parser.add_argument("--out-dir", type=Path, default=Path("transcripts-out"),
                        help="куда класть готовый JSON (копия на случай разбора)")
    parser.add_argument("--dry-run", action="store_true", help="распознать, но в LMS не заливать")
    parser.add_argument("--retry-failed", action="store_true", help="повторить упавшие из state")
    args = parser.parse_args()

    lessons = json.loads(args.queue.read_text(encoding="utf-8"))
    state = load_state(args.state)
    key = whisper_key()
    secret = os.environ.get("LESSON_SYNC_SECRET", "").strip()
    if not secret and not args.dry_run:
        sys.exit("Нет LESSON_SYNC_SECRET — залить стенограмму в LMS нечем (или запустите с --dry-run)")

    args.out_dir.mkdir(parents=True, exist_ok=True)
    picked = 0

    for lesson in lessons:
        lesson_id = int(lesson["lesson_id"])
        if args.only and lesson_id not in args.only:
            continue
        if str(lesson_id) in state["done"]:
            continue
        if str(lesson_id) in state["failed"] and not args.retry_failed:
            continue
        if args.limit is not None and picked >= args.limit:
            break

        url = lesson.get("rutube_url") or lesson.get("youtube_url")
        if args.source == "rutube":
            url = lesson.get("rutube_url")
        elif args.source == "youtube":
            url = lesson.get("youtube_url")
        if not url:
            state["failed"][str(lesson_id)] = "нет ссылки на запись"
            continue
        if not str(url).lower().startswith(("http://", "https://")):
            # Ссылку кладёт куратор руками — мусор в поле не должен уходить в yt-dlp.
            state["failed"][str(lesson_id)] = f"ссылка не http(s): {url[:60]}"
            continue

        picked += 1
        print(f"[{picked}] урок {lesson_id} · {lesson.get('course')} · {lesson.get('title')}")
        print(f"    {url}")

        try:
            result = transcribe(url, args.model, key)
            payload = to_deepgram(result)
            words = len(payload["results"]["channels"][0]["alternatives"][0]["words"])
            if words == 0:
                raise RuntimeError("whisper вернул пустую стенограмму")

            out_file = args.out_dir / f"lesson-{lesson_id}.json"
            out_file.write_text(json.dumps(payload, ensure_ascii=False), encoding="utf-8")

            if args.dry_run:
                print(f"    слов {words}, {round(float(result.get('duration') or 0) / 60)} мин -> {out_file} (без заливки)")
            else:
                answer = upload(lesson_id, payload, secret)
                print(f"    слов {words}, предложений {answer.get('sentences')} -> {answer.get('transcript_file')}")
                state["done"][str(lesson_id)] = {
                    "words": words,
                    "sentences": answer.get("sentences"),
                    "at": time.strftime("%Y-%m-%d %H:%M"),
                }
                state["failed"].pop(str(lesson_id), None)
        except (urllib.error.URLError, urllib.error.HTTPError, RuntimeError, KeyError, ValueError) as error:
            print(f"    ОШИБКА: {error}")
            state["failed"][str(lesson_id)] = str(error)
        finally:
            args.state.write_text(json.dumps(state, ensure_ascii=False, indent=2), encoding="utf-8")

    print(f"\nГотово: {len(state['done'])} уроков со стенограммой, ошибок {len(state['failed'])}.")
    if state["failed"]:
        for lesson_id, reason in list(state["failed"].items())[:10]:
            print(f"  урок {lesson_id}: {reason}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
