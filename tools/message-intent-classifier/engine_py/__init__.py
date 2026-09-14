"""message-intent-classifier — reference Python engine.

Deterministic regex classifier over four independent planes
(topic / objection / intent / meta). Semantics contract lives in README.md and
is shared verbatim with the PHP loader (php/MessageClassifier).
"""

__version__ = "0.1.0"

from .classifier import classify, normalize_text
from .loader import PLANES, Rule, RuleError, RuleSet, load_package, load_rules

__all__ = [
    "PLANES",
    "Rule",
    "RuleError",
    "RuleSet",
    "classify",
    "load_package",
    "load_rules",
    "normalize_text",
]
