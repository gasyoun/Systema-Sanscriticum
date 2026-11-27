// H8562 v3 acceptance probe: execute the ACTUAL Code-node JS from the export
// against fixtures for the three review findings. Mirrors n8n Code-node
// semantics (runOnceForAllItems): $input.all(), $(node).first().json.
'use strict';

const fs = require('fs');

const exportJson = JSON.parse(
  fs.readFileSync('docs/n8n/soft-alert-to-github-issue.workflow.json', 'utf8'),
);
const byName = {};
for (const node of exportJson.nodes) byName[node.name] = node;

const codeOf = (name) => byName[name].parameters.jsCode;

// The n8n Code-node runtime shim: only the exact globals the export uses.
function runCode(jsCode, items, nodeOutputs) {
  const input = { all: () => items };
  const dollar = (name) => ({
    first: () => ({ json: nodeOutputs[name][0].json }),
  });
  const fn = new Function('$input', '$', 'return (function() {' + jsCode + '})()');
  return fn(input, dollar);
}

const FP_X = '6fd6984000000000000000000000000000000000000000000000000000000000000';
const FP_COLLIDE = '6fd69840ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';
const TITLE_X = '[soft-alert] guards 6fd69840';

const webhookItem = {
  json: {
    body: {
      scope: 'guards',
      fingerprint: FP_X,
      occurred_at: '2026-11-23T23:00:00+00:00',
      failures: [{ message: 'guards/failed-units: telegram-daily.service' }],
    },
    headers: { 'X-Soft-Alert-Secret': 'test-soft-alert-secret' },
  },
};

let failures = 0;
const check = (label, actual, expected) => {
  const ok = JSON.stringify(actual) === JSON.stringify(expected);
  if (!ok) failures += 1;
  console.log((ok ? 'PASS ' : 'FAIL ') + label);
  if (!ok) {
    console.log('  actual   :', JSON.stringify(actual));
    console.log('  expected :', JSON.stringify(expected));
  }
};

// ---------------------------------------------------------------- finding 3
// Two DISTINCT full fingerprints share their first eight hex; only the
// same-fingerprint issue may match. Ambiguous legacy (no provable fp) and
// PRs never match.
const listItems = [
  { json: { number: 101, title: TITLE_X, state: 'open', body: 'body no fingerprint line', html_url: 'u101' } },
  { json: { number: 102, title: TITLE_X, state: 'open', pull_request: { url: 'pr' }, body: '<!-- soft-alert-fingerprint:' + FP_X + ' -->', html_url: 'u102' } },
  { json: { number: 103, title: TITLE_X, state: 'open', body: 'legacy: **Fingerprint:** `' + FP_X + '`', html_url: 'u103' } },
  { json: { number: 104, title: TITLE_X, state: 'open', body: 'legacy: **Fingerprint:** `' + FP_COLLIDE + '`', html_url: 'u104' } },
  { json: { number: 105, title: TITLE_X, state: 'open', body: '<!-- soft-alert-fingerprint:' + FP_X + ' -->', html_url: 'u105' } },
  { json: { number: 106, title: TITLE_X, state: 'closed', body: '<!-- soft-alert-fingerprint:' + FP_X + ' -->', html_url: 'u106' } },
];

const matchOut = runCode(codeOf('Match exact title'), listItems, { 'Webhook soft-alert': [webhookItem] })[0].json;
check('match: expected title', matchOut.expected_title, TITLE_X);
check(
  'match: only provable same-fp issues (103 legacy + 105 marker)',
  matchOut.matches,
  [{ number: 103, html_url: 'u103' }, { number: 105, html_url: 'u105' }],
);

// Empty-list + alwaysOutputData transport case: one empty item in, no match out.
const emptyListOut = runCode(codeOf('Match exact title'), [{ json: {} }], { 'Webhook soft-alert': [webhookItem] })[0].json;
check('match: empty list item is not a match, title still built', emptyListOut, { expected_title: TITLE_X, matches: [], issue_body: emptyListOut.issue_body, repeat_comment_body: emptyListOut.repeat_comment_body });
check('match: issue_body carries the rendered full-fp marker', emptyListOut.issue_body.includes('<!-- soft-alert-fingerprint:' + FP_X + ' -->'), true);
check('match: repeat comment carries the failures line', emptyListOut.repeat_comment_body.includes('- guards/failed-units: telegram-daily.service'), true);

// ---------------------------------------------------------------- reconcile
const reconcileOut = runCode(
  codeOf('Reconcile find rival'),
  listItems,
  {
    'Webhook soft-alert': [webhookItem],
    'Match exact title': [{ json: { expected_title: TITLE_X, matches: [] } }],
    'GitHub create issue': [{ json: { number: 107, title: TITLE_X, state: 'open', body: '<!-- soft-alert-fingerprint:' + FP_X + ' -->' } }],
  },
)[0].json;
check('reconcile: rival = lowest same-fp issue (full object), colliding fp excluded', reconcileOut, {
  mine: 107,
  expected_title: TITLE_X,
  rival: listItems[2].json,
  rivals_count: 2,
  dup_note_body: 'Closed as duplicate of #103 - concurrent soft-alert deliveries converge on one issue (H8562).',
});

const reconcileCollideOut = runCode(
  codeOf('Reconcile find rival'),
  [{ json: { number: 103, title: TITLE_X, state: 'open', body: 'legacy: **Fingerprint:** `' + FP_COLLIDE + '`', html_url: 'u103' } }],
  {
    'Webhook soft-alert': [{ json: { body: { scope: 'guards', fingerprint: FP_X } } }],
    'Match exact title': [{ json: { expected_title: TITLE_X } }],
    'GitHub create issue': [{ json: { number: 107 } }],
  },
)[0].json;
check('reconcile: a lower-number 8-hex collision is NOT a rival', reconcileCollideOut, {
  mine: 107,
  expected_title: TITLE_X,
  rival: null,
  rivals_count: 0,
  dup_note_body: '',
});

// ---------------------------------------------------------------- finding 3b
// Sweep: closes only PROVEN same title+fp duplicates; ambiguous legacy and
// PR and foreign titles and closed issues are untouched.
const sweepOut = runCode(codeOf('Sweep group duplicates'), [
  { json: { number: 201, title: TITLE_X, state: 'open', body: 'ambiguous no fp' } },
  { json: { number: 202, title: TITLE_X, state: 'open', body: 'legacy: **Fingerprint:** `' + FP_X + '`' } },
  { json: { number: 203, title: TITLE_X, state: 'open', body: 'legacy: **Fingerprint:** `' + FP_X + '`' } },
  { json: { number: 204, title: TITLE_X, state: 'open', body: '<!-- soft-alert-fingerprint:' + FP_X + ' -->' } },
  { json: { number: 205, title: TITLE_X, state: 'open', body: 'legacy: **Fingerprint:** `' + FP_COLLIDE + '`' } },
  { json: { number: 206, title: TITLE_X, state: 'open', pull_request: { url: 'pr' }, body: 'legacy: **Fingerprint:** `' + FP_X + '`' } },
  { json: { number: 207, title: 'Unrelated bug', state: 'open', body: 'legacy: **Fingerprint:** `' + FP_X + '`' } },
  { json: { number: 208, title: TITLE_X, state: 'closed', body: '<!-- soft-alert-fingerprint:' + FP_X + ' -->' } },
], {});
check(
  'sweep: only provable duplicates close (202 group: 203, 204); ambiguous 201 / colliding 205 / PR / closed / foreign untouched',
  sweepOut,
  [
    { json: { title: TITLE_X, survivor_number: 202, duplicate_number: 203, sweep_note_body: 'Closed as duplicate - periodic soft-alert dedup sweep (H8562). Survivor: the lowest-numbered OPEN issue with the same title and full fingerprint.' } },
    { json: { title: TITLE_X, survivor_number: 202, duplicate_number: 204, sweep_note_body: 'Closed as duplicate - periodic soft-alert dedup sweep (H8562). Survivor: the lowest-numbered OPEN issue with the same title and full fingerprint.' } },
  ],
);

// ---------------------------------------------------------------- finding 1
// The create body marker: the body is composed in the Match Code node
// (live-fix 27-11-2026: n8n extendSyntax rejects template literals with
// escaped backticks / nested interpolation inside ={{ }} jsonBody), and the
// create node references it flatly. Execute the real composer and pin both.
const createBody = byName['GitHub create issue'].parameters.jsonBody;
check('create: body comes from the Match node composer (flat expression)', createBody.includes("body: $('Match exact title').first().json.issue_body"), true);
check('create: composer embeds the rendered marker with the FULL fingerprint', matchOut.issue_body.includes('<!-- soft-alert-fingerprint:' + FP_X + ' -->'), true);
check('create: rendered marker', matchOut.issue_body.split('\n').filter((l) => l.startsWith('<!-- soft-alert-fingerprint:'))[0], '<!-- soft-alert-fingerprint:' + FP_X + ' -->');
check('create: composer never rebuilds the title', createBody.includes('slice(0, 8)'), false);

// finding 2: pagination config per the documented n8n schema.
for (const name of ['GitHub list open issues', 'GitHub reconcile list', 'Sweep list open issues']) {
  const node = byName[name];
  const p = node.parameters.options.pagination.pagination;
  check(name + ': alwaysOutputData + documented pagination', {
    alwaysOutputData: node.alwaysOutputData === true,
    mode: p.paginationMode === 'updateAParameterInEachRequest',
    page: p.parameters.parameters[0].name === 'page' && p.parameters.parameters[0].value === '={{ $pageCount + 1 }}',
    complete: p.paginationCompleteWhen === 'responseIsEmpty',
    bounded: p.limitPagesFetched === true && p.maxRequests > 1,
  }, {
    alwaysOutputData: true, mode: true, page: true, complete: true, bounded: true,
  });
}

console.log(failures === 0 ? 'ALL JS PROBES PASS' : failures + ' PROBE FAILURES');
process.exit(failures === 0 ? 0 : 1);
