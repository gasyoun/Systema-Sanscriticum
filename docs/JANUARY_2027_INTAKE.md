# January 2027 beginner grammar intake

Created: 2026-11-10. User-confirmed in the current interview.

The next intake of «Грамматика санскрита с нуля» starts on 16 January 2027 at 09:00 Europe/Moscow (UTC+3), with lessons every Saturday at 09:00. Existing teacher, format and prices apply: 8,000 RUB per block, 96,000 RUB for year one, 200,000 RUB for two years. This change does not write tariffs, schedules, groups, payments or access.

The existing course URL is retained. A January-specific announcement is separate from the schedules of other cohorts. Its application must match both the exact January waitlist slug and course id. The user accepted the current authenticated application process with the label «Подать заявку»; an anonymous click is pending intent, not a recorded application or guaranteed reservation. Generic waitlist wording and its quorum/payment behavior stay unchanged.

The upcoming announcement and special application context expire at 2027-01-16 09:00 Moscow. The evergreen program title stays after expiry; no next intake or holiday calendar is fabricated. The homepage may link directly to `/k/grammatika-gasuns-2026#upcoming-intake` only after the section is publicly verified. Existing WordPress publication uses ORS-FAQ's scoped workflow and separate credentials.

Validation: UpcomingIntakeTest covers date/timezone expiry, exact intake/course matching, fixed-time application wording, feature flag behavior and the pending guest-to-login route. Existing CourseCardWaitlistTest, WaitlistGuestVoteAfterLoginTest and CourseCadenceMultiStreamTest cover the surrounding behavior. Production user submission has not been performed.

Deployment: prepare and verify the PR first. `docs/deploy.md` describes the production gate and the server cron that can deploy merged main automatically; an unmerged branch is the review boundary. Never imply that a source commit or CI result proves production publication.
