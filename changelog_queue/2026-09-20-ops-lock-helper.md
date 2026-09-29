# ops-lock helper — standalone O_EXCL+TTL write-lock для прод-скриптов (20-09-2026)

- Standalone prod write-lock helper [`tools/ops_lock.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tools/ops_lock.php): O_EXCL-создание + TTL-протухание, без бутстрапа Laravel — ад-хок tinker/fix-скрипты зовут его перед любой мутацией прод-данных. Урок [H5195](https://github.com/gasyoun/Uprava/blob/main/DANGER_FACTS.md) (dual-run 20-09-2026: две агентские сессии параллельно писали одни прод-таблицы). Развёрнут на .92 в `/usr/local/bin/ops_lock` (вне app-дерева — deploy.sh его не затирает), lock-директория `/var/www/html/storage/ops-locks` (www-data:www-data, 0775).

_Гасунс_
