_Created: 10-09-2026 · Last updated: 10-09-2026_

# H4433: OrderPaymentConversion — пустить роль manager (куратор), не только admin/accountant (10-09-2026)

Находка [H4334](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H4334-OxAlpha_Uprava_nastya-filament-guide-mastery-test_07.09.26.md) (09-09-2026): куратор Настя (id 6598, role=manager) владеет списком «недожатых» заказов по должностной инструкции, но страница `/admin/order-payment-conversion` пускала только `RoleGate::finance()` (admin/accountant) — куратор её не видела.

- **Фикс** ([RoleGate.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/RoleGate.php), [OrderPaymentConversion.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/OrderPaymentConversion.php)): новый `RoleGate::salesOperator()` (admin+accountant+manager, тот же паттерн, что `managerSalesReport()`/`learningAnalytics()`) заменил `finance()` в `canAccess()`/`shouldRegisterNavigation()`. Безопасно по данным — страница показывает только воронку продаж, без сумм зарплат/выплат.
- **Тесты**: [OrderPaymentConversionTest](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/OrderPaymentConversionTest.php) — `manager_can_access_page` (было `manager_cannot_access_page`, поведение обращено сознательно), добавлены `teacher_cannot_access_page`/`student_cannot_access_page`. [CuratorAdminGuideCoverageTest](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/CuratorAdminGuideCoverageTest.php) поймал отсутствие заголовка в руководстве куратора — добавлен раздел «Конверсия заказ→оплата» в [docs/CURATOR_ADMIN_GUIDE_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CURATOR_ADMIN_GUIDE_RU.md). Полный прогон зелёный, Pint чист.
- **PR**: [#2477](https://github.com/gasyoun/Systema-Sanscriticum/pull/2477) → `c1f5d839`. Прод получает изменение через штатный server-cron `deploy.sh` (не ручной шаг).

_Dr. Mārcis Gasūns_
