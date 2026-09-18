#!/usr/bin/env node
// 0DH gate (18-09-2026): every inline <script> in public/lila/**/index.html must parse.
//
// Class covered (#2692, 18-09-2026): three drills (match/ru-sa-pairs-short,
// match/ru-sa-sentences, sort/verb-person-number) shipped with missing commas
// in the inline config array -> SyntaxError at load -> widget never mounted.
// Static HTML is cached, so users kept a dead drill until TTL. `node --check`
// is the standard mechanical syntax gate (parse only, never executes).
//
// Zero deps. Modes:
//   node scripts/check_lila_inline_js.mjs            — sweep all public/lila/**/index.html
//   node scripts/check_lila_inline_js.mjs --selftest — positive+negative control:
//       the gate must PASS a valid page and REFUSE the #2692 shape (missing comma).

import { readdirSync, readFileSync, mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { join, dirname } from 'node:path';
import { tmpdir } from 'node:os';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const LILA = join(ROOT, 'public', 'lila');

const SCRIPT_RX = /<script\b([^>]*)>([\s\S]*?)<\/script>/gi;
// JS-valued type attributes: absent, empty, classic JS, module. Everything else
// (application/json, importmap, text/template, ...) is data, not JavaScript.
const JS_TYPES_RX = /^(|text\/javascript|application\/javascript|module)$/i;
const ATTR_RX = /\b(type|src)\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+))/gi;

export function extractInlineScripts(html) {
  const blocks = [];
  for (const m of html.matchAll(SCRIPT_RX)) {
    const attrs = m[1] ?? '';
    const body = m[2] ?? '';
    let type = '';
    let hasSrc = false;
    for (const a of attrs.matchAll(ATTR_RX)) {
      const name = a[1].toLowerCase();
      const value = a[2] ?? a[3] ?? a[4] ?? '';
      if (name === 'src') hasSrc = true;
      if (name === 'type') type = value.trim();
    }
    if (hasSrc || !JS_TYPES_RX.test(type)) continue;
    blocks.push({ module: /^module$/i.test(type), body });
  }
  return blocks;
}

export function checkHtmlSource(html, workDir, label) {
  const blocks = extractInlineScripts(html);
  const failures = [];
  blocks.forEach((block, i) => {
    const ext = block.module ? 'mjs' : 'cjs';
    const jsPath = join(workDir, `${label}.${i}.${ext}`);
    writeFileSync(jsPath, block.body);
    try {
      execFileSync(process.execPath, ['--check', jsPath], { stdio: ['ignore', 'ignore', 'pipe'] });
    } catch (err) {
      failures.push(`inline script #${i + 1}: ${String(err.stderr ?? err.message).trim()}`);
    }
  });
  return { blocks: blocks.length, failures };
}

export function listIndexHtml(dir = LILA, out = []) {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const p = join(dir, entry.name);
    if (entry.isDirectory()) listIndexHtml(p, out);
    else if (entry.name === 'index.html') out.push(p);
  }
  return out.sort();
}

function sweep() {
  const pages = listIndexHtml();
  if (pages.length === 0) {
    console.error('lila-inline-js: no index.html found under public/lila — refusing to pass an empty sweep');
    process.exit(1);
  }
  const workDir = mkdtempSync(join(tmpdir(), 'lila-inline-js-'));
  let scriptsTotal = 0;
  const broken = [];
  try {
    for (const page of pages) {
      const rel = page.slice(ROOT.length + 1);
      const { blocks, failures } = checkHtmlSource(readFileSync(page, 'utf8'), workDir, rel.replaceAll('/', '__'));
      scriptsTotal += blocks;
      for (const f of failures) broken.push(`${rel} — ${f}`);
    }
  } finally {
    rmSync(workDir, { recursive: true, force: true });
  }
  if (broken.length > 0) {
    console.error(`lila-inline-js: FAIL — ${broken.length} broken inline script(s) across ${pages.length} page(s):`);
    for (const b of broken) console.error(`  ${b}`);
    process.exit(1);
  }
  console.log(`lila-inline-js: OK — ${scriptsTotal} inline script(s) across ${pages.length} page(s) parse clean.`);
}

function selftest() {
  const workDir = mkdtempSync(join(tmpdir(), 'lila-inline-js-selftest-'));
  const results = [];
  try {
    // Positive control: valid page must PASS.
    const good = checkHtmlSource(
      '<html><body><script>\nconst CFG = [{ id: "a", pairs: [["क", "ka"]] }, { id: "b", pairs: [["ग", "ga"]] }];\n</script></body></html>',
      workDir,
      'good',
    );
    results.push({ name: 'positive: valid page passes', ok: good.failures.length === 0 && good.blocks === 1 });

    // Negative control: the exact #2692 shape (missing comma between config
    // array elements) must FAIL — the gate has to refuse bad input.
    const bad = checkHtmlSource(
      '<html><body><script>\nconst CFG = [{ id: "a" } { id: "b" }];\n</script></body></html>',
      workDir,
      'bad',
    );
    results.push({ name: 'negative: missing comma refuses', ok: bad.failures.length === 1 });

    // Shape control: external <script src> and data blocks stay out of scope.
    const shaped = extractInlineScripts(
      '<script src="/lila/gate.js"></script><script type="application/json">{"x":1</script><script>let ok = 1;</script>',
    );
    results.push({ name: 'shape: src/json skipped, js kept', ok: shaped.length === 1 });
  } finally {
    rmSync(workDir, { recursive: true, force: true });
  }
  const failed = results.filter((r) => !r.ok);
  for (const r of results) console.log(`  ${r.ok ? 'PASS' : 'FAIL'} — ${r.name}`);
  if (failed.length > 0) {
    console.error(`lila-inline-js selftest: ${failed.length} control(s) failed — gate is untrustworthy, refusing to run.`);
    process.exit(1);
  }
  console.log('lila-inline-js selftest: all controls green.');
}

const mode = process.argv[2];
if (mode === '--selftest') {
  selftest();
} else if (mode === undefined) {
  sweep();
} else {
  console.error(`usage: node scripts/check_lila_inline_js.mjs [--selftest] (got: ${mode})`);
  process.exit(2);
}
