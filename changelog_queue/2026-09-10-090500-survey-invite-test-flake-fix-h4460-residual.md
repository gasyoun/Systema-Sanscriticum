_Created: 10-09-2026 · Last updated: 10-09-2026_

# H4460-residual: флейп CI SurveyStudentEmailInviteTest — само-диагностика + детерминированное имя (10-09-2026)

Тест `invitation_carries_survey_link_and_promises_no_reward` падал в CI 2/4 прогонов 09-09 (rerun всегда зелёный, локально не воспроизводился) анонимным «mailable was not sent» — предикат `Mail::assertSent` был монолитным `&&`-замыканием, и упавший терм не назывался.

- **Доказанная ловушка**: `str_contains($html, $user->name)` при `'name' => fake()->name()` — Faker en_US раз в ~1/300 даёт имя с `'`/`&` (O'Kon, O'Keefe), Blade экранирует его в HTML (`&#039;`) → подстрока не найдена. Живая проба: `str_contains($html, "O'Kon …") = false`, экранированная форма = true.
- **Фикс** ([SurveyStudentEmailInviteTest.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/SurveyStudentEmailInviteTest.php)): имя захардкожено `Анна Примерная` (без экранируемых символов); монолитный предикат заменён пошаговыми ассертами — `assertCount(1, sent)` с выводом консоли команды в сообщении (если флейк «0 отправленных» вернётся, упадёт с названной причиной и eligible-счётчиками), затем отдельные `assertStringContainsString/NotContainsString` на каждый терм.
- Только тест; прод-код не тронут. Pint чист; класс 12/12 зелёный под `--parallel`.
_Dr. Mārcis Gasūns_
