# Beginner acquisition pilot

_Created: 22-09-2026 · Last updated: 22-09-2026_

## Scope and activation

The homepage prioritises busy adult beginners: free teaching excerpt → existing ₽500 guided introduction → suitable main course. The catalogue and free materials remain accessible; prices, payment processing and access grants are unchanged. No advertising spend is authorised by this implementation. The existing ₽10,000 VK ceiling and attribution/spending approvals remain in force.

The 30-day observation period has **not started**. Activation requires a verified future staffed group session, end-to-end production journey verification and reconciliation with payment records. The live schedule checked on 22 September still pointed to 28 August without an end time. Do not turn historical reports into a current availability claim.

Before offering paid guidance prominently, verify reviewer availability for introductory tasks and one bounded group question session. Record the schedule ID, confirmation timestamp and exact ISO start/end snapshots in `config/beginner_pilot.php`; they must match the future `marathon.schedule_id` record. Moving the session invalidates approval. Total personal participation, including the existing Monday review, must remain within 180 minutes weekly. No schedule or staffing commitment has been invented.

## Teaching evidence and copy

[Public teaching excerpt](https://www.youtube.com/watch?v=FmdnLXZ4UFo&t=5337s): 1:28:57–1:30:40, 103 seconds, from the published free webinar lesson 1464. The teacher explains the distinction between Sanskrit and devanagari and starting without the script. Timing was verified against the public video transcript. The page embeds this range and offers a direct timestamped link plus the alternative full recording. The direct YouTube link starts at the excerpt but does not stop automatically; the displayed end time identifies its boundary.

The selected lesson must remain published and free or the clip is withheld. The short excerpt demonstrates explanation, not a verified student outcome. Existing consented story drafts contain payment-derived learning claims; purchase history does not establish completed practice or learning. Publish no story until its starting point, practice, actual result and publication consent are verified together. The demonstration is the approved interim evidence.

Introductory materials are approximately 15 minutes daily; live support is separate. Main-course workload is programme-specific. Copy explains resuming after a missed day and distinguishes free self-study from paid review.

## Reporting

Use the read-only `report:beginner-pilot` command after a verified start date exists:

```sh
php artisan report:beginner-pilot --from=YYYY-MM-DD --days=30 --main-course=ID --json
```

Replace placeholders with the actual start and verified main-course IDs; repeat `--main-course` for each eligible course. Supply `--support-minutes=N` only from measured effort for the same reporting window; omission remains unavailable rather than zero. Run during the existing Monday review, keeping the same start date. The existing `report:first-time-buyers` rolling report remains available; this command provides a fixed pilot window and explicit continuation-course selection.

- Deduplicate first-time buyers by user ID across retained paid history, not by payment rows. Include genuine paid trials and introductions; exclude donations, expenses, salary, deposits and refunded source payments. Duplicate accounts still require manual reconciliation.
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

Targeted regression: 55 tests, 226 assertions passed; independent report/offer verification: 10 tests, 54 assertions passed. Production asset build passed. Mobile preview at 390×844 verified readable copy and free/paid distinction. The embedded player did not load in the checking browser, so a timestamped direct fallback was added. This is not a completed production video/playback or payment journey check.

Deploy through the established deployment workflow/script, including the nullable `day1_started_at` migration and frontend asset rebuild. After deployment verify homepage → excerpt → offer → payment sandbox/test path → granted access → first task → support instructions on mobile. Never make a real charge merely to satisfy a smoke test. No pilot start date is set automatically.

External implementation evidence: [Laravel v13.31.0 query builder](https://github.com/laravel/framework/blob/v13.31.0/src/Illuminate/Database/Query/Builder.php), [InvoiceShelf](https://github.com/InvoiceShelf/InvoiceShelf), [Invoice Ninja](https://github.com/invoiceninja/invoiceninja), [YouTube player parameters](https://developers.google.com/youtube/player_parameters), [react-youtube](https://github.com/tjallingt/react-youtube), [videojs-youtube](https://github.com/videojs/videojs-youtube). Existing installed framework conventions were reused; no SDK or platform was added.

_Гасунс_
