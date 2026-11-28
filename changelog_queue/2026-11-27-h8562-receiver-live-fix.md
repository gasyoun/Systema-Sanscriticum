# H8562 receiver live-fix: bodies composed in Code nodes, sweep comment URL paired-item

27-11-2026 rollout receipts (GLM 5.3 Flash (`zai-coding-plan/glm-5.3-flash`), MG-ordered rollout of the merged #4488):

**Live incident, verified on .91:** the pre-v3 receiver's last successful execution was 25-11 07:38:46 UTC
(the moment of the 25-11 edit); every delivery since failed (~53, ~20 s each, silent). The v3 receiver was
imported+published 27-11 13:52 UTC (backup `/root/n8n-h8562-rollout-backup/` on .91; oxalpha-ops API key
lacks `workflow:activate`, so publish ran via `n8n publish:workflow` + container restart). The first sweep
fired 15:00:43 UTC and was a clean no-op (board had been manually cleared 21-11 + 25-11).

**Execution 17389 (first real delivery into v3, `--force-alert` smoke):** `GitHub create issue` failed with
`ExpressionExtensionError: invalid syntax` — n8n's extendSyntax preprocessor rejects template literals with
escaped backticks / nested `${…}` inside `={{ }}` jsonBody parameters. The v3 export's create/comment bodies
had only ever been evaluated by the repo's simulated evaluator (SoftAlertWorkflowExpressions), never by real
n8n — a green-suite/real-runtime gap in the same class as FINDINGS §1732. The sweep also carried a latent
URL bug: `Sweep comment duplicate` read `$json.number`, which exists on neither the grouped item
(`duplicate_number`) nor the close response.

**Fix (this PR):** issue/comment bodies are composed in the Code nodes as plain JS (`issue_body`,
`repeat_comment_body` in "Match exact title", `dup_note_body` in "Reconcile find rival",
`sweep_note_body` in "Sweep group duplicates"); the HTTP nodes carry flat single-level expressions only;
the sweep comment URL reads the duplicate number through the paired-item reference
(`$('Sweep group duplicates').item.json.duplicate_number`). Body content is byte-faithful to the v3 intent
(scope, full-fingerprint marker + legacy line, failures, playbook, suggested fence, assignee/label/H8562
contract line). No secrets, no `/search/issues`, single title source — all invariants preserved.

**Live fingerprint note:** the active class since the #4488 deploy hashes to `cbf3203d…`, not the storm's
`6fd69840…` — normalization input changed, so the storm issues are a different (closed) class; the live
class had no open issue at rollout time (its 13:45 UTC delivery died with the old receiver).

**Tests:** the full n8n surface stays green — 20 tests / 434 assertions (contract 9, replay 6, review-replay 4,
JsTest 1 executing the real Code-node JS via soft_alert_js_probe.cjs, now also pinning the composer output:
rendered marker, failures line, dup note, sweep note). Replay mirrors updated to the new shape
(SoftAlertWorkflowPins: composer mirrors, flat create shape, static sweep body, paired-item URL), the
evaluator learned `$('Node').item.json.path` resolved at the current item index.
