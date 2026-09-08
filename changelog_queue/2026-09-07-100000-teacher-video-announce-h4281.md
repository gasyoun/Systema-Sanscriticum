_Created: 07-09-2026 · Last updated: 07-09-2026_

# H4281 (приём материалов преподавателей, часть 1/3): видео-анонс курса — YouTube/RuTube/VK video в hero продающей страницы (OxAlpha z-ai/glm-5.3-flash, 07-09-2026)

Первая часть [H4281](https://github.com/gasyoun/Uprava/blob/main/handoffs/H4281-OxAlpha_Systema-Sanscriticum_teacher-materials-intake_07.09.26.md) — поле видео-анонса курса. Провайдер и embed-URL используют уже готовый парсер [`App\Support\VideoEmbed`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/VideoEmbed.php) (YouTube/RuTube/VK video/Vimeo/Kinescope), второй парсер не заводился.

- **Поле `courses.video_announce_url`** (миграция `2026_09_07_090000`, nullable, `after('image_path')`) + [`Course::videoAnnounceEmbedUrl()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Course.php).
- **Filament** ([CourseResource](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Resources/CourseResource.php)): текстовое поле рядом с `chat_url`/`zoom_link`, инлайн-валидация через `VideoEmbed::embed()` — нераспознанная ссылка не сохраняется, с понятной ошибкой.
- **Hero продающей страницы** ([shop/show.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/shop/show.blade.php)): при заполненной и распознанной ссылке в hero-блоке рендерится iframe вместо статичной обложки/типографического фолбэка; пустое или нераспознанное поле — обложка курса как раньше (regression-safe).
- Флага не заводили — поведение управляется только наличием валидной ссылки в самом поле; в [DEPLOY_QUEUE.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md) деплой обычный (авто-деплой), особого шага для Ивана нет.
- Тесты: [CourseVideoAnnounceTest](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/CourseVideoAnnounceTest.php) (5: embed-резолв YouTube, нераспознанная/пустая ссылка → null, hero рендерит iframe при валидной ссылке, hero остаётся без iframe без ссылки) — все зелёные, `CourseLandingPageTest` (4) и `VideoEmbedTest` (unit) не тронуты и зелёные. Pint clean (`--dirty`).
- **Вне скоупа этого прохода (2/3, 3/3 мисии H4281):** (2) вывод плашки курса из `course_design_assets` в карточку курса и (3) раздел «Мои материалы» для преподавателя (сдача видео-анонса/плашки/конспектов, статус-очередь куратора, уведомление) — оба требуют отдельного прохода: своя Filament/blade-поверхность для роли преподавателя, модель статус-очереди и уведомление куратору. Заявка на продолжение — в статусе закрытия H4281.

_Dr. Mārcis Gasūns_
