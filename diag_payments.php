<?php

// Диагностика promise-grant mismatch: платежи 14577/14066.
// Запуск: /opt/homebrew/bin/php diag_payments.php  (из корня проекта)

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

function out(string $title, $data): void
{
    echo "=== {$title} ===\n";
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE), "\n\n";
}

$paymentIds = [14577, 14066];

foreach ($paymentIds as $id) {
    $p = DB::table('payments')->where('id', $id)->first();
    if (! $p) {
        out("payment {$id}", 'NOT FOUND');
        continue;
    }
    out("payment {$id}", (array) $p);

    $u = DB::table('users')->where('id', $p->user_id)->first(['id', 'name', 'email', 'created_at']);
    out("user of payment {$id}", $u ? (array) $u : 'NOT FOUND');

    $cu = DB::table('course_user')->where('user_id', $p->user_id)->get();
    out("course_user rows for user {$p->user_id}", $cu);

    $gm = DB::table('group_user')->where('user_id', $p->user_id)->get();
    out("group_user rows for user {$p->user_id}", $gm);

    if ($p->course_id) {
        $groups = DB::table('groups')
            ->join('course_group', 'course_group.group_id', '=', 'groups.id')
            ->where('course_group.course_id', $p->course_id)
            ->get(['groups.id', 'groups.title']);
        out("groups of course {$p->course_id}", $groups);
    }

    // Аудит: как статус становился paid и через какой канал.
    try {
        $audit = DB::table('payment_audits')->where('payment_id', $id)->orderBy('id')->get();
        out("payment_audits for {$id}", $audit);
    } catch (Throwable $e) {
        out("payment_audits for {$id}", 'table error: '.$e->getMessage());
    }
}

// Свип класса: paid-платежи без course_user (по курсу платежа).
$sweep = DB::table('payments as p')
    ->whereIn('p.status', ['paid', 'success'])
    ->whereNotIn('p.tariff', ['deposit', 'trial', 'Расход', 'salary_payout'])
    ->whereNotNull('p.course_id')
    ->whereNull('p.refund_of_payment_id')
    ->whereNotExists(function ($q) {
        $q->select(DB::raw(1))
            ->from('course_user as cu')
            ->whereColumn('cu.user_id', 'p.user_id')
            ->whereColumn('cu.course_id', 'p.course_id');
    })
    ->orderBy('p.id')
    ->get(['p.id', 'p.user_id', 'p.course_id', 'p.tariff', 'p.status', 'p.amount', 'p.first_paid_at', 'p.created_at']);

out('SWEEP: paid payments without course_user (count='.count($sweep).')', $sweep);

// grantAccess-лог вокруг этих платежей.
$logPath = storage_path('logs/laravel.log');
if (is_file($logPath)) {
    $hits = [];
    foreach ($paymentIds as $id) {
        foreach (["payment #{$id}", "payment {$id}"] as $needle) {
            foreach (['grantAccess', 'enrollInCourse'] as $tag) {
                $lines = grepFile($logPath, $tag.'.*'.preg_quote($needle, '/'));
                $hits = array_merge($hits, $lines);
            }
        }
    }
    out('log hits (grantAccess/enrollInCourse for these payments)', array_slice(array_unique($hits), -20));
}

function grepFile(string $path, string $regex): array
{
    $out = [];
    $fh = fopen($path, 'r');
    if (! $fh) {
        return $out;
    }
    while (($line = fgets($fh)) !== false) {
        if (preg_match('/'.$regex.'/i', $line)) {
            $out[] = trim($line);
        }
    }
    fclose($fh);

    return $out;
}
