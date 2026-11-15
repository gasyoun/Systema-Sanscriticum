- «Книжный клуб» — товар-доставка для кампании planeta 219924 (H8195,
  [PR #4129](https://github.com/gasyoun/Systema-Sanscriticum/pull/4129)): чекаут
  оплаты доставки книг, уже оплаченных на кампании. Тарифы —
  `config/book_club.php` (РФ 300 ₽ за посылку до 2 кг; заграница по странам:
  LV 5100 · IL 5800 · DE 6200 · US 6600 · другая 6600 ₽). Заказ —
  `book_club_orders` (почтовый адрес + planeta email/№ для ручного матчинга);
  платёж `tariff='book_club_delivery'`, `course_id=null` — доступ к курсам не
  выдаётся. РФ → Tochka (после commit, purpose «Заказ №»), заграница →
  PayPal-заявка (pending + уведомление кураторов, сверка вручную). За
  фича-флагом `book_club_delivery` (`BOOK_CLUB_DELIVERY`, дефолт OFF —
  маршруты `/knizhny-klub/dostavka` отдают 404).
