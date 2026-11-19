# Веб-паритет FAQ кабинета — /dvaram/faq (H6301, тикет 5)

_Created: 09-10-2026 · Last updated: 09-10-2026_

**Model:** GLM 5.3 (`zai-coding-plan/glm-5.3`)
**Источник:** тикет 5 аудита [SELF_SERVICE_SUPPORT_UX_AUDIT_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SELF_SERVICE_SUPPORT_UX_AUDIT_2026.md) — `resources/knowledge/faq.md` кормил только TG/VK-бота (`BotKnowledgeBase`), у веб-кабинета не было статичной FAQ-поверхности.
**База ветки:** `origin/main` @ `09b3d978` (проверено перед правками — дубля не было: `CabinetFaqTool` — это API-инструмент агента за флагом `features.student_agent` OFF, не видимая поверхность; `/dvaram/help` — гид-сценарий, не FAQ).

## Что сделано

- `GET /dvaram/faq` (`student.faq`, за `auth`) — [`StudentFaqController`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/StudentFaqController.php): те же чанки [`FaqCorpusParser`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/Faq/FaqCorpusParser.php) (faq.md + лекционный сосед, кэш 10 мин), сгруппированные по категориям `##`. Без второй копии ответов, без LLM, без нового флага.
- Вид `student/faq.blade.php`: нативные `<details>`-аккордеоны (клавиатурно доступны), инкрементный текстовый фильтр (vanilla JS, progressive enhancement — без JS вся база читаема), пустой раздел → честная заглушка «ответа пока нет».
- [`FaqBodyHtml`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/FaqBodyHtml.php): тело раздела через общий `SupportText::safeHtml()` веб-чата (экранируется всё, whitelist b/i/…) + безопасный автолинк голых URL (после экранирования — атрибут не сломать).
- Путь в поддержку: `#chat` добавлен в hash-инициализацию вкладок дашборда; на странице два обычных `<a href="…#chat">` («Открыть поддержку», подсказка при пустом поиске) + фраза «позови куратора» упомянута. Мобильный и клавиатурный путь — обычные ссылки и нативные детали-элементы.
- Навигация кабинета: пункт «Вопросы и ответы» рядом с «Как пользоваться». Гид-сценарий (`/dvaram/help`, H6297-соседство) не тронут.

## Приёмка (закреплённый образец — пять операционных разделов)

`записи_пропуски`, `оплата_блоки`, `подключение`, `личный_кабинет`, `техподдержка` — темы из топ-таксономии аудита. Тест выводит ожидания из faq.md в рантайме: правки источника обязаны отражаться на странице без правок теста (дублирования текста ответов нет нигде).

## Команды и статусы (машина исполнителя, worktree h6301-drain)

| Команда | Выход |
|---|---|
| `vendor/bin/phpunit tests/Unit/FaqBodyHtmlTest.php` | **0** — OK, 4 теста / 11 утверждений |
| `vendor/bin/phpunit tests/Feature/Student/StudentFaqPageTest.php` | **0** — OK, 6 тестов / 31 утверждение |
| `php -d memory_limit=1G vendor/bin/phpunit` (BotKnowledgeBaseTest, BotMultiPersonaTest, StudentCabinetGuideCoverageTest, AccessSelfServiceTest, DebtSelfServiceTest, PublicCabinetGuideTest) | **0** — OK, 58 тестов / 210 утверждений (регрессия соседей) |
| `vendor/bin/pint --dirty` | **0** — passed (после авториска импортов в новом тесте) |

Синтетический кейс — враждебный корпус `tests/fixtures/faq_hostile.md` (`<script>`, `<img onerror>`, URL с кавычками): страница показывает payload экранированным текстом, тегом он не становится ни в одном контексте.

## Доставка

Статус: **подготовленный код** (merged PR), не «живая эксплуатация» — прод получает страницу своим деплоем; для работы поверхности ничего включать не надо (флага нет). Правки ответов по-прежнему идут в wiki ORS-FAQ → экспорт `faq.md` (файл руками не правится — см. шапку файла), веб-страница подхватывает их сама после деплоя (кэш парсера 10 минут).

## Границы соблюдены

Деньги/доступ/вебхуки не тронуты; новых сообщений, цен, расписаний, фича-флагов нет; гид-сценарий кабинета не переписан; stenogrammy/ не открывались.

_Dr. Mārcis Gasūns (по исполнению: GLM 5.3, opencode)_
