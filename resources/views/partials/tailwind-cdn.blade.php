{{--
  Tailwind Play CDN + дизайн-токены бренда.

  Витрина, чекаут, кабинет студента и страницы авторизации собирают Tailwind
  не из `resources/css/app.css`, а этим CDN. Play CDN читает ТОЛЬКО глобальный
  `tailwind.config` и ничего не знает про `@theme { --color-brand: … }`, поэтому
  токены приходится продублировать здесь.

  Чем это кончилось однажды (H2560, коммит 68f80829): токены завели в app.css,
  ~1100 классов `[#E85C24]` заменили на `brand`/`brand-hover` — и на всех
  CDN-страницах эти утилиты просто перестали существовать. Кнопка оплаты
  «К безопасной оплате» осталась без фона (`bg-gradient-to-r` без стопов даёт
  невалидный `linear-gradient`), белый текст на белой карточке стал невидим,
  а в кабинете пропало оранжевое подчёркивание активного пункта меню.

  Значения ОБЯЗАНЫ совпадать с `--color-brand` / `--color-brand-hover` в
  resources/css/app.css — расхождение ловит tests/Feature/Ui/BrandTokenParityTest.

  Подключать CDN мимо этого партиала нельзя: тот же тест проверяет, что голого
  тега `cdn.tailwindcss.com` в других шаблонах нет.
--}}
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = {
        theme: {
            extend: {
                colors: {
                    'brand': '#e85c24',
                    'brand-hover': '#d04a15',
                },
            },
        },
    };
</script>
