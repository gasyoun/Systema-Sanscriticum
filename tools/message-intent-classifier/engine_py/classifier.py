"""Reference classifier: normalize, per-plane first-match-wins with negations.

Semantics (mirrored 1:1 in php/MessageClassifier/Classifier.php):

1. text -> lower + ё->е + whitespace fold;
2. per plane, rules sorted by (priority asc, load order);
3. a rule fires when any pattern matches AND no negation matches;
4. winner carries {"category": ..., "reason": "keyword:<pattern>"};
5. no rule fired -> plane value is None.
"""

from __future__ import annotations

import re

from .loader import PLANES, RuleSet, normalize_text


def classify(ruleset: RuleSet, text: str | None) -> dict[str, dict[str, str] | None]:
    normalized = normalize_text(text)
    result: dict[str, dict[str, str] | None] = {}
    for plane in PLANES:
        result[plane] = None
        if not normalized:
            continue
        for rule in ruleset.rules_by_plane.get(plane, []):
            if not rule.enabled:
                continue
            if any(neg.search(normalized) for neg in rule.negations):
                continue
            for pattern in rule.patterns:
                if isinstance(pattern.search(normalized), re.Match):
                    result[plane] = {
                        "category": rule.category,
                        "reason": f"keyword:{pattern.pattern}",
                    }
                    break
            if result[plane] is not None:
                break
    return result
