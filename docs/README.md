# Документация Systema Sanscriticum

> Это вход в дерево документов, а не каталог для последовательного чтения. Начни с корневой [PROJECT_MAP.md](../PROJECT_MAP.md), затем открой один релевантный раздел ниже.

## Короткие карты доменов

- [Money / access](domains/money-access.md) — оплата, тарифы, доступ, курсы-записи.
- [Support](domains/support.md) — Helpdesk, каналы поддержки, Telegram analytics, идентичность.
- [SRS](domains/srs.md) — колоды, повторения, FSRS и связь с учебным контуром.

## Действующие правила и навигация

| Документ | Когда читать |
|---|---|
| [../AGENTS.md](../AGENTS.md) | перед любой задачей: приоритеты, high-risk areas, validation defaults |
| [../CLAUDE.md](../CLAUDE.md) | только соответствующий задаче раздел: money, deploy, architecture, integrations |
| [../README.md](../README.md) | продуктовое описание, пользовательские сценарии, стек и дорожная карта |
| [agents/domain.md](agents/domain.md) | правила ленивого чтения `CONTEXT.md` и ADR; отсутствующие файлы не создавать заранее |
| [../tests/README.md](../tests/README.md) | организация и запуск PHPUnit-тестов |

## Деньги, доступ и финансы

- [money-access-core-manual.md](money-access-core-manual.md) — глубокое описание алгебры доступа, цены и платёжного контура.
- [money-core-adversarial-review.md](money-core-adversarial-review.md) — adversarial review money-core.
- [webhook-security.md](webhook-security.md) — безопасность вебхуков.
- [finance-manual.md](finance-manual.md) — финансовый контур и панель.
- [accountant-guide.md](accountant-guide.md) — процедуры для бухгалтера.
- [revenue-recognition.md](revenue-recognition.md) — признание выручки.
- [FINANCE_REVIEW_RHYTHM.md](FINANCE_REVIEW_RHYTHM.md) — ритм финансового контроля.
- [ARCHITECTURE_SYSTEMA_TEACHER_STUDENT_SETTLEMENT_GROUP_REVIEWERS.md](ARCHITECTURE_SYSTEMA_TEACHER_STUDENT_SETTLEMENT_GROUP_REVIEWERS.md) — рецензенты и взаиморасчёты.
- [STANDING_POLICY_ADMIN_SEES_TEACHER_SURFACES_2026.md](STANDING_POLICY_ADMIN_SEES_TEACHER_SURFACES_2026.md) — видимость teacher surfaces.

Перед изменением поведения сначала прочитай [карту Money / access](domains/money-access.md): она показывает обязательные инварианты и тесты.

## Поддержка и идентичность

- [support-subsystem-map.md](support-subsystem-map.md) — фактическая архитектура поддержки; читать перед доработками.
- [support-identity.md](support-identity.md) — каноническая модель внешней идентичности.
- [telegram-userbot-inventory.md](telegram-userbot-inventory.md) — инвентарь Telegram userbot.
- [telegram-bots-inventory.md](telegram-bots-inventory.md) — инвентарь Telegram-ботов.
- [cabinet-bot.md](cabinet-bot.md) — бот кабинета.
- [jivo.md](jivo.md) — продуктовый benchmark, а не источник истины о текущем коде.

## Студент, курсы и обучение

- [student-manual.md](student-manual.md) — руководство студента.
- [onboarding-student.md](onboarding-student.md) — онбординг.
- [lila-games-manual.html](lila-games-manual.html) — игры-тренажёры.
- [group-recruitment-module-spec.md](group-recruitment-module-spec.md) — спецификация набора групп.
- [course-landing-spec.md](course-landing-spec.md) — лендинг курса.
- [certificate-auto-issue-guide.md](certificate-auto-issue-guide.md) — автоматическая выдача сертификатов.
- [access-self-service-spec.md](access-self-service-spec.md) — self-service доступа.

## Эксплуатация

- [deploy.md](deploy.md) — документированный деплой.
- [server-resource-guards.md](server-resource-guards.md) — ресурсные ограничения и защита сервера.
- [SERVER_SOFT_ALERT_PLAYBOOK.md](SERVER_SOFT_ALERT_PLAYBOOK.md) — playbook soft-alert.
- [UPTIME_BETTERSTACK_MONITORING_RU.md](UPTIME_BETTERSTACK_MONITORING_RU.md) — мониторинг uptime.
- [agents/issue-tracker.md](agents/issue-tracker.md) — работа с issue tracker.
- [agents/triage-labels.md](agents/triage-labels.md) — метки triage.

## Как отличать текущее правило от истории

1. Сначала код, миграции и действующие always-on правила из `AGENTS.md` / `CLAUDE.md`.
2. Затем короткая доменная карта и связанная действующая спецификация.
3. Документы с `PLAN_`, `ROADMAP_`, `AUDIT_`, `VERIFICATION_`, `HARVEST_`, датой в имени или описанием прошедшего этапа не считать автоматически текущим состоянием.
4. Наличие документа не доказывает, что функция включена в проде: проверяй feature flags и актуальный код.
5. При противоречии не выбирай источник молча: зафиксируй расхождение и проверь код/тесты.

## Правила пополнения дерева

- Не создавай новый общий обзор, если можно обновить существующий доменный документ и добавить ссылку сюда.
- Длинный документ начинай с назначения, краткого статуса и оглавления.
- Пиши, **когда документ обязателен к прочтению** и какой код считается источником истины.
- Исторические отчёты не переписывай под текущее состояние; создавай актуальный документ и связывай их.
- При изменении поведения обновляй действующую спецификацию в том же PR; не обновляй все связанные исторические материалы.
- Не включай секреты, реальные персональные данные, живые выплаты и чувствительные скриншоты.
