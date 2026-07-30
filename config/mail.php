<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send any email
    | messages sent by your application. Alternative mailers may be setup
    | and used as needed; however, this mailer will be used by default.
    |
    */

    'default' => env('MAIL_MAILER', 'smtp'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers to be used while
    | sending an e-mail. You will specify which one you are using for your
    | mailers below. You are free to add additional mailers as required.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "log", "array", "failover", "roundrobin"
    |
    */

    'mailers' => [
        'smtp' => [
            'transport' => 'smtp',
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', 'smtp.mailgun.org'),
            'port' => env('MAIL_PORT', 587),
            'encryption' => env('MAIL_ENCRYPTION', 'tls'),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN'),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => null,
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'mailgun' => [
            'transport' => 'mailgun',
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all e-mails sent by your application to be sent from
    | the same address. Here, you may specify a name and address that is
    | used globally for all e-mails that are sent by your application.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', 'Example'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-minute send throttle (H1449 A2)
    |--------------------------------------------------------------------------
    |
    | A mailbox-relay provider (mail.ru / Yandex 360, D6 ruling — see
    | docs/mail-esp.md) rate-limits and can throttle/suspend on bulk send,
    | unlike a dedicated ESP. App\Listeners\Email\EnforceMailSendingGuards
    | rejects (does not queue/retry) any send once this many messages have
    | gone out in the current rolling minute — a safety valve for both
    | transactional mail and the campaign engine (H1449 W1b).
    |
    */

    'throttle_per_minute' => (int) env('MAIL_THROTTLE_PER_MINUTE', 30),

    /*
    |--------------------------------------------------------------------------
    | Bounce mailbox scan (H1449 A3)
    |--------------------------------------------------------------------------
    |
    | D11 default: mail.ru/Yandex 360 bounce-webhook support could not be
    | confirmed without a live spike (S1 in docs/VERIFICATION_SYSTEMA_ANTON_OPS_GAPS.md),
    | so bounce capture is a scheduled IMAP scan of the sending mailbox
    | instead (`php artisan mail:scan-bounces`, App\Console\Commands\ScanBounces).
    | Uses PHP's built-in ext-imap (no new composer dependency) — if the
    | extension isn't loaded, the command logs and no-ops, so it is safe to
    | schedule before the extension is actually enabled on the server.
    | Wiring the real mailbox (host/credentials) is an activation-time step —
    | see DEPLOY_QUEUE.md.
    |
    */

    'bounce_scan' => [
        'enabled' => (bool) env('MAIL_BOUNCE_SCAN_ENABLED', false),
        'host' => env('MAIL_BOUNCE_IMAP_HOST'), // e.g. {imap.mail.ru:993/imap/ssl}INBOX
        'username' => env('MAIL_BOUNCE_IMAP_USERNAME'),
        'password' => env('MAIL_BOUNCE_IMAP_PASSWORD'),
        // H1916: unreachable IMAP host used to hang mail:scan-bounces
        // indefinitely (PHP/network decide the wait, not us).
        'open_timeout_seconds' => (int) env('MAIL_BOUNCE_IMAP_OPEN_TIMEOUT', 15),
        'read_timeout_seconds' => (int) env('MAIL_BOUNCE_IMAP_READ_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Markdown Mail Settings
    |--------------------------------------------------------------------------
    |
    | If you are using Markdown based email rendering, you may configure your
    | theme and component paths here, allowing you to customize the design
    | of the emails. Or, you may simply stick with the Laravel defaults!
    |
    */

    'markdown' => [
        'theme' => 'default',

        'paths' => [
            resource_path('views/vendor/mail'),
        ],
    ],

];
