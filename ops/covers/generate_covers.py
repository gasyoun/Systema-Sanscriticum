#!/usr/bin/env python3
"""H4514 — Auto-generated lesson covers: render dated cover cards and upload
them to each course's Google Drive folder so ZOOM 1.4 ("Обложка найдена?")
never depends on a human.

Sources:
  - upcoming lessons: mysql `laravel` on .92, reached over SSH from .91
    (root@192.168.200.92, key auth; DB password read from remote .env,
    never leaves .92)
  - meeting_id -> drive_folder_id: Automation_DB sheet, Settings tab
    (first grid), exported as CSV through the Drive API
  - render: Pillow, house palette (maroon/gold/cream/red, serif)
  - upload: Google Drive API (n8n "Google Drive account" oauth cred,
    exported decrypted to /opt/covers/creds/gdrive.json, 0600)

Idempotent: a file named YYYY-MM-DD.jpg that already exists in the target
folder is NEVER overwritten (skip), matching the ZOOM 1.4 search query.

Usage:
  generate_covers.py --dry                # plan only: lesson -> folder -> exists/missing
  generate_covers.py --run                # render + upload missing covers
  generate_covers.py --render-sample OUT.jpg --title "Текст" --date 2026-09-17
"""

import argparse
import csv
import io
import json
import os
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import date, datetime, timedelta

CONFIG = {
    "db_ssh_host": "root@192.168.200.92",
    "db_name": "laravel",
    "db_env_path": "/var/www/html/.env",
    "gdrive_cred": "/opt/covers/creds/gdrive.json",
    "token_cache": "/opt/covers/creds/token.json",
    "settings_sheet_id": "1Z8CndhrmEbgRBiFZvt2Zwr7JM4zFBLqyc9XOVggFiKI",
    "days": 8,
}

WEEKDAYS_RU = ["Понедельник", "Вторник", "Среда", "Четверг", "Пятница",
               "Суббота", "Воскресенье"]
MONTHS_RU_GEN = ["января", "февраля", "марта", "апреля", "мая", "июня",
                 "июля", "августа", "сентября", "октября", "ноября",
                 "декабря"]

# ---------------------------------------------------------------- utilities


def log(msg):
    print(f"{datetime.now().isoformat(timespec='seconds')} {msg}", flush=True)


def http(url, method="GET", body=None, headers=None, timeout=60):
    req = urllib.request.Request(url, data=body, method=method,
                                 headers=headers or {})
    last = None
    for attempt in range(3):
        try:
            with urllib.request.urlopen(req, timeout=timeout) as r:
                return r.read()
        except urllib.error.HTTPError as e:
            payload = e.read()
            if e.code < 500 and e.code != 429:
                raise RuntimeError(f"HTTP {e.code}: {payload[:400]}") from e
            last = e
        except urllib.error.URLError as e:
            last = e
        time.sleep(5 * (attempt + 1))
    raise RuntimeError(f"HTTP failed after retries: {last}")


def api(url, params=None, token="", method="GET", body=None, headers=None):
    if params:
        url += "?" + urllib.parse.urlencode(params)
    h = dict(headers or {})
    h["Authorization"] = "Bearer " + token
    return http(url, method=method, body=body, headers=h)

# ------------------------------------------------------------------- tokens


def load_token(cfg):
    """Return a fresh Drive access token, refreshing via the exported
    n8n oauth credential when the cache is missing or stale."""
    try:
        cache = json.load(open(cfg["token_cache"]))
        if cache.get("expires_at", 0) > time.time() + 120:
            return cache["access_token"]
    except Exception:
        pass
    cred = json.load(open(cfg["gdrive_cred"]))
    data = cred.get("data") or cred
    tok = data.get("oauthTokenData") or {}
    refresh = tok.get("refresh_token")
    if not refresh:
        raise RuntimeError("no refresh_token in exported credential")
    body = urllib.parse.urlencode({
        "client_id": data.get("clientId") or data.get("client_id"),
        "client_secret": data.get("clientSecret") or data.get("client_secret"),
        "refresh_token": refresh,
        "grant_type": "refresh_token",
    }).encode()
    resp = json.loads(http("https://oauth2.googleapis.com/token",
                           method="POST", body=body, timeout=30))
    json.dump({"access_token": resp["access_token"],
               "expires_at": time.time() + resp.get("expires_in", 3600)},
              open(cfg["token_cache"], "w"))
    log("drive token refreshed")
    return resp["access_token"]

# ------------------------------------------------------------------ lessons


def fetch_lessons(cfg, days):
    """Future schedules joined to their course zoom meeting id.
    Returns list of dicts: date, time, meeting_id, course_title."""
    sql = (
        "SELECT DATE_FORMAT(s.start,'%Y-%m-%d'), DATE_FORMAT(s.start,'%H:%i'), "
        "COALESCE(NULLIF(s.zoom_meeting_id,''), c.zoom_meeting_id, ''), "
        "COALESCE(NULLIF(c.title,''), s.title, '') "
        "FROM schedules s LEFT JOIN courses c ON c.id = s.course_id "
        f"WHERE s.start >= CURDATE() AND s.start < CURDATE() + INTERVAL {int(days)} DAY "
        "ORDER BY s.start"
    )
    remote = (
        'PW=$(grep "^DB_PASSWORD=" ' + cfg["db_env_path"] + ' | cut -d= -f2-); '
        f'mysql --default-character-set=utf8mb4 -usail -p"$PW" {cfg["db_name"]} '
        f'-B -N -e "{sql}"'
    )
    out = subprocess.run(
        ["ssh", "-o", "BatchMode=yes", "-o", "ConnectTimeout=10",
         cfg["db_ssh_host"], remote],
        capture_output=True, text=True, timeout=90, check=True)
    rows = []
    for line in out.stdout.splitlines():
        parts = line.split("\t")
        if len(parts) != 4:
            continue
        d, t, mid, title = parts
        if mid:
            rows.append({"date": d, "time": t, "meeting_id": mid,
                         "course_title": title})
    return rows


def fetch_folder_map(cfg, token):
    """Settings tab (first grid) of Automation_DB as CSV via Drive export."""
    raw = api(f"https://www.googleapis.com/drive/v3/files/"
              f"{cfg['settings_sheet_id']}/export",
              params={"mimeType": "text/csv"}, token=token)
    mapping = {}
    for row in csv.DictReader(io.StringIO(raw.decode("utf-8", "replace"))):
        mid = (row.get("meeting_id") or "").strip()
        folder = (row.get("drive_folder_id") or "").strip()
        if mid and folder:
            mapping[mid] = folder
    return mapping

# -------------------------------------------------------------------- drive


def cover_exists(cfg, token, folder, ymd):
    q = (f"name = '{ymd}.jpg' and trashed = false and "
         f"'{folder}' in parents")
    raw = api("https://www.googleapis.com/drive/v3/files",
              params={"q": q, "fields": "files(id,name)", "pageSize": 5},
              token=token)
    return bool(json.loads(raw).get("files"))


def upload_cover(cfg, token, folder, ymd, jpg_bytes):
    boundary = "h4514cover"
    meta = json.dumps({"name": f"{ymd}.jpg", "parents": [folder]})
    parts = []
    parts.append(f"--{boundary}\r\nContent-Type: application/json; "
                 f"charset=UTF-8\r\n\r\n{meta}\r\n")
    body = "".join(parts).encode("utf-8")
    body += (f"--{boundary}\r\nContent-Type: image/jpeg\r\n\r\n").encode()
    body += jpg_bytes
    body += f"\r\n--{boundary}--".encode()
    api("https://www.googleapis.com/upload/drive/v3/files",
        params={"uploadType": "multipart", "fields": "id"},
        token=token, method="POST", body=body,
        headers={"Content-Type":
                 f"multipart/related; boundary={boundary}"})


def list_folder_covers(cfg, token, folder):
    raw = api("https://www.googleapis.com/drive/v3/files",
              params={"q": f"'{folder}' in parents and trashed = false "
                           f"and mimeType = 'image/jpeg'",
                     "fields": "files(name)", "pageSize": 200},
              token=token)
    return sorted(f["name"] for f in json.loads(raw).get("files", []))

# ------------------------------------------------------------------- render

PALETTE = {
    "bg_top": (0x5a, 0x1e, 0x10),
    "bg_bottom": (0x24, 0x0b, 0x05),
    "gold": (0xe8, 0xb2, 0x3a),
    "gold_bright": (0xf5, 0xc5, 0x18),
    "cream": (0xf3, 0xe6, 0xc8),
    "red": (0xc8, 0x1e, 0x1e),
    "beige": (0xec, 0xe0, 0xc6),
    "maroon_text": (0x3d, 0x13, 0x0a),
}


def render_cover(out_path, title, ymd, time_str=""):
    from PIL import Image, ImageDraw, ImageFont
    p = PALETTE
    W, H = 1920, 1080
    img = Image.new("RGB", (W, H), p["bg_top"])
    d = ImageDraw.Draw(img)
    # vertical gradient
    for y in range(H):
        k = y / (H - 1)
        c = tuple(round(p["bg_top"][i] * (1 - k) + p["bg_bottom"][i] * k)
                  for i in range(3))
        d.line([(0, y), (W, y)], fill=c)
    # gold hairline frame
    d.rectangle([28, 28, W - 28, H - 28], outline=p["gold"], width=3)
    d.rectangle([40, 40, W - 40, H - 40], outline=0x7a4a1c, width=1)

    serif_b = "/usr/share/fonts/truetype/dejavu/DejaVuSerif-Bold.ttf"
    sans = "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"
    sans_b = "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"

    # top-right ORS wordmark
    f_mark = ImageFont.truetype(sans_b, 26)
    mark = "ОБЩЕСТВО\nРЕВНИТЕЛЕЙ САНСКРИТА"
    d.multiline_text((W - 70, 78), mark, font=f_mark, fill=p["cream"],
                     anchor="ra", align="right", spacing=6)
    d.polygon([(W - 150, 52), (W - 130, 30), (W - 110, 52), (W - 130, 74)],
              outline=p["gold"], width=2)

    # small letterspaced label
    f_label = ImageFont.truetype(sans_b, 34)
    d.text((110, 150), "З А Н Я Т И Е   К У Р С А", font=f_label,
           fill=p["gold"])

    # course title: shrink-to-fit, up to 4 lines, width ~1050px
    x0, y0, x1, y1 = 100, 220, 1220, 700
    wrapped = []
    size = 120
    f_title = None
    while size >= 48:
        f_title = ImageFont.truetype(serif_b, size)
        words = title.split()
        lines, cur = [], ""
        for w in words:
            cand = (cur + " " + w).strip()
            if d.textlength(cand, font=f_title) <= x1 - x0:
                cur = cand
            else:
                if cur:
                    lines.append(cur)
                cur = w
        if cur:
            lines.append(cur)
        # re-wrap into hard lines of max 2 words? keep greedy result
        if len(lines) * int(size * 1.28) <= y1 - y0:
            wrapped = lines[:4]
            break
        size -= 6
    ty = y0
    for ln in wrapped:
        d.text((x0 + 4, ty + 4), ln, font=f_title, fill=(0x18, 0x05, 0x02))
        d.text((x0, ty), ln, font=f_title, fill=p["gold_bright"])
        ty += int(f_title.size * 1.28)

    # chips bottom-left: beige weekday + red date
    ymd_dt = datetime.strptime(ymd, "%Y-%m-%d")
    wd = WEEKDAYS_RU[ymd_dt.weekday()].upper()
    dmy = ymd_dt.strftime("%d.%m.%Y")
    f_chip = ImageFont.truetype(sans_b, 52)
    bx, by = 110, 800
    w_wd = d.textlength(wd, font=f_chip)
    d.rounded_rectangle([bx, by, bx + w_wd + 90, by + 110], radius=14,
                        fill=p["beige"])
    d.text((bx + 45, by + 55), wd, font=f_chip, fill=p["maroon_text"],
           anchor="lm")
    rx = bx + w_wd + 110
    w_dt = d.textlength(dmy, font=f_chip)
    d.rounded_rectangle([rx, by, rx + w_dt + 90, by + 110], radius=14,
                        fill=p["red"])
    d.text((rx + 45, by + 55), dmy, font=f_chip, fill="white", anchor="lm")
    if time_str:
        f_time = ImageFont.truetype(sans, 44)
        d.text((rx + w_dt + 130, by + 55), f"{time_str} МСК", font=f_time,
               fill=p["cream"], anchor="lm")

    # right medallion: thin gold double circle with the date
    cx, cy, r = 1520, 560, 300
    for rr, wdt in ((r, 6), (r - 26, 2)):
        d.ellipse([cx - rr, cy - rr, cx + rr, cy + rr], outline=p["gold"],
                  width=wdt)
    f_med = ImageFont.truetype(serif_b, 86)
    while d.textlength(dmy, font=f_med) > 2 * (r - 70):
        f_med = ImageFont.truetype(serif_b, f_med.size - 4)
    d.text((cx, cy - 34), dmy, font=f_med, fill=p["gold_bright"],
           anchor="mm")
    f_med2 = ImageFont.truetype(sans, 40)
    d.text((cx, cy + 52), f"{WEEKDAYS_RU[ymd_dt.weekday()].lower()}, "
           f"{ymd_dt.day} {MONTHS_RU_GEN[ymd_dt.month - 1]}",
           font=f_med2, fill=p["cream"], anchor="mm")

    # footer
    f_foot = ImageFont.truetype(sans_b, 44)
    d.text((110, H - 96), "samskrtam.ru", font=f_foot, fill=p["cream"])

    img.save(out_path, "JPEG", quality=88)
    return out_path

# --------------------------------------------------------------------- main


def main():
    ap = argparse.ArgumentParser()
    g = ap.add_mutually_exclusive_group(required=True)
    g.add_argument("--dry", action="store_true")
    g.add_argument("--run", action="store_true")
    g.add_argument("--render-sample", metavar="OUT.jpg")
    ap.add_argument("--title", default="Грамматика хинди, начальная №5")
    ap.add_argument("--date", dest="sample_date", default=None)
    ap.add_argument("--days", type=int, default=CONFIG["days"])
    args = ap.parse_args()

    if args.render_sample:
        ymd = args.sample_date or date.today().isoformat()
        render_cover(args.render_sample, args.title, ymd)
        log(f"sample rendered: {args.render_sample}")
        return 0

    cfg = dict(CONFIG, days=args.days)
    failures = []

    # stage 1: lessons
    lessons = []
    for attempt in range(5):
        try:
            lessons = fetch_lessons(cfg, cfg["days"])
            break
        except Exception as e:
            log(f"[db] attempt {attempt + 1} failed: {e}")
            time.sleep(10)
    if not lessons:
        log("[db] no lessons fetched — nothing to do")
        return 1
    log(f"[db] {len(lessons)} lesson rows in next {cfg['days']} days")

    # stage 2: folder map
    token = load_token(cfg)
    mapping = fetch_folder_map(cfg, token)
    log(f"[sheets] {len(mapping)} meeting->folder rows")

    # stage 3: plan
    plan, unmapped = {}, []
    for les in lessons:
        key = (les["meeting_id"], les["date"])
        if key in plan:
            continue
        folder = mapping.get(les["meeting_id"])
        if not folder:
            unmapped.append(les)
        else:
            plan[key] = {**les, "folder": folder}
    for les in unmapped:
        log(f"[plan] UNMAPPED (no Settings row): {les['date']} "
            f"{les['time']} meeting={les['meeting_id']} "
            f"«{les['course_title']}» — skipped")

    # stage 4: existence check
    todo, missing = [], 0
    for key, item in sorted(plan.items(), key=lambda kv: (kv[1]["date"],
                                                          kv[1]["time"])):
        try:
            exists = cover_exists(cfg, token, item["folder"], item["date"])
        except Exception as e:
            log(f"[drive] exists-check failed {key}: {e}")
            failures.append((item, "exists-check"))
            continue
        log(f"[plan] {item['date']} {item['time']} "
            f"«{item['course_title'][:48]}» -> {item['folder'][:12]}.. "
            f"{'EXISTS, skip' if exists else 'MISSING'}")
        if not exists:
            missing += 1
            if not args.dry:
                todo.append(item)

    if args.dry:
        log(f"[dry] missing covers: {missing}; "
            f"unmapped skipped: {len(unmapped)}; done")
        return 0

    # stage 5: render + upload
    ok = 0
    for item in todo:
        try:
            fd, tmp = tempfile.mkstemp(suffix=".jpg")
            os.close(fd)
            render_cover(tmp, item["course_title"], item["date"],
                         item["time"])
            jpg = open(tmp, "rb").read()
            os.unlink(tmp)
            upload_cover(cfg, token, item["folder"], item["date"], jpg)
            log(f"[upload] {item['date']}.jpg -> {item['folder'][:12]}.. ok")
            ok += 1
        except Exception as e:
            log(f"[upload] FAILED {item['date']} -> {item['folder'][:12]}..:"
                f" {e}")
            failures.append((item, str(e)))
        time.sleep(1)

    # stage 6: evidence
    folders = sorted({i["folder"] for i in todo})
    for f in folders:
        try:
            names = list_folder_covers(cfg, token, f)
            log(f"[evidence] {f[:12]}.. covers: {', '.join(names[-6:])}")
        except Exception as e:
            log(f"[evidence] list failed {f[:12]}..: {e}")

    log(f"[done] uploaded={ok} skipped_unmapped={len(unmapped)} "
        f"failures={len(failures)}")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
