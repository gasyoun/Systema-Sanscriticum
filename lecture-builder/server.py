"""
server.py — Flask-микросервис для сборки лекций.

Эндпоинты:
  GET  /health                 — проверка живости
  POST /preprocess             — PDF + транскрипт → JPG слайды + data.json
  POST /render                 — data.json → HTML

Запуск:
    pip install -r requirements.txt
    python server.py             # 127.0.0.1:5001

Авторизация:
    Если задана переменная окружения LECTURE_BUILDER_TOKEN, каждый запрос
    обязан содержать заголовок X-Builder-Token с тем же значением.

Контракт обмена (file-based handoff):
    Laravel и сервис делят filesystem (одна машина или общий volume).
    Laravel передаёт абсолютный working_dir, сервис читает/пишет в него.
    Большие файлы (PDF, JPG, HTML) НЕ передаются по HTTP.
"""

import json
import os
import logging
import traceback
from datetime import datetime
from pathlib import Path

from flask import Flask, request, jsonify

import pipeline
import ai as ai_mod

logging.basicConfig(level=logging.INFO, format='%(asctime)s %(levelname)s %(message)s')
log = logging.getLogger('lecture-builder')

app = Flask(__name__)

AUTH_TOKEN = os.environ.get('LECTURE_BUILDER_TOKEN')


def _check_auth() -> bool:
    if not AUTH_TOKEN:
        return True
    return request.headers.get('X-Builder-Token') == AUTH_TOKEN


def _abs_dir(value: str) -> Path:
    p = Path(value).resolve()
    return p


@app.before_request
def auth_gate():
    if request.path == '/health':
        return None
    if not _check_auth():
        return jsonify({'ok': False, 'error': 'unauthorized'}), 401
    return None


@app.route('/health', methods=['GET'])
def health():
    return jsonify({'ok': True, 'service': 'lecture-builder'})


@app.route('/preprocess', methods=['POST'])
def preprocess():
    body = request.get_json(force=True, silent=True) or {}

    working_dir_raw = body.get('working_dir')
    raw_transcript_rel = body.get('raw_transcript')
    raw_pdf_rel = body.get('raw_pdf')
    lesson_number = int(body.get('lesson_number', 0) or 0)
    meta_override = body.get('meta') or None

    if not working_dir_raw or not raw_transcript_rel:
        return jsonify({'ok': False, 'error': 'нужны поля working_dir и raw_transcript'}), 400

    working_dir = _abs_dir(working_dir_raw)
    if not working_dir.exists():
        return jsonify({'ok': False, 'error': f'working_dir не существует: {working_dir}'}), 400

    transcript_path = working_dir / raw_transcript_rel
    if not transcript_path.exists():
        return jsonify({'ok': False, 'error': f'транскрипт не найден: {transcript_path}'}), 400

    pdf_path = (working_dir / raw_pdf_rel) if raw_pdf_rel else None
    if pdf_path is not None and not pdf_path.exists():
        return jsonify({'ok': False, 'error': f'PDF не найден: {pdf_path}'}), 400

    try:
        result = pipeline.preprocess(
            working_dir=working_dir,
            raw_pdf=pdf_path,
            raw_transcript=transcript_path,
            lesson_number=lesson_number,
            meta_override=meta_override,
        )
        log.info('preprocess ok working_dir=%s slides=%d', working_dir, len(result.get('slides', [])))
        return jsonify({'ok': True, **result})
    except Exception as e:
        log.exception('preprocess failed')
        return jsonify({
            'ok': False,
            'error': str(e),
            'trace': traceback.format_exc(),
        }), 500


@app.route('/render', methods=['POST'])
def render():
    body = request.get_json(force=True, silent=True) or {}

    working_dir_raw = body.get('working_dir')
    data_json = body.get('data_json', 'data.json')
    template_name = body.get('template', 'template.html.j2')

    if not working_dir_raw:
        return jsonify({'ok': False, 'error': 'нужно поле working_dir'}), 400

    working_dir = _abs_dir(working_dir_raw)
    if not working_dir.exists():
        return jsonify({'ok': False, 'error': f'working_dir не существует: {working_dir}'}), 400

    try:
        result = pipeline.render(
            working_dir=working_dir,
            data_json=data_json,
            template_name=template_name,
        )
        log.info('render ok working_dir=%s output=%s', working_dir, result.get('output'))
        return jsonify({'ok': True, **result})
    except Exception as e:
        log.exception('render failed')
        return jsonify({
            'ok': False,
            'error': str(e),
            'trace': traceback.format_exc(),
        }), 500


# ── AI-эндпоинты ─────────────────────────────────────────────────────────────

def _load_data_json(working_dir: Path, data_json_rel: str = 'data.json') -> tuple[Path, dict]:
    p = working_dir / data_json_rel
    if not p.exists():
        raise RuntimeError(f'data.json не найден: {p}')
    return p, json.loads(p.read_text(encoding='utf-8'))


def _backup_and_save(data_path: Path, lecture: dict) -> str:
    """Сохраняет копию текущего data.json в backups/ и пишет обновлённый."""
    backups = data_path.parent / 'backups'
    backups.mkdir(parents=True, exist_ok=True)
    ts = datetime.now().strftime('%Y%m%d_%H%M%S')
    backup_path = backups / f'{ts}_ai.json'
    backup_path.write_text(data_path.read_text(encoding='utf-8'), encoding='utf-8')
    data_path.write_text(json.dumps(lecture, ensure_ascii=False, indent=2), encoding='utf-8')
    return backup_path.name


def _ai_endpoint(task: callable, build_args: callable):
    """
    Универсальный обработчик AI-запроса.
    task(lecture, **kwargs) -> {ok, lecture, summary, ...}
    build_args(body, lecture) -> dict дополнительных kwargs для task
    """
    body = request.get_json(force=True, silent=True) or {}
    working_dir_raw = body.get('working_dir')
    apply_changes = bool(body.get('apply', False))

    if not working_dir_raw:
        return jsonify({'ok': False, 'error': 'нужно поле working_dir'}), 400

    working_dir = _abs_dir(working_dir_raw)
    if not working_dir.exists():
        return jsonify({'ok': False, 'error': f'working_dir не существует: {working_dir}'}), 400

    try:
        data_path, lecture = _load_data_json(working_dir)
        kwargs = build_args(body, lecture)
        result = task(lecture, **kwargs)

        if not result.get('ok'):
            return jsonify(result), 400

        if apply_changes:
            backup_name = _backup_and_save(data_path, result['lecture'])
            result['backup'] = backup_name
        else:
            # Если не применяем — возвращаем превью без записи
            result['preview_only'] = True

        # lecture в ответе может быть большим — не возвращаем по умолчанию
        if not body.get('include_lecture'):
            result.pop('lecture', None)

        return jsonify(result)
    except Exception as e:
        log.exception('AI task failed')
        return jsonify({
            'ok': False,
            'error': str(e),
            'trace': traceback.format_exc(),
        }), 500


@app.route('/ai/structure', methods=['POST'])
def ai_structure():
    return _ai_endpoint(
        ai_mod.structure_sections,
        lambda body, _: {'user_hint': body.get('hint') or ''},
    )


@app.route('/ai/correct', methods=['POST'])
def ai_correct():
    return _ai_endpoint(
        ai_mod.correct_transcript,
        lambda body, _: {
            'user_hint': body.get('hint') or '',
            'max_paragraphs': int(body.get('max_paragraphs') or 0),
        },
    )


@app.route('/ai/place_slides', methods=['POST'])
def ai_place_slides():
    def args(body, lecture):
        # Берём slides из working_dir/slides/
        wd = _abs_dir(body['working_dir'])
        slides_dir = wd / 'slides'
        if slides_dir.is_dir():
            slides = sorted(p.name for p in slides_dir.glob('*.jpg'))
        else:
            slides = body.get('slides') or []
        return {'slide_filenames': slides, 'user_hint': body.get('hint') or ''}

    return _ai_endpoint(ai_mod.place_slides, args)


@app.route('/ai/timecodes', methods=['POST'])
def ai_timecodes():
    def args(body, lecture):
        yt_url = body.get('yt_url') or (lecture.get('meta', {}).get('video', {}).get('youtube'))
        return {'yt_url': yt_url or '', 'user_hint': body.get('hint') or ''}

    return _ai_endpoint(ai_mod.verify_timecodes, args)


if __name__ == '__main__':
    port = int(os.environ.get('LECTURE_BUILDER_PORT', '5001'))
    host = os.environ.get('LECTURE_BUILDER_HOST', '127.0.0.1')
    debug = os.environ.get('LECTURE_BUILDER_DEBUG', '0') == '1'
    log.info('Listening on %s:%d (auth=%s)', host, port, 'on' if AUTH_TOKEN else 'off')
    app.run(host=host, port=port, debug=debug)
