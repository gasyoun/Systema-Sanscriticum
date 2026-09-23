"""Pin the stale-base guard's narrow generated-inventory exception."""

import importlib.util
import pathlib
import unittest
from unittest.mock import patch


SCRIPT = pathlib.Path(__file__).with_name("pre_push_stale_base_check.py")
SPEC = importlib.util.spec_from_file_location("stale_base_guard", SCRIPT)
assert SPEC and SPEC.loader
guard = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(guard)


class InventoryRowGuardTest(unittest.TestCase):
    path = "docs/ENVIRONMENT_VARIABLES.md"
    old = [
        "# Environment variable inventory",
        "| `PAYPAL_CLIENT_SECRET` | secret | `—` | config/services.php:589 |",
        "| `OTHER_KEY` | optional | `false` | config/features.php:10 |",
    ]

    def credits(self, new, path=None):
        with patch.object(guard, "blob_lines", side_effect=[self.old, new]):
            return guard.preserved_env_inventory_rows(
                "remote", "local", path or self.path, [1, 2, 3])

    def test_location_change_preserves_keyed_fact(self):
        new = [
            self.old[0],
            "| `PAYPAL_CLIENT_SECRET` | secret | `—` | config/services.php:639 |",
            self.old[2],
        ]
        self.assertEqual({2, 3}, self.credits(new))

    def test_real_deletion_and_changed_default_still_block(self):
        self.assertEqual(set(), self.credits([self.old[0]]))
        changed = [
            self.old[0],
            "| `PAYPAL_CLIENT_SECRET` | secret | `exposed` | config/services.php:639 |",
            self.old[2],
        ]
        self.assertEqual({3}, self.credits(changed))

    def test_identical_duplicate_collapses_but_conflicting_one_blocks(self):
        duplicate = self.old + [self.old[1]]
        with patch.object(guard, "blob_lines", side_effect=[duplicate, self.old]):
            self.assertEqual(
                {2, 3, 4}, guard.preserved_env_inventory_rows(
                    "remote", "local", self.path, [2, 3, 4]
                )
            )
        old = self.old + [
            "| `PAYPAL_CLIENT_SECRET` | secret | `exposed` | config/services.php:639 |"
        ]
        with patch.object(guard, "blob_lines", side_effect=[old, self.old]):
            self.assertEqual(
                set(), guard.preserved_env_inventory_rows(
                    "remote", "local", self.path, [2, 4]
                )
            )

    def test_other_path_gets_no_credit(self):
        self.assertEqual(set(), self.credits(self.old, "docs/other.md"))


if __name__ == "__main__":
    unittest.main()
