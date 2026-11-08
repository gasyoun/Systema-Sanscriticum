# Telegram natural-language class rescheduling — group-63 pilot runbook

_Created: 28-10-2026 · Status: prepared, not executed_

Decision record: `reports/GRILL_TELEGRAM_CLASS_RESCHEDULE_2026-10-27.md`.

## Scope and invariants

- First live group: **#150** («Грамматика по Кочергиной гр.63»), for **7 days**.
- First live message: **-1003994169966 / 93**, replayed exactly once through `AnnounceRescheduleService`.
- Expected result: schedule **#2856** moves to **30-10-2026 08:00–10:00 MSK**; **#2857** remains on **03-11-2026 08:00 MSK**.
- New code is inert while the feature flag is OFF or the group allowlist is empty. Existing cancellation behavior is preserved.
- No production step in this runbook has been executed by the implementation session.

## 1. Deploy and migrate

After the PR is merged and the normal deployment has landed:

```bash
cd /var/www/html
php artisan migrate --force
php artisan migrate:status | tail -n 8
```

Expected migration: `2026_10_28_120000_create_telegram_schedule_reschedules_table`.

## 2. Link @rusamskrtam to MG's staff identity

Production precheck on 28-10-2026: staff user **6755** is `super_admin` with primary Telegram ID `427307131`; no `social_accounts` row exists for `5487293147`.

Recheck for a collision, then create exactly one identity link:

```bash
php artisan tinker --execute='
$existing = App\Models\SocialAccount::where("provider", "telegram")->where("provider_id", "5487293147")->first();
if ($existing && (int) $existing->user_id !== 6755) { throw new RuntimeException("Telegram identity already belongs to another user"); }
App\Models\SocialAccount::firstOrCreate(
    ["provider" => "telegram", "provider_id" => "5487293147"],
    ["user_id" => 6755],
);
$actor = app(App\Services\Telegram\TelegramCommandAcl::class)->resolve(5487293147);
echo json_encode(["role" => $actor["role"] ?? null, "user_id" => $actor["user"]->id ?? null]);
'
```

Expected: `{"role":"super_admin","user_id":6755}`. Do not grant schedule authority from Telegram group-admin status.

## 3. Enable only the group-63 pilot

Back up `.env`, then set:

```dotenv
TELEGRAM_ZAPISI_ANNOUNCE_RESCHEDULE_DETECT=true
TELEGRAM_ZAPISI_ANNOUNCE_RESCHEDULE_GROUP_IDS=150
```

Apply and verify:

```bash
php artisan config:clear
php artisan config:cache
php artisan tinker --execute='echo json_encode(config("services.telegram_zapisi.announce_reschedule_detect"))." ".json_encode(config("services.telegram_zapisi.announce_reschedule_group_ids"));'
```

Expected: `true [150]`.

## 4. Replay message 93 once

The timestamp is part of the input: **27-10-2026 08:24:54 MSK**. It keeps «сегодня» bound to 27 October during the replay.

```bash
php artisan tinker --execute='
app(App\Services\Telegram\AnnounceRescheduleService::class)->handle([
    "chat" => ["id" => -1003994169966, "type" => "supergroup"],
    "message_id" => 93,
    "from" => ["id" => 5487293147],
    "date" => Illuminate\Support\Carbon::parse("2026-10-27 08:24:54", "Europe/Moscow")->timestamp,
    "text" => "Сегодня занятие не состоялось. У нас вынужденный перенос на пятницу 08.00. Было принято решение о разовом переносе на пятницу.",
]);
'
```

The persistent unique key is `(telegram_chat_id, telegram_message_id)`. A second identical invocation must be a no-op and must not send another receipt.

## 5. Verify the real result

```bash
php artisan tinker --execute='
echo json_encode([
    "class" => App\Models\Schedule::find(2856, ["id", "title", "start", "end"]),
    "next" => App\Models\Schedule::find(2857, ["id", "title", "start", "end"]),
    "actions" => App\Models\TelegramScheduleReschedule::where("telegram_chat_id", "-1003994169966")->where("telegram_message_id", 93)->get(["id", "status", "old_start", "old_end", "new_start", "new_end", "receipt_message_id", "undo_expires_at"]),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
'
```

Accept only when all are true:

- #2856 is **30-10-2026 08:00–10:00 MSK**, and its embedded title date is `30.10.26`.
- #2857 is unchanged.
- Exactly one action row exists for chat `-1003994169966`, message `93`, with one receipt message ID.
- The Telegram receipt is a reply to message 93, shows old and new times, and carries the 1-hour Undo button.
- No separate cancellation offer or standalone duplicate announcement was posted.
- The next reminders are armed for the new slot.

Do **not** test Undo on the desired production move: it would restore the obsolete Tuesday slot. Undo behavior, expiry, ACL, and later-edit protection are covered by `AnnounceRescheduleTest`; a separate sandbox may be used for a live callback smoke.

## 6. Pilot review and expansion

Review on **04-11-2026**. Expand the allowlist only if the 7-day pilot has:

- zero wrong moves;
- zero duplicate receipts;
- zero reminder defects;
- no unresolved Undo/ACL defect.

Otherwise keep the feature restricted to group #150 and record the defect. To disable the new path without touching cancellation:

```dotenv
TELEGRAM_ZAPISI_ANNOUNCE_RESCHEDULE_DETECT=false
```

Then run `php artisan config:clear && php artisan config:cache`.

## Automated acceptance

```bash
php -d memory_limit=1G artisan test tests/Feature/AnnounceRescheduleTest.php tests/Feature/ScheduleMoverTest.php tests/Feature/AnnounceCancelTest.php
```

The rescheduling suite covers the message-93 golden case, time preservation/override, social identity, ACL, both gates, ambiguity, same-day and older past sources, collisions, replay idempotency, cancellation-flow isolation, Undo, expiry, and later-edit refusal.
