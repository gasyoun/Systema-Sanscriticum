# tests

PHPUnit-тесты. Используют SQLite в памяти — внешняя БД не нужна.

## Запуск

```bash
php artisan test                          # все тесты
php artisan test --filter=CourseBlockTest # конкретный класс
php artisan test tests/Unit/              # только unit
php artisan test tests/Feature/           # только feature
```

## Структура

```
tests/
├── TestCase.php             # базовый класс с RefreshDatabase
├── CreatesApplication.php   # трейт для bootstrap приложения
├── Unit/
│   ├── CourseBlockTest.php  # логика isCurrent() с граничными датами
│   ├── LessonTest.php       # методы модели Lesson
│   └── ExampleTest.php      # пример
└── Feature/
    ├── Shop/
    │   └── CourseShowTest.php       # страница курса в магазине
    ├── Student/
    │   ├── LessonAccessTest.php     # контроль доступа к урокам через группы
    │   └── OpenLessonsTest.php      # бесплатные уроки
    └── ExampleTest.php
```

## Конфигурация (`phpunit.xml`)

- **БД**: SQLite in-memory (`:memory:`) — миграции прогоняются заново для каждого теста.
- **Env**: `APP_ENV=testing`, `CACHE_DRIVER=array`, `QUEUE_CONNECTION=sync`.
- **Покрытие**: источник — `app/` directory.

## Приоритеты для написания новых тестов

Покрытие минимальное. Наиболее критичная логика без тестов:

1. `Tariff::calculateFinalPriceForUser()` — скидки лояльности и вычет апгрейда.
2. `Payment` + `PaymentObserver` — выдача доступа после оплаты.
3. `PromoCode::calculateDiscountedPrice()` — оба типа промокодов.
4. `ActivityTracker` — корректность накопления времени.
5. `LandingPage` — рендер блоков конструктора.
