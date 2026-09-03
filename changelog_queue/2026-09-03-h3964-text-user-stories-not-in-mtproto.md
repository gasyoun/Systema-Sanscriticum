# H3964 live-проба (unit 1): текстовых user-сториз в MTProto НЕ СУЩЕСТВУЕТ (OxAlpha z-ai/glm-5.3-flash, 03-09-2026)

Живой замер на проде (`stories.sendStory`, MadelineProto-сессия @rusamskrtam): `inputMediaEmpty` + caption → **MEDIA_FILE_INVALID**. Причина системная, не баг формы вызова:
- В TL-схеме **layer 225** (и у вендорного MadelineProto, и в актуальном [tdlib/telegram_api.tl](https://github.com/tdlib/td/blob/master/td/generate/scheme/telegram_api.tl)) конструктора text-медиа для InputMedia НЕТ вовсе (`inputMediaEmpty/Photo/Document/…` — text-стори нет).
- Официальный Android-клиент ([TL_stories.java](https://github.com/DrKLO/Telegram/blob/master/TMessagesProj/src/main/java/org/telegram/tgnet/tl/TL_stories.java)) строит media только из photo/video-аплоадов; media-less ветки в `TL_stories_sendStory` нет.

Следствия в коде:
- **`--test-text` снят, добавлен `--test-photo=ПУТЬ`** (смок: фотосториз отправлена и удалена тем же кодом; `--probe-attempts` теперь фотосторизами).
- **persona-строки kind=text скипаются издателем с журналом** (никогда не публикуются, не роняют прогон): «текстовый контент персоны» — это пост канала, stories:publish-due (Phase 1).
- [StoryPublisher::sendTextStory](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Stories/StoryPublisher.php) — явная точка отказа с объяснением (никто не «починит» молчаливой подменой постом).

Тесты: StoriesPublishStoryTest переписан на photo-форму (12), полныйStories-набор 26 green, Pint clean.
