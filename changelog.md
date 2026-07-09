# Changelog

All notable changes to Systema-Sanscriticum are documented here.

This repository does not currently publish versioned release notes. Entries use
dated maintenance snapshots; keep upcoming work under [Unreleased] until it is
ready for a dated entry.

## [Unreleased]

## [1.1.0] - 2026-07-09

### Added
- Group recruitment shortfall notifications (H162): `Group.status/min_size/planned_start_date/start_date_override`, daily `groups:notify-forming-shortfall` command warning paid students and curators when a forming group is under-enrolled near its start date, `GroupResource` "Зафиксировать дату" reschedule action.

## [1.0.0] - 2026-06-13

### Added
- Added this changelog so repository-level changes have a stable home.
- Recorded the current repository purpose: Laravel-приложение: учебный кабинет, магазин курсов, конструктор лендингов, редактор лекций и панель администратора.

### Recent Git History
- 2026-05-29 ai-wip: add .pre-commit-config.yaml (yaml-only)
- 2026-05-29 ai-wip: add CodeQL SAST workflow (php, javascript)
- 2026-05-29 ai-wip: add .github/dependabot.yml for GitHub Actions auto-updates
- 2026-05-29 ai-wip: add CODE_OF_CONDUCT.md (Contributor Covenant 2.1)
- 2026-05-29 fix(ci): proper Vite manifest stub with entry keys
