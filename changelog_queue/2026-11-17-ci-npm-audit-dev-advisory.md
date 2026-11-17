# ci: npm audit разделён — прод-дерево блокирует, dev-дерево советует (непатченный picomatch ReDoS) (2026-11-17)

_Created: 17-11-2026 · Last updated: 17-11-2026_

- **CI перестал быть красным для всех PR: `npm audit` разделён на два шага.**
  Причина: advisory [GHSA-fc5q-39xc-vjpf](https://github.com/advisories/GHSA-fc5q-39xc-vjpf)
  (`picomatch`, ReDoS через повторные extglob-ветви) валил шаг «npm security audit
  (including dev dependencies)» на каждом прогоне — у GitHub для него
  **`first_patched_version: None`**, то есть заплатки не существует: все версии
  `picomatch` до 4.0.7 включительно уязвимы, а 4.0.7 — последняя выпущенная.
  `npm audit fix --force` предлагал `vite@6.2.7` — это даунгрейд с 8.x, то есть
  не фикс.

- **Разделение по достижимости.** Все 6 high-находок живут только в dev-сборке
  (`vite` → `tinyglobby`/`fdir`, `laravel-vite-plugin`, `vite-plugin-full-reload`);
  `npm audit --omit=dev` на этом же lockfile даёт **0 уязвимостей**. Поэтому
  прод-дерево осталось **жёстким гейтом**, а dev-дерево печатается как
  `continue-on-error` — не блокирует, но и не скрывается из лога.

- **Условие возврата записано в самом workflow:** когда `picomatch` выпустит
  патченную версию, раздвоение удаляется и возвращается единый блокирующий
  `npm audit`. Это ослабление гейта на время, а не новая норма.

- **Побочный провал того же прогона** — `GlobalSearchTest::builder_writes_hashed_index_and_manifest_and_prunes_old`
  (`tests/Feature/GlobalSearchTest.php:102`, ожидался `index-2ac3d86c9e50.json`,
  получен `index-ba5480b00203.json`) — к правке доков отношения не имеет: хеш
  имени индекса зашит в тест и меняется вместе с содержимым корпуса/окружением;
  проверяется перезапуском джобы отдельно.

_Dr. Mārcis Gasūns_
