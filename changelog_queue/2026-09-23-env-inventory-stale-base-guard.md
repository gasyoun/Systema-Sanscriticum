- **Generated environment inventory no longer trips the stale-base guard**


- The pre-push stale-base guard now recognises a regenerated `docs/ENVIRONMENT_VARIABLES.md` row when its key, classification and default survive. Updated config line numbers and identical duplicate rows no longer look like lost upstream work.
- A missing key, changed classification or default, conflicting duplicate, and changes outside this exact inventory path still use the original protection. Regression tests pin both accepted and blocked cases.
- The CI Pint check now validates every PHP file changed by the PR or push. Older formatting debt in untouched files no longer prevents unrelated work from reaching the test suite.

  _Гасунс_
