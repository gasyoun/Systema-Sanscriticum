<?php

declare(strict_types=1);

namespace Tests\Support\N8n;

use RuntimeException;

/**
 * Fail-closed signal: the exported n8n workflow no longer matches the
 * grammar this replay understands. Either update the mirrors/pins in
 * tests/Support/N8n/ to follow the new export, or revert the export
 * change — never loosen a pin to make a test pass.
 */
final class WorkflowGlueException extends RuntimeException {}
