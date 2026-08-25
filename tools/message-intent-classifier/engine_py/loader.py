"""YAML rules/taxonomy loader with strict, cross-engine-portable validation.

Both loaders (this one and php/MessageClassifier/Loader.php) enforce the same
schema so a rules directory is byte-comparable between consumers:

- top-level document: {"rules": [rule, ...]} (rules dir) or
  {"plane": ..., "categories": [{key, title, description}, ...]} (taxonomy dir);
- rule keys: plane, category, priority, patterns (required);
  negations, enabled (default True), source (optional provenance);
- patterns must not contain "/" or "#" (PCRE delimiters) nor \\w \\b \\d \\s
  escapes that behave differently across re/PCRE — explicit classes only;
- every rule category must exist in the matching taxonomy file.
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from pathlib import Path

import yaml

PLANES = ("topic", "objection", "intent", "meta")

_FORBIDDEN_SUBSTRINGS = ("/", "#", "\\w", "\\b", "\\d", "\\s", "\\u")

_RULE_KEYS = {"plane", "category", "priority", "patterns", "negations", "enabled", "source"}
_TAXONOMY_KEYS = {"key", "title", "description"}


class RuleError(ValueError):
    """Raised on any schema or portability violation in YAML sources."""


@dataclass(frozen=True)
class Rule:
    plane: str
    category: str
    priority: int
    patterns: tuple[re.Pattern, ...]
    negations: tuple[re.Pattern, ...] = ()
    enabled: bool = True
    order: int = 0
    source: str = ""

    def sort_key(self) -> tuple[int, int]:
        # Меньше приоритет = раньше; при равном — порядок загрузки файлов.
        return (self.priority, self.order)


@dataclass(frozen=True)
class RuleSet:
    rules_by_plane: dict[str, list[Rule]] = field(default_factory=dict)
    categories_by_plane: dict[str, frozenset[str]] = field(default_factory=dict)


def normalize_text(text: str | None) -> str:
    """mb_lower + ё->е + ASCII-whitespace fold. Mirrored 1:1 in Classifier.php.

    Only [\\t\\n\\v\\f\\r ] are folded (not every Unicode space): PCRE \\s without
    UCP diverges from Python \\s on NBSP/thin-space, and byte-parity between the
    two engines wins over exotic-space handling.
    """
    lowered = (text or "").lower().replace("ё", "е")
    return re.sub(r"[\t\n\v\f\r ]+", " ", lowered).strip()


def _compile(pattern: str, where: str) -> re.Pattern:
    if not isinstance(pattern, str) or not pattern.strip():
        raise RuleError(f"{where}: pattern must be a non-empty string, got {pattern!r}")
    for bad in _FORBIDDEN_SUBSTRINGS:
        if bad in pattern:
            raise RuleError(
                f"{where}: forbidden token {bad!r} in pattern {pattern!r} "
                "(keep patterns portable across Python re and PCRE)"
            )
    try:
        return re.compile(pattern)
    except re.error as exc:  # pragma: no cover - message context only
        raise RuleError(f"{where}: pattern {pattern!r} does not compile: {exc}") from exc


def _require_str(value: object, what: str, where: str) -> str:
    if not isinstance(value, str) or not value.strip():
        raise RuleError(f"{where}: {what} must be a non-empty string")
    return value


def load_rules(rules_dir: str | Path) -> list[Rule]:
    rules_dir = Path(rules_dir)
    if not rules_dir.is_dir():
        raise RuleError(f"rules dir not found: {rules_dir}")
    collected: list[Rule] = []
    order = 0
    for path in sorted(rules_dir.glob("*.yaml")):
        doc = yaml.safe_load(path.read_text(encoding="utf-8"))
        if not isinstance(doc, dict) or not isinstance(doc.get("rules"), list):
            raise RuleError(f"{path}: expected top-level mapping with 'rules' list")
        for index, entry in enumerate(doc["rules"]):
            where = f"{path}#rules[{index}]"
            if not isinstance(entry, dict):
                raise RuleError(f"{where}: rule must be a mapping")
            unknown = set(entry) - _RULE_KEYS
            if unknown:
                raise RuleError(f"{where}: unknown keys {sorted(unknown)}")
            plane = _require_str(entry.get("plane"), "plane", where)
            if plane not in PLANES:
                raise RuleError(f"{where}: plane {plane!r} not in {PLANES}")
            category = _require_str(entry.get("category"), "category", where)
            priority = entry.get("priority")
            if not isinstance(priority, int) or isinstance(priority, bool):
                raise RuleError(f"{where}: priority must be an integer")
            raw_patterns = entry.get("patterns")
            if not isinstance(raw_patterns, list):
                raise RuleError(f"{where}: patterns must be a list")
            enabled = entry.get("enabled", True)
            if not isinstance(enabled, bool):
                raise RuleError(f"{where}: enabled must be a boolean")
            if enabled and not raw_patterns:
                raise RuleError(f"{where}: enabled rule needs at least one pattern")
            patterns = tuple(_compile(p, f"{where}.patterns[{i}]") for i, p in enumerate(raw_patterns))
            raw_negations = entry.get("negations", [])
            if not isinstance(raw_negations, list):
                raise RuleError(f"{where}: negations must be a list")
            negations = tuple(_compile(p, f"{where}.negations[{i}]") for i, p in enumerate(raw_negations))
            source = entry.get("source", "")
            if source and not isinstance(source, str):
                raise RuleError(f"{where}: source must be a string")
            collected.append(
                Rule(
                    plane=plane,
                    category=category,
                    priority=priority,
                    patterns=patterns,
                    negations=negations,
                    enabled=enabled,
                    order=order,
                    source=source or "",
                )
            )
            order += 1
    return collected


def load_taxonomy(taxonomy_dir: str | Path) -> dict[str, dict[str, dict]]:
    taxonomy_dir = Path(taxonomy_dir)
    result: dict[str, dict[str, dict]] = {}
    if not taxonomy_dir.is_dir():
        raise RuleError(f"taxonomy dir not found: {taxonomy_dir}")
    for path in sorted(taxonomy_dir.glob("*.yaml")):
        doc = yaml.safe_load(path.read_text(encoding="utf-8"))
        if not isinstance(doc, dict):
            raise RuleError(f"{path}: expected top-level mapping")
        plane = _require_str(doc.get("plane"), "plane", str(path))
        categories = doc.get("categories")
        if plane not in PLANES or not isinstance(categories, list):
            raise RuleError(f"{path}: expected 'plane' in {PLANES} and 'categories' list")
        bucket: dict[str, dict] = {}
        for index, entry in enumerate(categories):
            where = f"{path}#categories[{index}]"
            if not isinstance(entry, dict):
                raise RuleError(f"{where}: category must be a mapping")
            unknown = set(entry) - _TAXONOMY_KEYS
            if unknown:
                raise RuleError(f"{where}: unknown keys {sorted(unknown)}")
            key = _require_str(entry.get("key"), "key", where)
            if key in bucket:
                raise RuleError(f"{where}: duplicate category key {key!r}")
            bucket[key] = {
                "title": _require_str(entry.get("title"), "title", where),
                "description": (entry.get("description") or "").strip(),
            }
        result[plane] = bucket
    missing = [p for p in PLANES if p not in result]
    if missing:
        raise RuleError(f"taxonomy missing planes: {missing}")
    return result


def load_package(root: str | Path) -> RuleSet:
    """Load rules/v1 + taxonomy/v1 from a package root and cross-check them."""
    root = Path(root)
    rules = load_rules(root / "rules" / "v1")
    taxonomy = load_taxonomy(root / "taxonomy" / "v1")
    for rule in rules:
        known = taxonomy.get(rule.plane, {})
        if rule.category not in known:
            raise RuleError(
                f"rule {rule.plane}/{rule.category} (priority {rule.priority}) "
                f"is absent from taxonomy/v1/{rule.plane}.yaml"
            )
    rules_by_plane: dict[str, list[Rule]] = {plane: [] for plane in PLANES}
    for rule in rules:
        rules_by_plane[rule.plane].append(rule)
    for plane in rules_by_plane:
        rules_by_plane[plane].sort(key=Rule.sort_key)
    return RuleSet(
        rules_by_plane=rules_by_plane,
        categories_by_plane={p: frozenset(taxonomy[p]) for p in PLANES},
    )


def load_rule_set_from_root(root: str | Path) -> RuleSet:
    """Alias kept for harness ergonomics."""
    return load_package(root)
