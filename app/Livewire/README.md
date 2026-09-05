_Created: 07-05-2026 · Last updated: 05-09-2026_

# app/Livewire

Livewire-компоненты для интерактивных UI-блоков без перезагрузки страницы.

## Компоненты

### `StudentDictionary`
Словарь для студента в личном кабинете.  
Поиск по санскритским словам (деванагари, IAST, кириллица), фильтрация по словарю.  
Шаблон: `resources/views/livewire/student-dictionary.blade.php`.

### `StudentPayments`
История платежей студента в личном кабинете.  
Показывает курс, сумму, дату, статус. Не требует перезагрузки для обновления.  
Шаблон: `resources/views/livewire/student-payments.blade.php`.

### `Shop/CourseCatalog`
Каталог курсов в магазине с живой фильтрацией.  
Фильтры: категория, формат (live/записи), поиск по названию.  
Шаблон: `resources/views/livewire/shop/course-catalog.blade.php`.

---

## Создание нового компонента

```bash
php artisan make:livewire ComponentName
```

Для компонентов в подпапке:
```bash
php artisan make:livewire Shop/ComponentName
```

Подключение в Blade:
```blade
<livewire:student-dictionary />
<livewire:shop.course-catalog />
```

_Dr. Mārcis Gasūns_
