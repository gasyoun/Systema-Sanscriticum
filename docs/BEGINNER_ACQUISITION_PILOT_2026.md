# Beginner acquisition pilot

_Created: 22-09-2026 · Last updated: 23-09-2026_

## Scope and activation

The homepage prioritises busy adult beginners: free teaching excerpt → existing ₽500 guided introduction → suitable main course. The catalogue and free materials remain accessible; prices and existing paid access grants are unchanged. New paid registration and checkout are closed until staffing is verified against the scheduled session; existing paid enrollment remains accessible. No advertising spend is authorised by this implementation. The existing ₽10,000 VK ceiling and attribution/spending approvals remain in force.

The 30-day observation period **started on 23 September 2026 at 00:00 Moscow time**, following the user's explicit instruction. Its end is 23 October 2026 at 00:00 Moscow time (exclusive). This starts measurement independently of the paid offer. The user confirmed curator availability and supplied the next session start: 30 September at 19:00 Moscow time. The bounded session is scheduled through 20:00; the 60-minute duration is an explicit working assumption pending any correction from the user.

The staffed meeting is Schedule #1979, with a verified HTTPS join link for the existing recurring consultation room. Its exact ISO start/end snapshots and the curator-confirmation date are bound in `config/beginner_pilot.php`; they must match the future `marathon.schedule_id` record. Moving the session invalidates approval. New paid registration closes at 00:00 Moscow time on 28 September, so a participant's third personal calendar day can fall by 30 September; checkout links expire within 60 minutes and before that cutoff. A late settled payment remains paid, but receives a neutral curator-follow-up message instead of a false live-place promise. Total personal participation, including the existing Monday review, must remain within 180 minutes weekly.

The same schedule check controls the older `/online/konsultaciya` page and both August paid POST routes. Once the cutoff passes, the page states that registration for the scheduled meeting is closed; free introductory materials remain available. January consultation routes remain separate; the September invitation and recording dispatcher explicitly exclude January-cohort enrollments.

## Teaching evidence and copy

[Public teaching excerpt](https://www.youtube.com/watch?v=FmdnLXZ4UFo&t=5337s): 1:28:57–1:30:40, 103 seconds, from the published free webinar lesson 1464. The teacher explains the distinction between Sanskrit and devanagari and starting without the script. Timing was verified against the public video transcript. The page embeds this range and offers a direct timestamped link plus the alternative full recording. The direct YouTube link starts at the excerpt but does not stop automatically; the displayed end time identifies its boundary.

The selected lesson must remain published and free or the clip is withheld. The short excerpt demonstrates explanation, not a verified student outcome. Existing consented story drafts contain payment-derived learning claims; purchase history does not establish completed practice or learning. Publish no story until its starting point, practice, actual result and publication consent are verified together. The demonstration is the approved interim evidence.

Introductory materials are approximately 15 minutes daily; live support is separate. Main-course workload is programme-specific. Copy explains resuming after a missed day and distinguishes free self-study from paid review.

## Reporting

Use the read-only `report:beginner-pilot` command with the configured start date:

```sh
php artisan report:beginner-pilot --days=30 --json
```

The configured start is 2026-09-23; use `--from` only to override it deliberately. Add `--main-course=ID` for each verified eligible course; until IDs are selected, continuation remains unavailable rather than zero. Supply `--support-minutes=N` only from measured effort for the same reporting window; omission remains unavailable rather than zero. Run during the existing Monday review, keeping the same start date. The existing `report:first-time-buyers` rolling report remains available; this command provides a fixed pilot window and explicit continuation-course selection.

The first production aggregate snapshot at 23 September 10:43 Moscow time classified 0 identifiable first-time buyers among timestamped payments, 0 returning buyers, 0 paid introductions, 0 first-task starts and 0 linked refunds within the new window. The first-time count is provisional, not proof that no first purchase occurred. Source coverage and main-course continuation were unavailable, not zero. The retained ledger contains 7,902 currently paid rows without `first_paid_at` across all history; these are not silently classified as new buyers. Payment reconciliation remains necessary before claiming reliable first-time-buyer totals.

- Deduplicate first-time buyers by user ID across retained paid history, not by payment rows. Include genuine paid trials and introductions; exclude donations, expenses, salary, deposits and linked refund ledger rows from purchase counts while retaining original refunded purchases in buyer history. Duplicate accounts still require manual reconciliation.
- Require `first_paid_at`; uncertain undated historical payments make history unknown rather than implying a new buyer. Payment rows remain separately countable for reconciliation.
- Keep observed attribution, inferred attribution and unknown sources separate. Capture valid first-visit acquisition fields on new marathon leads while preserving existing tracked-link priority and original duplicate-lead attribution. Source observation is not proof of causal lift.
- Count first successful Day 1 page views using `day1_started_at`; this is a task start, not quiz completion or learning achievement. Keep completed engagement distinct from message delivery.
- Report main-course continuation separately using explicitly selected course IDs. Purchases outside the observation window are not silently added.
- Count only real linked refund records in the refund window; refunds are not a claim of bank-net revenue. Reconcile buyer cohorts, duplicate payment rows, linked refunds and source coverage with payment records before activation.

## Acquisition reuse

Existing cabinet and payment-confirmation referral surfaces and `/?ref=CODE` handling are preserved and covered by regression tests. Separate new-buyer results from returning students. Completion of the previously proposed top-50 video-description updates has not been verified; do not mark that work published.

Reusable description text for the verified webinar:

> Начните знакомство с санскритом: короткое объяснение, вводные задания и условия участия с проверкой. Бесплатный самостоятельный вариант тоже доступен.
> https://samskrte.ru/online/poprobovat?utm_source=youtube&utm_medium=video_description&utm_campaign=beginner_pilot&utm_content=FmdnLXZ4UFo

Preserve existing useful description content and referral parameters. Before changing other descriptions, verify their current text and use the actual video ID as `utm_content`; do not invent a top-video ranking. No external description edits or messages are claimed by this repository change.

## Validation and deployment

Initial targeted regression: 55 tests, 226 assertions passed; independent report/offer verification: 10 tests, 54 assertions passed. Production asset build passed. After the 23 September release, the live homepage and beginner page returned 200, the YouTube player loaded in the mobile browser, and the cabinet probe passed after a stale failure fuse was archived. A subsequent mobile walkthrough exposed an older consultation-page paid option and ambiguous free-track promise; the follow-up staffing gate and messaging fixes have 83 focused tests and 251 assertions passing locally, with independent money-flow verification. CI and production smoke are still required. No real payment was made.

Deploy through the established deployment workflow/script, including the nullable `day1_started_at` migration and frontend asset rebuild. After deployment verify homepage → excerpt → offer → payment sandbox/test path → granted access → first task → support instructions on mobile. Never make a real charge merely to satisfy a smoke test. No pilot start date is set automatically.

External implementation evidence: [Laravel v13.31.0 query builder](https://github.com/laravel/framework/blob/v13.31.0/src/Illuminate/Database/Query/Builder.php), [InvoiceShelf](https://github.com/InvoiceShelf/InvoiceShelf), [Invoice Ninja](https://github.com/invoiceninja/invoiceninja), [YouTube player parameters](https://developers.google.com/youtube/player_parameters), [react-youtube](https://github.com/tjallingt/react-youtube), [videojs-youtube](https://github.com/videojs/videojs-youtube). Existing installed framework conventions were reused; no SDK or platform was added.

_Гасунс_
