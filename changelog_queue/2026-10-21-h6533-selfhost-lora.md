# H6533 — Lora самохостом: @import fonts.googleapis.com в article.css удалён

152-ФЗ: страницы article-layout (/privacy, /s/{slug}) запрашивали
fonts.googleapis.com (Lora) до любого согласия — `resources/css/article.css:12`
делал `@import` на Google Fonts, IP посетителя утекал за границу
(CSS-слепое пятно NoForeignCdnTest, найдено инкогнито-пробой H6528,
evidence PR #3207).

- `@fontsource/lora` в package.json; начертания 400/600/700 + 400-italic/600-italic
  (тот же ital,wght-набор, что в удалённом @import: 0,400;0,600;0,700;1,400;1,600)
- импорты Lora в `resources/css/fonts.css` (тот же паттерн, что остальные шрифты),
  `@import` из article.css удалён
- NoForeignCdnTest расширен: сканирует `resources/css/**/*.css` и собранные
  `public/build` css/manifest на те же 5 хостов
- `public/build` пересобран (деплой на .92 — отдельный шаг, см. PR)
