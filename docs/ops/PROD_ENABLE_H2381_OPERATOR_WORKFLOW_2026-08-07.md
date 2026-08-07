# Prod enable — H2381 operator workflow + geo driver (07-08-2026)

_Created: 07-08-2026 · Last updated: 07-08-2026_

**Host:** `193.232.229.92` · app root `/var/www/html` · HEAD at enable: `af4549b9` (H2382 merge + deploy).

**Executor:** Grok 4.5 (`grok-4.5`) · order from H2382 enable ladder (user: deploy → flip → canary → geo → report).

## 1. Deploy

```text
sudo bash deploy.sh
```

Result: **Already up to date** at `af4549b9` (H2381 code + migrations + H2382 `support:parity-report` already present). Caches rebuilt, Horizon restarted, `samskrte.ru` → 200, `guards:verify` OK.

## 2–3. Flags flipped ON

Backup: `.env.bak.h2381-enable.20260807T204725`

| Env | Config | Before | After |
|---|---|---|---|
| `SUPPORT_FOLLOW_UP_TASKS=true` | `features.support_follow_up_tasks` | false | **true** |
| `SUPPORT_REQUIRED_CLOSE_TOPIC=true` | `features.support_required_close_topic` | false | **true** |
| `SUPPORT_GEO_DRIVER=cloudflare` | `support_geo.driver` | `null` | **cloudflare** |
| `SUPPORT_VISITOR_GEO=true` | `features.support_visitor_geo` | true (kept) | **true** |

After each flip: `php artisan config:cache`.

### Curator note (1 line)

> **Helpdesk:** при закрытии диалога теперь **обязательна тема** (категория или «другое»). Можно ставить follow-up из диалога (срок + заметка) — не путать с CRM-задачами по сделкам.

## 4. Operator canary (synthetic, not a student)

| Step | Result |
|---|---|
| Create guest thread | `SupportConversation` **#47** (`H2381 enable canary`) |
| Follow-up create + complete | `FollowUpTask` **#1** · `support_conversation_id=47` · `done_at` set |
| Close without topic | **blocked** (`InvalidArgumentException` — required path works) |
| Close with topic `technical` | status **closed** · `support_conversation_topics` **1** row |

Counts after canary: `closed_threads=1`, topic history=1, support follow-ups=1.

## 5. Geo

Driver set to **cloudflare** (zero outbound HTTP; city only if CF-IPCity on the plan). New guest web threads should pick city from CF headers when present; existing threads stay without city until re-resolved.

## 6. Parity report

```bash
php artisan support:parity-report --days=14 --json
```

Still **HOLD** overall (H1200 email residual, VK zero traffic, lead/presence not canaried, reply-out pending history). Improvements vs pre-enable: **closed_threads ≥ 1**, geo driver no longer null.

## Rollback

```bash
# restore backup or:
SUPPORT_FOLLOW_UP_TASKS=false
SUPPORT_REQUIRED_CLOSE_TOPIC=false
SUPPORT_GEO_DRIVER=null   # or remove line
php artisan config:cache
```

Backup file on server: `.env.bak.h2381-enable.20260807T204725`.

_Dr. Mārcis Gasūns_
