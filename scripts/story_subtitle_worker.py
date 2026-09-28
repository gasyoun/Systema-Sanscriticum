#!/usr/bin/env python3
"""Air fallback for Telegram Story subtitle drafts; Ivan's Tailscale Whisper is tried first.

Runs outside production. The production server stages only the public-channel
video and accepts SRT over the existing SSH connection; no bot or Whisper key is
copied to it. Safe to run repeatedly: the server lists only pending drafts.
"""

from __future__ import annotations

import argparse
import http.client
import ipaddress
import json
import os
import stat
import subprocess
import sys
import tempfile
import time
import urllib.parse
from pathlib import Path

def load_local_config() -> None:
    path = Path.home() / ".config/samskrte/story-subtitles.env"
    if not path.is_file():
        return
    if stat.S_IMODE(path.stat().st_mode) & 0o077:
        print("Story worker config is not owner-only; ignoring Ivan settings", file=sys.stderr)
        return
    for line in path.read_text(encoding="utf-8").splitlines():
        if "=" not in line or line.lstrip().startswith("#"):
            continue
        key, value = line.split("=", 1)
        if key in ("STORY_IVAN_WHISPER_URL", "WHISPER_API_KEY") and key not in os.environ:
            os.environ[key] = value.strip()


load_local_config()

SERVER = "root@193.232.229.92"
SERVER_APP = "/var/www/html"
SOURCE_PREFIX = SERVER_APP + "/storage/app/telegram-business-story-subtitles/"
DEFAULT_MODEL = Path.home() / ".whisper/models/ggml-small.bin"
IVAN_URL = os.environ.get("STORY_IVAN_WHISPER_URL", "").rstrip("/")


def run(*args: str, input_bytes: bytes | None = None, timeout: int = 120) -> str:
    result = subprocess.run(args, input=input_bytes, capture_output=True, timeout=timeout, check=False)
    if result.returncode:
        raise RuntimeError(f"{args[0]} exited {result.returncode}: {result.stderr.decode(errors='replace')[-300:]}")
    return result.stdout.decode("utf-8", errors="replace")


def server_command(*args: str, input_bytes: bytes | None = None) -> str:
    # args are fixed command tokens or validated integers, never shell text from Telegram.
    return run("ssh", "-o", "BatchMode=yes", "-o", "ConnectTimeout=10", SERVER,
               "cd", SERVER_APP, "&&", "php", "artisan", "telegram-business:story-subtitles",
               *args, input_bytes=input_bytes, timeout=120)


def whisper_key() -> str | None:
    key = os.environ.get("WHISPER_API_KEY", "").strip()
    if key:
        return key
    path = Path.home() / ".whisper_api_key"
    if not path.is_file():
        return None
    if stat.S_IMODE(path.stat().st_mode) & 0o077:
        print("Whisper API key file is not owner-only; using Air fallback", file=sys.stderr)
        return None
    return path.read_text(encoding="utf-8").strip()


def request_json(url: str, key: str, payload: dict | None = None) -> dict:
    data = None if payload is None else json.dumps(payload).encode("utf-8")
    headers = {"Authorization": f"Bearer {key}"}
    if data is not None:
        headers["Content-Type"] = "application/json"
    target = urllib.parse.urlparse(url)
    if target.scheme not in ("http", "https") or not target.hostname:
        raise ValueError("Invalid Whisper HTTP endpoint")
    connection_type = http.client.HTTPSConnection if target.scheme == "https" else http.client.HTTPConnection
    connection = connection_type(target.hostname, target.port, timeout=30)
    try:
        path = target.path + ("?" + target.query if target.query else "")
        connection.request("POST" if data is not None else "GET", path, body=data, headers=headers)
        response = connection.getresponse()
        if response.status != 200:
            raise RuntimeError("Whisper HTTP request failed")
        return json.loads(response.read(5_000_000))
    finally:
        connection.close()


def srt_time(seconds: float) -> str:
    milliseconds = max(0, round(seconds * 1000))
    hours, rem = divmod(milliseconds, 3_600_000)
    minutes, rem = divmod(rem, 60_000)
    secs, millis = divmod(rem, 1000)
    return f"{hours:02d}:{minutes:02d}:{secs:02d},{millis:03d}"


def ivan_transcribe(source_url: str) -> str | None:
    key = whisper_key()
    if not key or not IVAN_URL:
        return None
    target = urllib.parse.urlparse(IVAN_URL)
    if target.scheme not in ("http", "https") or not target.hostname:
        print("Ivan Whisper URL is invalid; using Air fallback", file=sys.stderr)
        return None
    try:
        tailnet_ip = ipaddress.ip_address(target.hostname) in ipaddress.ip_network("100.64.0.0/10")
    except ValueError:
        tailnet_ip = False
    if not tailnet_ip and not target.hostname.endswith(".ts.net"):
        print("Ivan Whisper endpoint is outside Tailscale; using Air fallback", file=sys.stderr)
        return None
    try:
        job = request_json(IVAN_URL + "/transcribe", key,
                           {"url": source_url, "model": "large-v3", "language": "ru"})
        job_id = str(job["job_id"])
        deadline = time.monotonic() + 45 * 60
        while time.monotonic() < deadline:
            state = request_json(IVAN_URL + "/jobs/" + job_id, key)
            if state.get("status") == "completed":
                segments = state.get("segments") or []
                if state.get("segments_omitted") or not segments:
                    segments = []
                    offset = 0
                    while True:
                        page = request_json(IVAN_URL + f"/jobs/{job_id}/segments?offset={offset}&limit=1000", key)
                        chunk = page.get("segments", [])
                        segments.extend(chunk)
                        if len(chunk) < 1000:
                            break
                        offset += len(chunk)
                blocks = []
                for segment in segments:
                    text = str(segment.get("text", "")).strip()
                    if text:
                        blocks.append(f"{len(blocks) + 1}\n"
                                      f"{srt_time(float(segment['start']))} --> {srt_time(float(segment['end']))}\n"
                                      f"{text}")
                return "\n\n".join(blocks) + "\n" if blocks else None
            if state.get("status") in ("failed", "error"):
                raise RuntimeError("Ivan Whisper job failed")
            time.sleep(20)
        raise RuntimeError("Ivan Whisper timed out")
    except (OSError, KeyError, ValueError, RuntimeError) as exc:
        # Never print the signed media URL, API key, or remote response body.
        print(f"Ivan Whisper unavailable ({type(exc).__name__}); using Air fallback", file=sys.stderr)
        return None


def air_transcribe(video: Path, scratch: Path, model: Path) -> str:
    if not model.is_file():
        raise RuntimeError(f"Air Whisper model missing: {model}")
    wav = scratch / "audio.wav"
    run("/opt/homebrew/bin/ffmpeg", "-hide_banner", "-loglevel", "error", "-y",
        "-i", str(video), "-vn", "-ar", "16000", "-ac", "1", str(wav), timeout=300)
    output_base = scratch / "subtitles"
    # GPU allocation of large-v3-turbo failed on this Air; the small CPU model
    # passed a live smoke test and is only the fallback behind Ivan's full ASR.
    run("/opt/homebrew/bin/whisper-cli", "-ng", "-m", str(model), "-f", str(wav),
        "-l", "auto", "-osrt", "-of", str(output_base), timeout=3600)
    srt = output_base.with_suffix(".srt")
    if not srt.is_file():
        raise RuntimeError("Air Whisper produced no SRT")
    return srt.read_text(encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--model", type=Path, default=DEFAULT_MODEL)
    args = parser.parse_args()

    queue = json.loads(server_command("queue", "--limit=1"))
    if not queue:
        return 0
    job = queue[0]
    job_id = int(job["id"])
    source_path = str(job["source_path"])
    if source_path != f"{SOURCE_PREFIX}{job_id}.mp4":
        raise RuntimeError("Unexpected Story source path")

    with tempfile.TemporaryDirectory(prefix="story-subtitles-") as directory:
        scratch = Path(directory)
        video = scratch / "source.mp4"
        run("scp", "-q", "-o", "BatchMode=yes", "-o", "ConnectTimeout=10",
            f"{SERVER}:{source_path}", str(video), timeout=300)
        srt = ivan_transcribe(str(job["source_url"]))
        worker = "ivan" if srt else "air"
        if not srt:
            srt = air_transcribe(video, scratch, args.model)
        if not srt.strip():
            server_command("skip", str(job_id))
            print(f"Story #{job_id}: no speech, original queued")
            return 0
        server_command("import", str(job_id), f"--worker={worker}", input_bytes=srt.encode("utf-8"))
        print(f"Story #{job_id}: subtitle draft ready from {worker}")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (OSError, ValueError, RuntimeError, subprocess.TimeoutExpired) as exc:
        print(f"Story subtitle worker failed: {exc}", file=sys.stderr)
        raise SystemExit(1)
