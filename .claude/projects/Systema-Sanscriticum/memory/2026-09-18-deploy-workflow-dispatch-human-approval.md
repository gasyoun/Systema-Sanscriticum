# Systema deploy = workflow_dispatch with human environment approval

_Created: 18-09-2026_

Fact (probed 18-09-2026, H5154 close): merging a PR to Systema-Sanscriticum main does NOT deploy — `.github/workflows/deploy.yml` triggers only on `workflow_dispatch` (header comment: «Environment approval; merge/push сам по себе его не ставит в очередь»). Last prod deploys are sporadic human runs.

1. Agent can DISPATCH: `gh workflow run deploy.yml -R gasyoun/Systema-Sanscriticum --ref main` — the run then sits in `waiting` until the environment approval is clicked by a human (MG) in the Actions UI.
2. Approved run takes ~3 min; failing deploy cannot redden main (dispatch-only by design).
3. So: merged ≠ live. Any handoff whose verification includes live HTML probes must either include the deploy-approval step in its GTD residual or wait for MG's next deploy.
