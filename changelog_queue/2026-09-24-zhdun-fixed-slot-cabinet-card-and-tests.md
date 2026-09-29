# «Список ожидания»: то же правило «слот известен — не спрашиваем» на карточке кабинета + тесты

_Created: 24-09-2026 · Last updated: 24-09-2026_

- Продолжение правила MG 24-09-2026 (витрина `/online/zhdun`): карточка «Список ожидания» в кабинете ([`student/partials/waitlist-card.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/student/partials/waitlist-card.blade.php), H3815) ведёт себя так же — непустой `slot` («пн 18:00», «сб 17:00», «сб 13:00») скрывает селект «Когда удобно?», кнопка «Голосовать» остаётся, голос уходит с `slot_preference = null`.
- Регрессионные тесты добавлены на **обе** поверхности (у витринной правки их не было): [`VitrinaWaitlistPageTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/VitrinaWaitlistPageTest.php) — `test_fixed_slot_hides_when_convenient_select_on_vitrina` и `..._in_cabinet_card`; строка без слота по-прежнему получает селект.
- Тесты: `VitrinaWaitlistPageTest` 20 тестов / 81 assertion, зелёные кроме предсуществующего `test_teacher_links_use_natural_name_and_short_facet_resolves` (404, воспроизводится на нетронутом `origin/main`); два новых теста — 2/2. Pint clean.
- Остаток: деплой. Сайт живёт на `workflow_dispatch`-деплое с ручным подтверждением среды — строка в GTD.

_Гасунс_
