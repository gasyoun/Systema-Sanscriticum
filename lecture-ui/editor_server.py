#!/usr/bin/env python3
"""
editor_server.py — Flask-сервер для редактирования реплик лекций.

Установка: pip install flask
Запуск:    python editor_server.py
Страницы открывать через: python -m http.server 8080 --directory output
"""

import json
import copy
import subprocess
from pathlib import Path
from flask import Flask, request, jsonify
from datetime import datetime

ROOT     = Path(__file__).parent
DATA_DIR = ROOT / "data"
BUILD_PY = ROOT / "build.py"

app = Flask(__name__)

@app.after_request
def add_cors(response):
    response.headers["Access-Control-Allow-Origin"] = "*"
    response.headers["Access-Control-Allow-Headers"] = "Content-Type"
    response.headers["Access-Control-Allow-Methods"] = "POST, OPTIONS"
    return response


def apply_patch(lecture: dict, patch: list) -> dict:
    """
    patch — список правок:
    [
      {
        "section_id":  "intro",
        "block_index": 0,      # индекс dialog-блока внутри section.content
        "turn_index":  0,      # индекс реплики внутри turns
        "para_index":  null,   # null → реплика-строка; число → абзац в списке
        "text":        "новый текст"
      },
      ...
    ]
    """
    data = copy.deepcopy(lecture)
    section_map = {s["id"]: s for s in data["sections"]}

    for edit in patch:
        section = section_map.get(edit["section_id"])
        if section is None:
            continue
        block = section["content"][edit["block_index"]]
        if block.get("type") != "dialog":
            continue
        turn = block["turns"][edit["turn_index"]]
        para_index = edit.get("para_index")

        if para_index is None:
            turn["text"] = edit["text"]
        else:
            if isinstance(turn["text"], list):
                turn["text"][para_index] = edit["text"]

    return data


def rebuild(json_path: Path):
    result = subprocess.run(
        ["python3", str(BUILD_PY), str(json_path)],
        capture_output=True, text=True
    )
    return result.returncode == 0, result.stdout + result.stderr


@app.route("/api/save", methods=["POST", "OPTIONS"])
def save():
    if request.method == "OPTIONS":
        return "", 204

    body     = request.get_json(force=True)
    filename = body.get("file")    # "00.json"
    patch    = body.get("patch")   # список правок

    if not filename or patch is None:
        return jsonify({"ok": False, "error": "Нужны поля file и patch"}), 400

    json_path = DATA_DIR / filename
    if not json_path.exists():
        return jsonify({"ok": False, "error": f"Файл {filename} не найден"}), 404

    with open(json_path, encoding="utf-8") as f:
        lecture = json.load(f)

    # Резервная копия перед сохранением
    ts = datetime.now().strftime("%Y%m%d_%H%M%S")
    backup = json_path.with_suffix(f".{ts}.bak.json")
    backup.write_text(json.dumps(lecture, ensure_ascii=False, indent=2), encoding="utf-8")

    updated = apply_patch(lecture, patch)
    json_path.write_text(json.dumps(updated, ensure_ascii=False, indent=2), encoding="utf-8")

    ok, log = rebuild(json_path)
    if not ok:
        return jsonify({"ok": False, "error": "build.py завершился с ошибкой", "log": log}), 500

    return jsonify({"ok": True, "log": log})


if __name__ == "__main__":
    app.run(host="127.0.0.1", port=5000, debug=True)