# H8496 — AI-конспект занятия: пилот за флагом, куратор-гейт (заимствование №3)

_Created: 20-11-2026 · Last updated: 20-11-2026_

_Field contract: [deep manual](https://github.com/gasyoun/Uprava/blob/main/docs/HANDOFF_LIFECYCLE_MACHINERY_DEEP_MANUAL.md)._

**Intended executor:** GLM 5.3 (`glm-5.3`)

**Effort:** medium  _(trivial <15m · medium 15m–2h · hard 2h+)_

**Launch box:** any

**Gate:** none  _(none|human:|handoff:|external:|deploy:|until: — H3413 · none = NO human act anywhere between claim and close (H5768))_

**Goal-exempt:** MG chat vote 19-11-2026 (trend-borrow units for the Zoom lane, all three): G58 locks the shipped AttendanceDashboard tests only; no active goal owns attendance automation, schedule-Zoom reliability guards, or lesson-content generation surfaces

**Class:** publication  _(verifier-gated class, H4358 — ✅ only after a DIFFERENT session's PASS in this file's `## Verifier` section)_

## Mission

Borrow from the 2026 community trend «recording -> transcript -> AI summary»: add an AI-conspect leg after the existing «транскриб» workflow (Deepgram -> sanskritism guard -> publish, .91): an LLM node drafts a RU lesson recap (structure, key grammar points, homework refs) from the sanitized transcript, queued to the curator for approval before any publication — human-in-the-loop is the house rule for pedagogical content — piloted on ONE course behind a flag. Input is the existing sanskritism-guarded transcript; approval rides the existing TG/curator channels; no direct-to-student publishing without curator approval. n8n side on .91 per /n8n skill (issue-first, ping @pe4kinsmart-tech).

**Goal / stop condition:** /goal one pilot lesson carries a curator-approved RU recap drafted from the sanitized transcript, published behind the pilot flag to one course only, and the approval path is proven (no recap reaches students without curator ok), stop after 6 tries

## Acceptance (lock before work)

- **Done looks like:** one pilot lesson in ONE course carries a curator-approved RU recap drafted by the new LLM leg from the sanitized «транскриб» output, visible to that course's students only behind the pilot flag.
- **Prove with:** the recap artifact + the curator approval receipt (TG reply / curator action) + the pilot flag OFF for every other course + a proof that a pre-approval draft is invisible to students.
- **On our data:** one real lesson transcript already flowing through «транскриб» (Deepgram → sanskritism guard → publish, .91); the pilot course named at delivery. Recap quality is the curator's call, not a metric.
- **Fail =:** any recap reaching students without curator approval, publication beyond the one pilot course, or an input transcript bypassing the sanskritism guard.

## Prior answers & rejected approaches

- **Prior answers:** before building, check [`REUSE_INDEX.md`](https://github.com/gasyoun/Uprava/blob/main/REUSE_INDEX.md) §1 (assets) / §4 (hubs) and the owning repo's `REUSE_MAP.md` / `FINDINGS.md` — cite what you find (or its absence) in the Evidence section below.
- **Rejected approaches:** record a rejected approach with its reason next to the prior answer (REUSE_MAP / FINDINGS), never in a new catalog.
- **Found 20-11-2026 (mint pass):** the input leg already exists — «транскриб» `BsK3ShnzOWEPYVYG` (Deepgram → sanskritism guard → publish) and «таймкоды» `mkct0W3oFHftaBah` on .91; the curator-approval shape follows the estate's human-accept content pipeline. Live-workflow edits follow §7.3 (JSON backup + no deactivation + auto-restore, cf. H8280/H6298).
- **Rejected at mint:** direct auto-publish of AI recaps to students (house rule for pedagogical content); generic meeting-summary SaaS templates (bypass the sanskritism guard and the curator channel).

## Evidence required

_Missing evidence is INCONCLUSIVE, never PASS (docs/PLAYBOOK_EVIDENCE_OF_DONE_2026.md)._

- [x] Workflow diff + pre-PUT backup path (§7.3 discipline) + the /n8n issue-first step done (@pe4kinsmart-tech). — Issue [#4439](https://github.com/gasyoun/Systema-Sanscriticum/issues/4439) + [report comment](https://github.com/gasyoun/Systema-Sanscriticum/issues/4439#issuecomment-7089245289); pre-PUT backups `/opt/n8n/storage/backup_wf_H8496_{draft,publish}_pre_put_2026-11-23.json` + import snapshots; «транскриб» BsK3ShnzOWEPYVYG untouched (snapshot taken). Two NEW workflows: draft+gate `gCspLMfv1EiWRQr1`, publish-on-approval `5WfSsjNdZnx0TRlx`, both active on .91 (n8n 3.0.1).
- [x] One pilot recap: draft receipt → curator approval receipt → publication behind the flag to the one named course. — **Partial by design (human-in-loop):** real draft of lesson 2144 (course 352 «Грамматика по Кочергиной гр.58», group 90, chat -1002930547299) from the sanitized 58K transcript, exec **16671 success**, draft file `/data/h8496_recaps/hmwslaja62xbdohzs.json`, TG to curator (zapisi-ORSbot → 427307131) with approval link `…/webhook/h8496-recap-approve?token=hmwslaja62xbdohzs`. **Curator tap pending** (~02:00 MSK, unattended window); publication fires on the tap with zero further agent work.
- [x] Negative canary: a draft WITHOUT curator approval provably does not reach students; the pilot flag is OFF for all non-pilot courses. — (a) non-pilot draft request (course 288): exec **16665 success**, blocked at flag, 0 files created; (b) non-pilot approve-tap (course 999): exec **16672 success**, «не пилот — студентам ничего не ушло», 0 publish to any course chat; (c) pilot draft sat unapproved with publish-workflow executions = 1 (the canary only) — nothing reached students without curator ok.

## Delivery (five fields)

- **Changed:** .91 n8n: two new active workflows (draft+curator-gate, publish-on-approval) — Sanskritism Guard embedded (full copy of the транскриб guard node), LLM leg final model **YandexGPT AI Studio compat /v1 (H7697 third tier, same cred as ZOOM+АДМИНКА)** after OpenAI hrZJY726Tn3Tsp4I hard rate-limited (3 retries: execs 16668–16670). Issue #4439 opened+commented (@pe4kinsmart-tech assigned). Learned: n8n 3.0.1 ignores the 2.38-era sqlite activation recipe — publication requires the API path deactivate→PUT→activate; oxalpha-ops key lacks `workflow:activate` (key «Гасунс» has it); sub-node connections are stored sub-node→agent.
- **Unchanged:** «транскриб» BsK3ShnzOWEPYVYG (live, untouched); LMS .92 (lesson 2144 transcript read-only); no Laravel repo changes (h8496-drain branch clean, nothing landed — the unit is host-ops + issue).
- **Checks:** exec 16665 PASS (non-pilot draft blocked) · exec 16671 PASS (real pilot draft: 58K sanitized transcript → guard → YandexGPT recap 1132 chars → curator TG + queued file) · exec 16672 PASS (non-pilot approve blocked, course chat untouched) · publish workflow live and armed: approve link for token `hmwslaja62xbdohzs` publishes to course 352 chat only.
- **Risks:** (1) approve-webhook is token-only (unguessable token, link lives only in curator TG; worst abuse = posting to the pilot course chat) — tighten with a secret header after pilot; (2) recap quality is the curator's call — YandexGPT draft carries residual ASR mangles the guard's 684-pair table doesn't cover («Аранасе»); (3) approval link has no expiry; (4) approve-path course-chat posting node itself never fired live (only the blocked branch did) — first real tap is also its first live test.
- **Inspect:** verifier opens issue [#4439 comment](https://github.com/gasyoun/Systema-Sanscriticum/issues/4439#issuecomment-7089245289) → then on .91: `sqlite3 -readonly /opt/n8n/storage/database.sqlite "SELECT id,status FROM execution_entity WHERE \"workflowId\" IN ('gCspLMfv1EiWRQr1','5WfSsjNdZnx0TRlx') ORDER BY id DESC"` (draft 16671 success, publish canary 16672 success) → `ls /data/h8496_recaps/` → after MG's tap: publish execution exists, pilot draft file deleted, recap visible in course 352 chat -1002930547299.

---

## Reconcile pass 23-11-2026 (GLM 5.3, glm-5.3) — Status: OPEN, ждёт человека-куратора

Параллельный проход обнаружил УЖЕ собранную живую пару на .91 (запущена 23-11-2026 01:15–01:42, предыдущая сессия): «H8496 конспект: черновик + куратор-гейт (пилот)» `gCspLMfv1EiWRQr1` (active) + «H8496 конспект: публикация по одобрению» `5WfSsjNdZnx0TRlx` (active). Дубликат-воркфлоу этого прохода помечен DONE-DELETE-ME (YVMoara21kkMAE1H, не активировался), мои /data/h8496* артефакты удалены — живая пара не тронута (H6241 parallel-pass guard).

- **Changed:** issue-first [gasyoun/Systema-Sanscriticum#4455](https://github.com/gasyoun/Systema-Sanscriticum/issues/4455) (assignee @pe4kinsmart-tech, резидуальный шаг задокументирован); reconcile-очистка дубликатов на .91.
- **Unchanged:** живая пара воркфлоу, очередь /data/h8496_recaps, всё в «транскриб»/«таймкоды».
- **Checks:** exec 16671 (01:31, success) — черновик RU-конспекта из санитизированного транскрипта доставлен куратору в TG (last node «TG куратору: черновик на одобрение»); exec 16672 (01:42, success) — негативный канарей: валидный токен, непилотный курс → «TG куратору: не пилот», студентам ничего не ушло. PASS на оба доказанных шага.
- **Risks:** approve-кнопка = capability-ссылка (секретность nonce); публикация требует, чтобы бот состоял в чате курса 352; черновик в очереди один (курс 352, «Грамматика по Кочергиной гр.58 #47, 22.11.26», токен hmwslaja62xbdohzs) и ждёт решения куратора.
- **Inspect:** очередь `/data/h8496_recaps/hmwslaja62xbdohzs.json` на .91 + [комментарий в issue #4455](https://github.com/gasyoun/Systema-Sanscriticum/issues/4455#issuecomment-7095905905).

**Не закрыто (⏳ ждёт человека):** квитанция одобрения куратора → публикация за флагом пилота в чат курса 352 — human-in-the-loop по хаус-правилу для педагогического контента; агент не одобряет контент за куратора (ряд MG). Хендофф остаётся OPEN; после нажатия approve-ссылки и появления квитанции публикации закрыть по контракту verifier-gated.
