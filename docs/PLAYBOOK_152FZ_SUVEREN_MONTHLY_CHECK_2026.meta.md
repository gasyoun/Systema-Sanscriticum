# Плейбук ежемесячной 152-ФЗ-проверки suveren — H6512

_Created: 12-10-2026 · Last updated: 12-10-2026_

Документ: [docs/PLAYBOOK_152FZ_SUVEREN_MONTHLY_CHECK_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAYBOOK_152FZ_SUVEREN_MONTHLY_CHECK_2026.md)

- **Тип:** операционный плейбук (рекуррентная машинная проверка 152-ФЗ сканером suveren, домены samskrte.ru и samskrtam.ru)
- **Создан:** 12-10-2026 · handoff H6512 (GLM 5.3 Flash, ZCode), заказ MG
- **Триггер:** ежемесячно, минт новой руки со ссылкой на плейбук; launchd-авто-минт не заведён — отдельный @DO при визе MG
- **Обновлять:** строку в «Журнал прогонов» — каждый месячный прогон; механику сканера — при смене версии suveren или путей установки
- **Верификация:** независимый субагент воспроизводит env-пробу, curl-греп samskrte.ru (ожидание 4) и читает журнал на origin/main

_к.ф.н. М.Ю. Гасунс_
