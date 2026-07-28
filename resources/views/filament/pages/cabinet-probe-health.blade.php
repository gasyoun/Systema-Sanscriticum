<x-filament-panels::page>
    <div class="space-y-4 text-sm text-gray-600 dark:text-gray-300">
        <p>
            Прогон <code>php artisan cabinet:probe</code> каждые 15 минут:
            public <code>/login</code> + <code>/online</code>, smoke-manager, опционально smoke-student.
            История пишется в <code>cabinet_probe_runs</code>.
        </p>
        <p>
            TG critical: <code>CABINET_PROBE_TELEGRAM_CHAT_ID</code> ·
            soft: <code>CABINET_PROBE_TELEGRAM_SOFT_CHAT_ID</code> ·
            healthchecks: <code>CABINET_PROBE_PING_URL</code> / <code>HEARTBEAT_PING_URL</code>.
        </p>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
