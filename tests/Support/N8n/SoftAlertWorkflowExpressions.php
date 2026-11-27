<?php

declare(strict_types=1);

namespace Tests\Support\N8n;

/**
 * n8n expression evaluator for the H8562 workflow export (H8562 v2).
 *
 * Evaluates exactly the expression forms the export uses; anything else
 * fails closed with a WorkflowGlueException instead of being silently
 * skipped. Context shape:
 *
 *  - 'json'    — $json of the executing node (current input item)
 *  - 'outputs' — node name => OUTPUT items ($('X').first().json.…)
 *  - 'env'     — $env.… values provided by the replay constructor
 */
final class SoftAlertWorkflowExpressions
{
    /** Failures-render fragment exactly as exported (JS escapes intact) — pin form. */
    public const FAILURES_JOIN_RAW = "($('Webhook soft-alert').first().json.body.failures || []).map(f => \\`- \${f.message}\\`).join('\\n')";

    /** Failures-render fragment after template-escape conversion — what evalAtom sees. */
    public const FAILURES_JOIN = "($('Webhook soft-alert').first().json.body.failures || []).map(f => `- \${f.message}`).join('\n')";

    /**
     * n8n expression semantics: leading '=', whole {{ … }} wrappers, and
     * mixed templates that substitute each {{ … }} in place.
     *
     * @param  array<string, mixed>  $context
     */
    public function evalExpr(string $raw, array $context): mixed
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if (str_starts_with($raw, '=')) {
            $raw = substr($raw, 1);
        }

        if (preg_match('/^\{\{(.+)\}\}$/s', $raw, $m) === 1) {
            return $this->evalAtom($m[1], $context);
        }

        return preg_replace_callback(
            '/\{\{(.+?)\}\}/s',
            fn (array $m): string => (string) $this->evalAtom($m[1], $context),
            $raw
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function evalAtom(string $expr, array $context): mixed
    {
        $expr = trim($expr);

        // The failures-render fragment used by the issue body and comments.
        if ($expr === self::FAILURES_JOIN) {
            $failures = $context['outputs']['Webhook soft-alert'][0]['json']['body']['failures'] ?? [];

            return implode("\n", array_map(
                static fn (array $f): string => '- '.(string) ($f['message'] ?? ''),
                array_values((array) $failures)
            ));
        }

        $parts = $this->splitTopLevel($expr, '||');
        $value = null;
        foreach ($parts as $part) {
            $value = $this->evalPrimary(trim($part), $context);
            if ($this->isTruthy($value)) {
                return $value;
            }
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function evalPrimary(string $expr, array $context): mixed
    {
        if ($expr === '') {
            throw new WorkflowGlueException('empty expression atom');
        }

        // Object literal (respond bodies).
        if (preg_match('/^\{(.*)\}$/s', $expr, $m) === 1) {
            return $this->evalObjectLiteral($m[1], $context);
        }

        // Numeric comparison (IF predicates).
        if (preg_match('/^([^>]+)\s*>\s*([^>]+)$/', $expr, $m) === 1) {
            return ((float) $this->evalAtom($m[1], $context)) > ((float) $this->evalAtom($m[2], $context));
        }

        // Paired-item node reference: $('X').item.json.path — resolved
        // against outputs[X] at the CURRENT item index (1:1 when the
        // intervening HTTP node emits one output per input, which the close
        // node's mirror guarantees). Requires itemIndex in the context.
        if (preg_match('/^\$\(\'([^\']+)\'\)\.item\.json\.(.+)$/', $expr, $m) === 1) {
            $node = $m[1];
            $index = $context['itemIndex'] ?? null;
            if ($index === null) {
                throw new WorkflowGlueException("\$('{$node}').item used without an item-index context");
            }
            $items = $context['outputs'][$node] ?? null;
            if ($items === null || ! array_key_exists($index, $items)) {
                throw new WorkflowGlueException("\$('{$node}') has no paired item at index {$index} in this replay");
            }

            return $this->resolvePath($items[$index]['json'], str_replace('?.', '.', $m[2]));
        }

        if (preg_match('/^\$\(\'([^\']+)\'\)\.first\(\)\.json\.(.+)$/', $expr, $m) === 1) {
            $node = $m[1];
            $item = $context['outputs'][$node][0]['json'] ?? null;
            if ($item === null) {
                throw new WorkflowGlueException("\$('{$node}') has no first item in this replay");
            }

            return $this->resolvePath($item, str_replace('?.', '.', $m[2]));
        }

        if (preg_match('/^\$json\.(.+)$/', $expr, $m) === 1) {
            return $this->resolvePath($context['json'], $m[1]);
        }

        if (preg_match('/^\$env\.([A-Za-z_][A-Za-z0-9_]*)$/', $expr, $m) === 1) {
            $name = $m[1];
            if (! array_key_exists($name, $context['env'])) {
                throw new WorkflowGlueException("\$env.{$name} is not provided to the replay; pass it in the env array");
            }

            return $context['env'][$name];
        }

        if (preg_match('/^-?\d+(?:\.\d+)?$/', $expr) === 1) {
            return str_contains($expr, '.') ? (float) $expr : (int) $expr;
        }

        if (preg_match('/^\'(.*)\'$/s', $expr, $m) === 1) {
            return $m[1];
        }

        if (preg_match('/^"(.*)"$/s', $expr, $m) === 1) {
            return $m[1];
        }

        if ($expr === 'true') {
            return true;
        }

        if ($expr === 'false') {
            return false;
        }

        if ($expr === 'null') {
            return null;
        }

        if ($expr === '[]') {
            return [];
        }

        throw new WorkflowGlueException(
            "unhandled n8n expression form: {$expr} — either simplify the export expression "
            .'or teach the replay evaluator this form (fail-closed by design)'
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function evalObjectLiteral(string $inner, array $context): array
    {
        $result = [];
        foreach ($this->splitTopLevel($inner, ',') as $field) {
            $field = trim($field);
            if ($field === '') {
                continue;
            }
            if (preg_match('/^"([^"]+)"\s*:\s*(.+)$/s', $field, $m) !== 1) {
                throw new WorkflowGlueException("unhandled object-literal field: {$field}");
            }
            $result[$m[1]] = $this->evalAtom($m[2], $context);
        }

        return $result;
    }

    /**
     * JS template literal: convert the exported escapes (\n, \`), then
     * interpolate ${ … } with balanced-brace scanning (the failures map
     * contains a nested ${f.message}).
     *
     * @param  array<string, mixed>  $context
     */
    public function evalTemplateLiteral(string $template, array $context): string
    {
        $template = str_replace(['\\n', '\\`'], ["\n", '`'], $template);

        $out = '';
        $len = strlen($template);
        $i = 0;
        while ($i < $len) {
            if ($template[$i] === '$' && $i + 1 < $len && $template[$i + 1] === '{') {
                $depth = 1;
                $start = $i + 2;
                $j = $start;
                while ($j < $len && $depth > 0) {
                    if ($template[$j] === '{') {
                        $depth++;
                    } elseif ($template[$j] === '}') {
                        $depth--;
                    }
                    $j++;
                }
                if ($depth !== 0) {
                    throw new WorkflowGlueException('unbalanced ${ … } in exported template literal');
                }
                $out .= (string) $this->evalAtom(substr($template, $start, $j - $start - 1), $context);
                $i = $j;
            } else {
                $out .= $template[$i];
                $i++;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function resolvePath(mixed $value, string $path): mixed
    {
        // The calling regex consumes the separator dot before the first
        // path segment; restore it so every segment tokenizes.
        if (! str_starts_with($path, '.') && ! str_starts_with($path, '[')) {
            $path = '.'.$path;
        }

        if (preg_match_all("/\\.([A-Za-z_][A-Za-z0-9_]*)|\['([^']+)'\]|\[(\d+)\]/", $path, $matches, PREG_SET_ORDER) === false) {
            throw new WorkflowGlueException("cannot tokenize expression path: {$path}");
        }

        foreach ($matches as $token) {
            if ($token[0] === '') {
                continue;
            }

            if ($token[1] !== '' && $token[1] === 'length') {
                if (is_array($value)) {
                    $value = count($value);

                    continue;
                }
                throw new WorkflowGlueException('.length used on a non-array in an exported expression');
            }

            if ($token[1] !== '') {
                $key = $token[1];
            } elseif ($token[2] !== '') {
                $key = $token[2];
            } else {
                $key = (int) $token[3];
            }

            if (is_array($value) && array_key_exists((string) $key, $value)) {
                $value = $value[(string) $key];
            } elseif (is_array($value) && array_key_exists((int) $key, $value)) {
                $value = $value[(int) $key];
            } else {
                return null; // JS undefined → null for || fallbacks
            }
        }

        return $value;
    }

    /**
     * Split on a separator outside quotes and any bracket/paren depth.
     *
     * @return list<string>
     */
    private function splitTopLevel(string $expr, string $separator): array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;
        $quote = null;
        $sepLen = strlen($separator);

        $chars = str_split($expr);
        for ($i = 0; $i < count($chars); $i++) {
            $char = $chars[$i];

            if ($quote !== null) {
                $buffer .= $char;
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;

                continue;
            }

            if ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
            } elseif ($char === ')' || $char === ']' || $char === '}') {
                $depth--;
            }

            if ($depth === 0 && substr($expr, $i, $sepLen) === $separator) {
                $parts[] = $buffer;
                $buffer = '';
                $i += $sepLen - 1;

                continue;
            }

            $buffer .= $char;
        }
        $parts[] = $buffer;

        return $parts;
    }

    private function isTruthy(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== 0 && $value !== false && $value !== [];
    }
}
