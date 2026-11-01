#!/usr/bin/env python3
"""Tests for `scripts/indexnow_submit.py` — no network, no real submission.

    python3 -m unittest scripts/test_indexnow_submit.py -v
"""

import contextlib
import io
import json
import os
import sys
import tempfile
import unittest
import urllib.error
from unittest import mock

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import indexnow_submit as indexnow  # noqa: E402

KEY = "3d8b1b068ee599d1f5aa9ae9c63d0968"
KEY_LOCATION = f"https://samskrte.ru/{KEY}.txt"


class FakeResponse:
    def __init__(self, status=200, body=b""):
        self.status = status
        self._body = body

    def read(self):
        return self._body

    def getcode(self):
        return self.status

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        return False


class HostTest(unittest.TestCase):
    def test_normalizes_scheme_and_case(self):
        self.assertEqual(indexnow.normalize_host("HTTPS://Samskrte.RU/online"), "samskrte.ru")

    def test_accepts_apex_and_subdomain(self):
        self.assertTrue(indexnow.belongs_to_host("https://samskrte.ru/k/a", "samskrte.ru"))
        self.assertTrue(indexnow.belongs_to_host("https://www.samskrte.ru/k/a", "samskrte.ru"))

    def test_rejects_lookalike_suffix(self):
        self.assertFalse(indexnow.belongs_to_host("https://evilsamskrte.ru/k/a", "samskrte.ru"))

    def test_rejects_non_http(self):
        self.assertFalse(indexnow.belongs_to_host("ftp://samskrte.ru/k/a", "samskrte.ru"))


class LoadUrlsTest(unittest.TestCase):
    def test_reads_file_ignores_comments_and_dedupes(self):
        with tempfile.NamedTemporaryFile("w", encoding="utf-8", suffix=".txt", delete=False) as fh:
            fh.write("# comment\n\nhttps://samskrte.ru/a\nhttps://samskrte.ru/b\nhttps://samskrte.ru/a\n")
            path = fh.name
        try:
            urls = indexnow.load_urls(
                ["https://samskrte.ru/b", "https://samskrte.ru/c"],
                path,
            )
        finally:
            os.unlink(path)

        self.assertEqual(
            urls,
            [
                "https://samskrte.ru/b",
                "https://samskrte.ru/c",
                "https://samskrte.ru/a",
            ],
        )


class ValidateTest(unittest.TestCase):
    def validate(self, **overrides):
        values = {
            "host": "samskrte.ru",
            "key": KEY,
            "key_location": KEY_LOCATION,
            "urls": ["https://samskrte.ru/k/a"],
        }
        values.update(overrides)
        return indexnow.validate(**values)

    def test_accepts_valid_batch(self):
        self.assertEqual(
            self.validate(),
            ["https://samskrte.ru/k/a"],
        )

    def test_rejects_short_key(self):
        with self.assertRaises(indexnow.IndexNowError):
            self.validate(key="short")

    def test_rejects_foreign_url(self):
        with self.assertRaises(indexnow.IndexNowError):
            self.validate(urls=["https://example.com/k/a"])

    def test_rejects_empty_urls(self):
        with self.assertRaises(indexnow.IndexNowError):
            self.validate(urls=[])

    def test_rejects_key_location_on_other_host(self):
        with self.assertRaises(indexnow.IndexNowError):
            self.validate(key_location=f"https://example.com/{KEY}.txt")

    def test_rejects_over_cap(self):
        with self.assertRaises(indexnow.IndexNowError):
            self.validate(urls=[f"https://samskrte.ru/k/{i}" for i in range(indexnow.MAX_URLS + 1)])


class VerifyKeyTest(unittest.TestCase):
    def test_accepts_matching_body(self):
        with mock.patch.object(
            indexnow.urllib.request,
            "urlopen",
            return_value=FakeResponse(200, KEY.encode()),
        ):
            indexnow.verify_key(host="samskrte.ru", key=KEY, key_location=KEY_LOCATION)

    def test_rejects_mismatched_body(self):
        with mock.patch.object(
            indexnow.urllib.request,
            "urlopen",
            return_value=FakeResponse(200, b"another-key"),
        ):
            with self.assertRaises(indexnow.IndexNowError):
                indexnow.verify_key(host="samskrte.ru", key=KEY, key_location=KEY_LOCATION)

    def test_rejects_http_error(self):
        error = urllib.error.HTTPError(KEY_LOCATION, 404, "Not Found", {}, None)
        with mock.patch.object(indexnow.urllib.request, "urlopen", side_effect=error):
            with self.assertRaises(indexnow.IndexNowError):
                indexnow.verify_key(host="samskrte.ru", key=KEY, key_location=KEY_LOCATION)

    def test_rejects_timeout(self):
        with mock.patch.object(
            indexnow.urllib.request,
            "urlopen",
            side_effect=TimeoutError("timed out"),
        ):
            with self.assertRaises(indexnow.IndexNowError):
                indexnow.verify_key(host="samskrte.ru", key=KEY, key_location=KEY_LOCATION)


class SubmitTest(unittest.TestCase):
    def test_posts_expected_payload(self):
        response = FakeResponse(202, b"Accepted")
        with mock.patch.object(indexnow.urllib.request, "urlopen", return_value=response) as opener:
            status, body = indexnow.submit(
                indexnow.build_payload(
                    host="samskrte.ru",
                    key=KEY,
                    key_location=KEY_LOCATION,
                    urls=["https://samskrte.ru/k/a"],
                )
            )

        self.assertEqual((status, body), (202, "Accepted"))
        request = opener.call_args[0][0]
        payload = json.loads(request.data.decode("utf-8"))
        self.assertEqual(payload["host"], "samskrte.ru")
        self.assertEqual(payload["keyLocation"], KEY_LOCATION)
        self.assertEqual(payload["urlList"], ["https://samskrte.ru/k/a"])
        self.assertEqual(request.method, "POST")

    def test_returns_http_error_status(self):
        error = urllib.error.HTTPError(indexnow.INDEXNOW_ENDPOINT, 400, "Bad Request", {}, io.BytesIO(b"bad"))
        with mock.patch.object(indexnow.urllib.request, "urlopen", side_effect=error):
            status, body = indexnow.submit({"host": "samskrte.ru"})

        self.assertEqual(status, 400)
        self.assertEqual(body, "bad")

    def test_timeout_raises_indexnow_error(self):
        with mock.patch.object(
            indexnow.urllib.request,
            "urlopen",
            side_effect=TimeoutError("timed out"),
        ):
            with self.assertRaises(indexnow.IndexNowError):
                indexnow.submit({"host": "samskrte.ru"})


class MainTest(unittest.TestCase):
    def args(self, *extra):
        return [
            "--host", "samskrte.ru",
            "--key", KEY,
            "--key-location", KEY_LOCATION,
            "--urls", "https://samskrte.ru/k/a",
            *extra,
        ]

    def test_dry_run_does_not_touch_network(self):
        with mock.patch.object(indexnow.urllib.request, "urlopen") as opener:
            with contextlib.redirect_stdout(io.StringIO()) as out:
                code = indexnow.main(self.args())

        self.assertEqual(code, 0)
        opener.assert_not_called()
        self.assertIn("DRY RUN", out.getvalue())

    def test_dry_run_json_contains_payload(self):
        with contextlib.redirect_stdout(io.StringIO()) as out:
            code = indexnow.main(self.args("--json"))

        self.assertEqual(code, 0)
        payload = json.loads(out.getvalue())
        self.assertTrue(payload["dry_run"])
        self.assertEqual(payload["payload"]["urlList"], ["https://samskrte.ru/k/a"])

    def test_apply_verifies_then_submits(self):
        with mock.patch.object(indexnow, "verify_key") as verify, \
             mock.patch.object(indexnow, "submit", return_value=(200, "OK")) as submit, \
             contextlib.redirect_stdout(io.StringIO()) as out:
            code = indexnow.main(self.args("--apply"))

        self.assertEqual(code, 0)
        verify.assert_called_once()
        submit.assert_called_once()
        self.assertIn("OK", out.getvalue())

    def test_apply_failure_returns_one(self):
        with mock.patch.object(indexnow, "verify_key"), \
             mock.patch.object(indexnow, "submit", return_value=(400, "bad")), \
             contextlib.redirect_stdout(io.StringIO()):
            code = indexnow.main(self.args("--apply"))

        self.assertEqual(code, 1)

    def test_verify_only_checks_key(self):
        with mock.patch.object(indexnow, "verify_key") as verify, \
             contextlib.redirect_stdout(io.StringIO()) as out:
            code = indexnow.main(self.args("--verify-only"))

        self.assertEqual(code, 0)
        verify.assert_called_once()
        self.assertIn("OK", out.getvalue())

    def test_missing_key_is_usage_error(self):
        with contextlib.redirect_stderr(io.StringIO()):
            with self.assertRaises(SystemExit) as exc:
                indexnow.main(["--host", "samskrte.ru", "--urls", "https://samskrte.ru/k/a"])

        self.assertEqual(exc.exception.code, 2)


if __name__ == "__main__":
    unittest.main()
