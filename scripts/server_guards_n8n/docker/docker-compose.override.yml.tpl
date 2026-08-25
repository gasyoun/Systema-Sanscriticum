# MANAGED FILE — ставится scripts/server_guards_apply.sh --profile n8n в
# /opt/n8n/docker-compose.override.yml. Значения — из server_guards_n8n.conf.
#
# ПОЧЕМУ ОТДЕЛЬНЫЙ override, а не правка docker-compose.yml. Основной файл —
# чужой и живой; переписать его значило бы взять на себя ответственность за
# каждую его строку (env_file, тома, патч task-runner'а). Compose сам сливает
# override с основным файлом, поэтому добавка изолирована, читается одним
# взглядом и отменяется удалением ОДНОГО файла. Ограда волны (PLAN §4) запрещает
# терять состояние без человека — здесь терять нечего.
#
# КОГДА ЭТО ВСТУПАЕТ В СИЛУ. Потолки памяти УЖЕ применены на живых контейнерах
# через `docker update` (n8n-container-limits.sh) — без пересоздания. Этот файл
# нужен, чтобы первый же законный `docker compose up -d` (обновление образа,
# рукой человека) НЕ СНЯЛ их обратно: иначе предохранитель исчез бы молча.
#
# healthcheck — единственное, что докером на живом контейнере не меняется вовсе.
# Он появится при следующем законном пересоздании, и до тех пор verify честно
# показывает health=none как warning, а не как «здоров».
services:
  n8n:
    mem_limit: @@N8N_MEMORY_LIMIT@@
    # Равен mem_limit: контейнеру своп не положен. Разогнавшийся процесс умрёт
    # внутри своей cgroup, а не утащит машину в свопинг — именно свопинг дал
    # класс аварий 24-07 и 28-07 на .92.
    memswap_limit: @@N8N_MEMORY_LIMIT@@
    mem_reservation: @@N8N_MEMORY_RESERVATION@@
    pids_limit: @@N8N_PIDS_LIMIT@@
    healthcheck:
      # /healthz проверен живым 25-08-2026 (HTTP 200). wget есть в образе n8n
      # (busybox); curl там нет — проверено, а не предположено.
      test: ["CMD", "wget", "-q", "-O", "/dev/null", "http://127.0.0.1:5678/healthz"]
      interval: @@N8N_HEALTHCHECK_INTERVAL@@
      timeout: @@N8N_HEALTHCHECK_TIMEOUT@@
      retries: @@N8N_HEALTHCHECK_RETRIES@@
      start_period: @@N8N_HEALTHCHECK_START_PERIOD@@

  caddy:
    # Пин образа. Было `caddy:latest` — то есть «неизвестно что будет завтра»:
    # ровно эту дыру закрыли для n8n 01-08-2026 (H1961, C07), а Caddy остался.
    # Версия и digest сняты с ЖИВОГО образа 25-08-2026, так что пин ничего не
    # меняет сегодня и фиксирует ровно то, что уже работает.
    image: @@CADDY_IMAGE_PIN@@
    mem_limit: @@CADDY_MEMORY_LIMIT@@
    memswap_limit: @@CADDY_MEMORY_LIMIT@@
    mem_reservation: @@CADDY_MEMORY_RESERVATION@@
    pids_limit: @@CADDY_PIDS_LIMIT@@
    healthcheck:
      # Caddy admin API на :2019 выключен (проверено: connection refused), а
      # busybox-wget по :80 получил бы редирект на https. Поэтому проверяем не
      # HTTP, а то, что процесс жив и конфиг цел — `caddy validate` не ходит по
      # сети и не зависит от сертификатов.
      test: ["CMD", "caddy", "validate", "--config", "/etc/caddy/Caddyfile"]
      interval: 60s
      timeout: 10s
      retries: 3
      start_period: 30s
