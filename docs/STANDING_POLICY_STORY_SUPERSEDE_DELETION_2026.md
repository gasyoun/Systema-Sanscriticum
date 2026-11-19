# STANDING POLICY — Superseded story versions are ALWAYS deleted

_Created: 15-10-2026 · Last updated: 15-10-2026_
_Ruling: MG, 13-10-2026 (H6678 live tap-test session)_

## Rule

**MG 13-10-2026:** superseded story versions are always deleted.

When a story (or story version) is superseded — replaced by a re-published
version, corrected creative, or a new rung of a series — the superseded
remote story on Telegram MUST be deleted through the same code path
(`StoryPublisher::deleteStory` → isolated lane worker, `stories.deleteStories`).
No orphaned old versions may linger on the @rusamskrtam profile.

## Surfaces that carry this rule

- `App\Services\Anons\Adapters\TelegramStoryAdapter::delete()` — anons
  supersede path (deletes the remote story by remote id).
- `stories:publish-story --delete-story=ID` — artifact cleanup mode.
- `scripts/stories_lane_worker.php` — the isolated MTProto worker; the
  `delete` action (H6678: warm-up + single warmed client; the previous
  single-shot cold call fiber-deadlocked, which is what made this rule
  unenforceable in practice — deletions crashed).

## Why it is written down

13-10-2026 live tap-test (H6678) showed story deletion was broken in
practice: the lane worker fiber-deadlocked on `delete`/`get_story_views`,
so superseded versions silently stayed live. The repair (H6678) re-enables
deletion; this policy records the standing rule so future story pipelines
never treat "publish new version" as complete without deleting the old one.

_Gasūns_
