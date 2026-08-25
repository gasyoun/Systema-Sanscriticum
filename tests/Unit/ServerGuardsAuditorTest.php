<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ServerGuards\GuardFinding;
use App\Support\ServerGuards\GuardSpec;
use App\Support\ServerGuards\ServerGuardsAuditor;
use RuntimeException;
use Tests\Support\FakeSystemInspector;
use Tests\TestCase;

/**
 * H1914: пропажа предохранителя обязана быть НАЙДЕНА и НАЗВАНА.
 *
 * Каждый тест снимает ровно один предохранитель с «здорового прода», собранного
 * из настоящих scripts/server_guards.conf + scripts/server_guards/, и требует,
 * чтобы аудитор назвал именно его.
 */
class ServerGuardsAuditorTest extends TestCase
{
    private string $templateRoot;

    private GuardSpec $spec;

    protected function setUp(): void
    {
        parent::setUp();

        $this->templateRoot = base_path('scripts/server_guards');
        $this->spec = GuardSpec::fromFile(base_path('scripts/server_guards.conf'));
    }

    private function healthy(): FakeSystemInspector
    {
        return FakeSystemInspector::healthy(
            $this->spec,
            $this->templateRoot,
            (string) file_get_contents($this->templateRoot.'/manifest.psv'),
        );
    }

    private function auditor(FakeSystemInspector $sys): ServerGuardsAuditor
    {
        return new ServerGuardsAuditor($this->spec, $sys, $this->templateRoot);
    }

    /**
     * Аудитор на спеке БЕЗ waivers: severity самой проверки (tmpfs-cap и др.)
     * тестируется до понижения — иначе активный в conf waiver делал бы эти
     * ассерты нечитаемыми.
     */
    private function waiverlessAuditor(FakeSystemInspector $sys): ServerGuardsAuditor
    {
        $lines = array_values(array_filter(
            explode("\n", (string) file_get_contents(base_path('scripts/server_guards.conf'))),
            static fn (string $line): bool => preg_match('/^GUARD_WAIVERS/', $line) !== 1,
        ));

        return new ServerGuardsAuditor(GuardSpec::fromString(implode("\n", $lines)), $sys, $this->templateRoot);
    }

    /**
     * @param  list<GuardFinding>  $findings
     */
    private function lines(array $findings): string
    {
        return implode("\n", array_map(static fn (GuardFinding $f): string => $f->line(), $findings));
    }

    public function test_a_fully_guarded_host_produces_no_findings(): void
    {
        $findings = $this->waiverlessAuditor($this->healthy())->audit();

        $this->assertSame('', $this->lines($findings), 'здоровый прод не должен давать находок');
        $this->assertFalse(ServerGuardsAuditor::hasBlocking($findings));
    }

    public function test_stopped_earlyoom_is_reported_by_name(): void
    {
        $sys = $this->healthy();
        $sys->active['earlyoom'] = false;

        $findings = $this->auditor($sys)->audit();

        $this->assertTrue(ServerGuardsAuditor::hasBlocking($findings));
        $this->assertStringContainsString('[critical] unit-active: earlyoom не active', $this->lines($findings));
    }

    public function test_missing_earlyoom_config_is_critical(): void
    {
        $sys = $this->healthy();
        unset($sys->files['/etc/default/earlyoom']);

        $findings = $this->auditor($sys)->audit();

        $this->assertStringContainsString('/etc/default/earlyoom отсутствует', $this->lines($findings));
        $this->assertContains(GuardFinding::CRITICAL, array_map(fn ($f) => $f->severity, $findings));
    }

    public function test_bare_schedule_run_in_crontab_is_critical(): void
    {
        $sys = $this->healthy();
        $user = $this->spec->get('APP_USER');
        $sys->crontabs[$user] = "* * * * * /usr/bin/php /var/www/html/artisan schedule:run >> /dev/null 2>&1\n";

        $lines = $this->lines($this->auditor($sys)->audit());

        $this->assertStringContainsString('голый `artisan schedule:run`', $lines);
        $this->assertStringContainsString('нет строки через /usr/local/sbin/systema-schedule-run.sh', $lines);
    }

    public function test_watchdog_lines_folded_back_into_the_scheduler_are_critical(): void
    {
        $sys = $this->healthy();
        $user = $this->spec->get('APP_USER');
        // Ровно та конфигурация, которая 28-07 заглушила тревогу: сторожа больше
        // не имеют своей строки cron и делят судьбу с планировщиком.
        $sys->crontabs[$user] = "* * * * * /usr/local/sbin/systema-schedule-run.sh >> /dev/null 2>&1\n";

        $lines = $this->lines($this->auditor($sys)->audit());

        $this->assertStringContainsString('нет отдельной строки cron для cabinet:probe', $lines);
        $this->assertStringContainsString('нет отдельной строки cron для heartbeat:ping', $lines);
    }

    public function test_watchdog_schedule_drift_is_reported(): void
    {
        $sys = $this->healthy();
        $user = $this->spec->get('APP_USER');
        $sys->crontabs[$user] = str_replace(
            '*/15 * * * *',
            '17 4 * * *',
            (string) $sys->crontabs[$user],
        );

        $this->assertStringContainsString(
            'cabinet:probe: расписание не «*/15 * * * *»',
            $this->lines($this->auditor($sys)->audit()),
        );
    }

    public function test_removed_memory_ceiling_is_critical(): void
    {
        $sys = $this->healthy();
        $sys->unitProperties['cron|MemoryMax'] = 'infinity';

        $lines = $this->lines($this->auditor($sys)->audit());

        $this->assertStringContainsString('cron.service: MemoryMax=infinity', $lines);
        $this->assertStringContainsString('потолка НЕТ', $lines);
    }

    public function test_lost_oom_policy_is_critical(): void
    {
        $sys = $this->healthy();
        $sys->unitProperties['cron|OOMPolicy'] = 'continue';

        $this->assertStringContainsString(
            '[critical] systemd: cron.service: OOMPolicy=continue, ожидалось kill',
            $this->lines($this->auditor($sys)->audit()),
        );
    }

    public function test_memory_numbers_in_a_second_drop_in_are_caught(): void
    {
        $sys = $this->healthy();
        // Ловушка 29-07: эффективное значение решает сортировка имён файлов.
        $sys->files['/etc/systemd/system/cron.service.d/zz-someone-else.conf'] = "[Service]\nMemoryMax=16G\n";

        $lines = $this->lines($this->auditor($sys)->audit());

        $this->assertStringContainsString('[critical] single-definition', $lines);
        $this->assertStringContainsString('zz-someone-else.conf', $lines);
    }

    public function test_commented_out_memory_directive_in_another_drop_in_is_not_a_violation(): void
    {
        $sys = $this->healthy();
        $sys->files['/etc/systemd/system/cron.service.d/zz-notes.conf'] = "[Service]\n# MemoryMax=16G — про историю\n";

        $this->assertStringNotContainsString('single-definition', $this->lines($this->auditor($sys)->audit()));
    }

    public function test_unlimited_php_cli_memory_is_critical(): void
    {
        $sys = $this->healthy();
        $sys->phpCliMemoryLimit = '-1';

        $this->assertStringContainsString(
            '[critical] php-cli: memory_limit CLI = -1',
            $this->lines($this->auditor($sys)->audit()),
        );
    }

    public function test_hand_edited_guard_file_is_reported_as_drift(): void
    {
        $sys = $this->healthy();
        $sys->files['/usr/local/sbin/systema-schedule-run.sh'] = "#!/bin/bash\nphp /var/www/html/artisan schedule:run\n";

        $findings = $this->auditor($sys)->audit();

        $this->assertTrue(ServerGuardsAuditor::hasBlocking($findings), 'расхождение обязано портить код возврата');
        $this->assertStringContainsString('systema-schedule-run.sh разошёлся с репозиторием', $this->lines($findings));
    }

    public function test_wrong_file_mode_is_reported(): void
    {
        $sys = $this->healthy();
        $sys->modes['/usr/local/sbin/systema-schedule-run.sh'] = '644';

        $this->assertStringContainsString('права 644, ожидались 755', $this->lines($this->auditor($sys)->audit()));
    }

    public function test_dead_observability_timer_is_a_warning_not_a_silence(): void
    {
        $sys = $this->healthy();
        $sys->active['memwatch.timer'] = false;

        $findings = $this->auditor($sys)->audit();

        $this->assertStringContainsString('[warning] unit-active: memwatch.timer не active', $this->lines($findings));
        $this->assertTrue(ServerGuardsAuditor::hasBlocking($findings));
    }

    public function test_fpm_pool_drift_is_a_warning(): void
    {
        $sys = $this->healthy();
        $pool = '/etc/php/'.$this->spec->get('PHP_VERSION').'/fpm/pool.d/www.conf';
        $sys->files[$pool] = "[www]\npm.max_children = 5\npm.max_requests = 500\n";

        $this->assertStringContainsString(
            '[warning] php-fpm: pm.max_children = 5, ожидалось 12',
            $this->lines($this->auditor($sys)->audit()),
        );
    }

    public function test_tripped_auto_deploy_breaker_is_critical_and_names_the_reason(): void
    {
        $sys = $this->healthy();
        $breaker = rtrim($this->spec->get('APP_DIR'), '/').'/storage/auto_deploy.disabled';
        $sys->files[$breaker] = "2026-07-30T09:00:00Z deploy.sh завершился с кодом 1 — авто-деплой остановлен\n";

        $findings = $this->auditor($sys)->audit();

        $this->assertTrue(ServerGuardsAuditor::hasBlocking($findings));
        $line = $this->lines($findings);
        $this->assertStringContainsString('[critical] auto-deploy', $line);
        $this->assertStringContainsString('deploy.sh завершился с кодом 1', $line);
        $this->assertStringContainsString('auto_deploy.disabled', $line);
    }

    public function test_rolled_back_breaker_is_a_warning_not_critical(): void
    {
        $sys = $this->healthy();
        $breaker = rtrim($this->spec->get('APP_DIR'), '/').'/storage/auto_deploy.disabled';
        $sys->files[$breaker] = "2026-07-30T09:00:00Z [rolled-back] deploy.sh завершился с кодом 1; автоматически откатились на abc1234, сайт жив — чинить можно без спешки\n";

        $findings = $this->auditor($sys)->audit();

        $line = $this->lines($findings);
        $this->assertStringContainsString('[warning] auto-deploy', $line);
        $this->assertStringNotContainsString('[critical] auto-deploy', $line);
        $this->assertStringContainsString('сайт жив', $line);
    }

    public function test_blocked_preflight_breaker_is_a_warning_not_critical(): void
    {
        $sys = $this->healthy();
        $breaker = rtrim($this->spec->get('APP_DIR'), '/').'/storage/auto_deploy.disabled';
        $sys->files[$breaker] = "2026-08-01T02:00:00Z [blocked-preflight] deploy.sh завершился с кодом 1; HEAD не сдвинулся (65e660bd), health чист — разобрать git status / preflight, не паника\n";

        $findings = $this->auditor($sys)->audit();

        $line = $this->lines($findings);
        $this->assertStringContainsString('[warning] auto-deploy', $line);
        $this->assertStringNotContainsString('[critical] auto-deploy', $line);
        $this->assertStringContainsString('blocked-preflight', $line);
        $this->assertStringContainsString('health чист', $line);
    }

    public function test_timeout_alive_breaker_is_a_warning_not_critical(): void
    {
        $sys = $this->healthy();
        $breaker = rtrim($this->spec->get('APP_DIR'), '/').'/storage/auto_deploy.disabled';
        $sys->files[$breaker] = "2026-08-01T11:33:25Z [timeout-alive] deploy.sh завершился с кодом 124; автооткат не уложился/не помог, но health чист (smoke 200) — сайт жив, Артём не нужен\n";

        $findings = $this->auditor($sys)->audit();

        $line = $this->lines($findings);
        $this->assertStringContainsString('[warning] auto-deploy', $line);
        $this->assertStringNotContainsString('[critical] auto-deploy', $line);
        $this->assertStringContainsString('timeout-alive', $line);
        // Message uses instrumental «с кодом 124» (not bare «код 124»).
        $this->assertStringContainsString('кодом 124', $line);
    }

    public function test_tracked_dirty_non_pdf_is_a_warning(): void
    {
        $sys = $this->healthy();
        $sys->trackedDirtyPaths = [
            'app/Jobs/SendPaymentToSheetJob.php',
            'config/services.php',
            'public/docs/oferta.pdf',
        ];

        $findings = $this->auditor($sys)->audit();

        $line = $this->lines($findings);
        $this->assertStringContainsString('[warning] auto-deploy', $line);
        $this->assertStringContainsString('tracked dirty', $line);
        $this->assertStringContainsString('SendPaymentToSheetJob.php', $line);
        $this->assertStringNotContainsString('oferta.pdf', $line);
        $this->assertStringNotContainsString('[critical] auto-deploy', $line);
    }

    public function test_pdf_only_dirty_is_silent(): void
    {
        $sys = $this->healthy();
        $sys->trackedDirtyPaths = ['public/docs/oferta.pdf', 'public/docs/policy.pdf'];

        $findings = $this->auditor($sys)->audit();

        $this->assertStringNotContainsString('tracked dirty', $this->lines($findings));
    }

    public function test_missing_auto_deploy_cron_line_is_a_warning(): void
    {
        $sys = $this->healthy();
        $sys->crontabs['root'] = "# пусто\n";

        $findings = $this->auditor($sys)->audit();

        $this->assertStringContainsString(
            '[warning] auto-deploy: в crontab root нет строки systema-auto-deploy-run.sh',
            $this->lines($findings),
        );
    }

    public function test_auto_deploy_schedule_drift_is_a_warning(): void
    {
        $sys = $this->healthy();
        $sys->crontabs['root'] = "0 4 * * * /usr/local/sbin/systema-auto-deploy-run.sh >> /dev/null 2>&1\n";

        $findings = $this->auditor($sys)->audit();

        $this->assertStringContainsString(
            'авто-деплой: расписание не «'.$this->spec->get('AUTO_DEPLOY_SCHEDULE').'»',
            $this->lines($findings),
        );
    }

    public function test_absent_swap_is_reported_but_does_not_fail_the_check(): void
    {
        $sys = $this->healthy();
        $sys->swapTotalBytes = 0;

        $findings = $this->waiverlessAuditor($sys)->audit();

        $this->assertCount(1, $findings);
        $this->assertSame(GuardFinding::INFO, $findings[0]->severity);
        $this->assertStringContainsString('хосте Proxmox', $findings[0]->message);
        $this->assertFalse(
            ServerGuardsAuditor::hasBlocking($findings),
            'своп внутри LXC не заводится — это не повод падать',
        );
    }

    public function test_spec_and_bash_read_the_same_file_the_same_way(): void
    {
        $spec = GuardSpec::fromString(<<<'CONF'
            # комментарий
            PLAIN=value
            QUOTED="two words"
              INDENTED=ignored
            SIZE=2G
            LIST="a, b,c"
            CONF);

        $this->assertSame('value', $spec->get('PLAIN'));
        $this->assertSame('two words', $spec->get('QUOTED'));
        $this->assertFalse($spec->has('INDENTED'), 'строки с ведущим пробелом bash тоже пропускает');
        $this->assertSame(2 * 1024 ** 3, $spec->bytes('SIZE'));
        $this->assertSame(['a', 'b', 'c'], $spec->csv('LIST'));
    }

    public function test_spec_refuses_shell_substitutions(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/подстановки/');

        GuardSpec::fromString("APP_DIR=/var/www\nDERIVED=\${APP_DIR}/storage\n");
    }

    public function test_spec_keeps_a_bare_dollar_because_earlyoom_regexes_need_it(): void
    {
        $spec = GuardSpec::fromString("EARLYOOM_PREFER_RE=\"^php[0-9.]*$\"\n");

        $this->assertSame('^php[0-9.]*$', $spec->get('EARLYOOM_PREFER_RE'));
    }

    public function test_render_refuses_to_leave_a_placeholder_behind(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/@@NOT_IN_CONF@@/');

        GuardSpec::fromString("A=1\n")->render('value = @@NOT_IN_CONF@@');
    }

    // ── H3121: cgroup крона и живость планировщика ─────────────────────────

    public function test_cron_cgroup_over_memory_high_is_critical(): void
    {
        $sys = $this->healthy();
        // Ровно состояние 19-08-2026: группа НАД порогом. Хостовой памяти при
        // этом было 8.5 ГиБ — то есть все остальные проверки зелены.
        $sys->files['/sys/fs/cgroup/system.slice/cron.service/memory.current'] =
            (string) ($this->spec->bytes('CRON_MEMORY_HIGH') + 1);

        $findings = $this->auditor($sys)->audit();

        $this->assertTrue(ServerGuardsAuditor::hasBlocking($findings));
        $this->assertStringContainsString('[critical] cgroup: cron.service занял', $this->lines($findings));
        $this->assertStringContainsString('ТОРМОЗИТ всю группу', $this->lines($findings));
    }

    public function test_cron_cgroup_near_memory_high_is_only_a_warning(): void
    {
        $sys = $this->healthy();
        $sys->files['/sys/fs/cgroup/system.slice/cron.service/memory.current'] =
            (string) (int) ($this->spec->bytes('CRON_MEMORY_HIGH') * 0.85);

        $findings = $this->auditor($sys)->audit();

        $this->assertStringContainsString('[warning] cgroup:', $this->lines($findings));
        $this->assertStringNotContainsString('[critical] cgroup:', $this->lines($findings));
    }

    public function test_unreadable_cgroup_is_not_a_finding(): void
    {
        // Не cgroup v2 / чужая машина / CI: проверка обязана молчать, а не
        // выдумывать аварию там, где смотреть не на что.
        $sys = $this->healthy();
        unset($sys->files['/sys/fs/cgroup/system.slice/cron.service/memory.current']);

        $this->assertStringNotContainsString('cgroup:', $this->lines($this->auditor($sys)->audit()));
    }

    public function test_stale_scheduler_stamp_is_critical(): void
    {
        $sys = $this->healthy();
        $path = rtrim($this->spec->get('APP_DIR'), '/').'/storage/framework/schedule-run.stamp';
        $stale = (int) $this->spec->get('SCHEDULER_STAMP_MAX_MINUTES') + 5;
        $sys->files[$path] = (string) (time() - $stale * 60);

        $findings = $this->auditor($sys)->audit();

        $this->assertTrue(ServerGuardsAuditor::hasBlocking($findings));
        $this->assertStringContainsString('[critical] scheduler-stamp:', $this->lines($findings));
        $this->assertStringContainsString('schedule-run.lock', $this->lines($findings));
    }

    public function test_absent_scheduler_stamp_is_info_not_alarm(): void
    {
        $sys = $this->healthy();
        unset($sys->files[rtrim($this->spec->get('APP_DIR'), '/').'/storage/framework/schedule-run.stamp']);

        $findings = $this->auditor($sys)->audit();

        $this->assertStringContainsString('[info] scheduler-stamp:', $this->lines($findings));
        $this->assertFalse(ServerGuardsAuditor::hasBlocking($findings));
    }

    public function test_madeline_daemon_unit_without_a_ceiling_is_critical(): void
    {
        $sys = $this->healthy();
        $sys->unitProperties['systema-madeline-daemon|MemoryMax'] = 'infinity';

        $findings = $this->auditor($sys)->audit();

        $this->assertTrue(ServerGuardsAuditor::hasBlocking($findings));
        $this->assertStringContainsString('systema-madeline-daemon.service: MemoryMax=infinity', $this->lines($findings));
        $this->assertStringContainsString('потолка НЕТ', $this->lines($findings));
    }

    public function test_stopped_madeline_daemon_unit_is_reported_by_name(): void
    {
        // Юнит стоит → демон при следующей же надобности родится под кроном,
        // то есть дефект H3121 вернётся целиком.
        $sys = $this->healthy();
        $sys->active['systema-madeline-daemon'] = false;

        $findings = $this->auditor($sys)->audit();

        $this->assertStringContainsString('[critical] unit-active: systema-madeline-daemon не active', $this->lines($findings));
    }

    public function test_every_manifest_row_points_at_an_existing_template(): void
    {
        foreach ($this->auditor($this->healthy())->manifest() as $row) {
            $this->assertFileExists(
                $this->templateRoot.'/'.$row['template'],
                "манифест ссылается на несуществующий шаблон {$row['template']}",
            );
            $this->assertStringStartsWith('/', $row['dest'], 'путь установки обязан быть абсолютным');
        }
    }

    // ── H3181, волна 1: четыре живые опасности .92 ──────────────────────────

    public function test_tmpfs_without_an_explicit_size_is_critical(): void
    {
        $sys = $this->healthy();
        // Ровно то, что показывал прод 19-08-2026: tmpfs без size=, то есть
        // потолок в половину памяти ХОСТА.
        $sys->files['/proc/mounts'] = "tmpfs /tmp tmpfs rw,nosuid,nodev,nr_inodes=1048576 0 0\n";

        $findings = $this->waiverlessAuditor($sys)->audit();

        $this->assertTrue(ServerGuardsAuditor::hasBlocking($findings));
        $this->assertStringContainsString('[critical] tmpfs-cap: /tmp смонтирован tmpfs БЕЗ явного size=', $this->lines($findings));
    }

    /**
     * Замер .92 19-08-2026: /tmp несёт uid=100000 — его смонтировал ХОСТ, вне
     * пространства имён контейнера. Ни drop-in, ни fstab там не работают, а
     * `mount -o remount` падает на «Invalid uid '100000'». Critical обязан
     * остаться (опасность та же), но посылать человека в applier — врать ему.
     */
    public function test_a_host_mounted_tmpfs_names_the_host_as_the_place_to_fix_it(): void
    {
        $sys = $this->healthy();
        $sys->files['/proc/mounts'] =
            "tmpfs /tmp tmpfs rw,nosuid,nodev,nr_inodes=1048576,uid=100000,gid=100000,inode64 0 0\n";

        $lines = $this->lines($this->waiverlessAuditor($sys)->audit());

        $this->assertStringContainsString('[critical] tmpfs-cap:', $lines);
        $this->assertStringContainsString('на стороне хоста Proxmox', $lines);
        $this->assertStringNotContainsString('Вернуть: scripts/server_guards_apply.sh', $lines);
    }

    public function test_a_container_owned_tmpfs_still_points_at_the_applier(): void
    {
        $sys = $this->healthy();
        $sys->files['/proc/mounts'] = "tmpfs /tmp tmpfs rw,nosuid,nodev,nr_inodes=1048576 0 0\n";

        $lines = $this->lines($this->waiverlessAuditor($sys)->audit());

        $this->assertStringContainsString('Вернуть: scripts/server_guards_apply.sh', $lines);
        $this->assertStringNotContainsString('Proxmox', $lines);
    }

    public function test_tmpfs_raised_above_the_repo_value_is_a_warning_not_a_failure(): void
    {
        $sys = $this->healthy();
        $sys->files['/proc/mounts'] = 'tmpfs /tmp tmpfs rw,size='
            .intdiv($this->spec->bytes('TMP_TMPFS_SIZE') * 4, 1024)."k 0 0\n";

        $findings = $this->waiverlessAuditor($sys)->audit();

        $this->assertStringContainsString('[warning] tmpfs-cap: /tmp: size=', $this->lines($findings));
    }

    public function test_tmp_on_a_real_disk_is_not_a_finding(): void
    {
        $sys = $this->healthy();
        $sys->files['/proc/mounts'] = "/dev/sda2 /tmp ext4 rw,relatime 0 0\n";

        $this->assertStringNotContainsString('tmpfs-cap', $this->lines($this->waiverlessAuditor($sys)->audit()));
    }

    // ── Waiver (2026-08-25): осознанно терпимые находки ─────────────────────

    public function test_waiver_downgrades_a_matching_critical_to_info(): void
    {
        // Прод с 19-08-2026: host-side /tmp без size= критичен и не чинится
        // изнутри гостя. Waiver гасит тревогу (и деплойный exit 75, и суточный
        // Telegram), но находка остаётся видимой и помечена датой.
        $sys = $this->healthy();
        $sys->files['/proc/mounts'] =
            "tmpfs /tmp tmpfs rw,nosuid,nodev,nr_inodes=1048576,uid=100000,gid=100000,inode64 0 0\n";

        $findings = $this->auditor($sys)->audit();

        $lines = $this->lines($findings);
        $this->assertStringContainsString('[info] tmpfs-cap:', $lines);
        $this->assertStringContainsString(' [waiver до '.$this->spec->get('GUARD_WAIVERS_EXPIRES').']', $lines);
        $this->assertFalse(ServerGuardsAuditor::hasBlocking($findings));
    }

    public function test_an_expired_waiver_stops_working_silently_and_loudly(): void
    {
        // Fail-closed: дата прошла — понижения нет, критичная находка сама и
        // есть тревога; никаких «waiver»-строк сверху.
        $sys = $this->healthy();
        $sys->files['/proc/mounts'] =
            "tmpfs /tmp tmpfs rw,nosuid,nodev,nr_inodes=1048576,uid=100000,gid=100000,inode64 0 0\n";
        $spec = GuardSpec::fromString(
            (string) file_get_contents(base_path('scripts/server_guards.conf'))
            ."\nGUARD_WAIVERS_EXPIRES=\"2026-01-01\"\n"
        );

        $lines = $this->lines((new ServerGuardsAuditor($spec, $sys, $this->templateRoot))->audit());

        $this->assertStringContainsString('[critical] tmpfs-cap:', $lines);
        $this->assertStringNotContainsString('waiver до', $lines);
        $this->assertStringNotContainsString('[info] tmpfs-cap:', $lines);
    }

    public function test_a_waiver_without_a_valid_expiry_fails_closed_and_says_so(): void
    {
        $sys = $this->healthy();
        $sys->files['/proc/mounts'] =
            "tmpfs /tmp tmpfs rw,nosuid,nodev,nr_inodes=1048576,uid=100000,gid=100000,inode64 0 0\n";
        $spec = GuardSpec::fromString(
            (string) file_get_contents(base_path('scripts/server_guards.conf'))
            ."\nGUARD_WAIVERS_EXPIRES=\"скоро\"\n"
        );

        $findings = (new ServerGuardsAuditor($spec, $sys, $this->templateRoot))->audit();

        $lines = $this->lines($findings);
        $this->assertStringContainsString('[critical] tmpfs-cap:', $lines);
        $this->assertStringContainsString('[warning] waiver: GUARD_WAIVERS задан', $lines);
        $this->assertTrue(ServerGuardsAuditor::hasBlocking($findings));
    }

    public function test_a_waiver_that_matches_nothing_asks_to_be_removed(): void
    {
        // Здоровый хост + активный waiver в conf: конфиг обязан сам сказать,
        // что стал мёртвым текстом, — info, не тревога.
        $findings = $this->auditor($this->healthy())->audit();

        $this->assertStringContainsString(
            '[info] waiver: waiver не понадобился: tmpfs-cap',
            $this->lines($findings),
        );
        $this->assertFalse(ServerGuardsAuditor::hasBlocking($findings));
    }

    public function test_a_failed_unit_is_named(): void
    {
        $sys = $this->healthy();
        $sys->failedUnits = ['samudra-health-monitor.service'];

        $findings = $this->auditor($sys)->audit();

        $this->assertTrue(ServerGuardsAuditor::hasBlocking($findings));
        $this->assertStringContainsString(
            '[warning] failed-units: samudra-health-monitor.service в состоянии failed',
            $this->lines($findings),
        );
    }

    public function test_an_allowlisted_failed_unit_is_silent(): void
    {
        $sys = $this->healthy();
        $sys->failedUnits = ['knowingly-broken.service'];
        $spec = GuardSpec::fromString(
            (string) file_get_contents(base_path('scripts/server_guards.conf'))
            ."\nFAILED_UNITS_ALLOWLIST=\"knowingly-broken.service\"\n"
        );

        $findings = (new ServerGuardsAuditor($spec, $sys, $this->templateRoot))->audit();

        $this->assertStringNotContainsString('failed-units', $this->lines($findings));
    }

    public function test_unreadable_failed_unit_list_is_not_a_finding(): void
    {
        $sys = $this->healthy();
        $sys->failedUnits = null; // не Linux / нет systemd

        $this->assertStringNotContainsString('failed-units', $this->lines($this->auditor($sys)->audit()));
    }

    public function test_a_stale_offsite_backup_is_critical_and_a_stale_local_one_is_not(): void
    {
        $old = time() - (((int) $this->spec->get('BACKUP_MAX_AGE_DAYS') + 3) * 86400);
        $plausible = ((int) $this->spec->get('BACKUP_MIN_ARCHIVE_MB') + 1) * 1024 ** 2;

        $sys = $this->healthy();
        $sys->backupDestinations = [
            ['disk' => 'local', 'reachable' => true, 'newestAt' => $old, 'newestBytes' => $plausible],
            ['disk' => 'yandex_disk', 'reachable' => true, 'newestAt' => time(), 'newestBytes' => $plausible],
        ];
        $lines = $this->lines($this->auditor($sys)->audit());
        $this->assertStringContainsString('[warning] backup-fresh: новейший архив на локальный диске local старше', $lines);

        $sys = $this->healthy();
        $sys->backupDestinations = [
            ['disk' => 'local', 'reachable' => true, 'newestAt' => time(), 'newestBytes' => $plausible],
            ['disk' => 'yandex_disk', 'reachable' => true, 'newestAt' => $old, 'newestBytes' => $plausible],
        ];
        $lines = $this->lines($this->auditor($sys)->audit());
        $this->assertStringContainsString('[critical] backup-fresh: новейший архив на off-site диске yandex_disk старше', $lines);
    }

    /**
     * Ровно замер 19-08-2026: два обрезка по 11.7 МиБ со свежей датой, и
     * backup:monitor называет назначение здоровым. Без порога размера эта
     * проверка была бы такой же зелёной лампочкой над пустым сейфом.
     */
    public function test_a_fresh_but_truncated_offsite_archive_is_critical(): void
    {
        $sys = $this->healthy();
        $sys->backupDestinations = [
            ['disk' => 'local', 'reachable' => true, 'newestAt' => time(), 'newestBytes' => 1425272697],
            ['disk' => 'yandex_disk', 'reachable' => true, 'newestAt' => time(), 'newestBytes' => 11757968],
        ];

        $findings = $this->auditor($sys)->audit();
        $lines = $this->lines($findings);

        $this->assertTrue(ServerGuardsAuditor::hasBlocking($findings));
        $this->assertStringContainsString('ОБРЕЗОК оборвавшейся загрузки', $lines);
        $this->assertStringContainsString('нет ни одного живого off-site назначения', $lines);
    }

    public function test_an_unreachable_offsite_destination_is_critical(): void
    {
        $sys = $this->healthy();
        $sys->backupDestinations = [
            ['disk' => 'local', 'reachable' => true, 'newestAt' => time(), 'newestBytes' => 1425272697],
            ['disk' => 'yandex_disk', 'reachable' => false, 'newestAt' => null, 'newestBytes' => null],
        ];

        $this->assertStringContainsString(
            '[critical] backup-fresh: off-site диск yandex_disk недостижим',
            $this->lines($this->auditor($sys)->audit()),
        );
    }

    public function test_backup_destinations_that_cannot_be_read_are_not_a_finding(): void
    {
        $sys = $this->healthy();
        $sys->backupDestinations = null; // Spatie не отвечает / пакет выключен

        $this->assertStringNotContainsString('backup-fresh', $this->lines($this->auditor($sys)->audit()));
    }
}
