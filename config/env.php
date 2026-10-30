<?php

declare(strict_types=1);

return [
    'gateway' => [
        // recorded: replays the answers in recorded.path, only in recorded.allowed_environments.
        // ssh: asks simai-env's gateway. Anything else, or an incomplete target: the screen says it is not configured.
        'driver' => getenv('LARENA_ENV_GATEWAY') ?: 'recorded',
        'recorded' => [
            'path' => getenv('LARENA_ENV_RECORDED_PATH') ?: dirname(__DIR__).'/resources/recorded-answers',
            'allowed_environments' => ['local', 'testing'],
        ],
        'ssh' => [
            'host' => getenv('LARENA_ENV_SSH_HOST') ?: '',
            'user' => getenv('LARENA_ENV_SSH_USER') ?: 'simai-panel',
            'port' => (int) (getenv('LARENA_ENV_SSH_PORT') ?: 22),
            // A path, never the key itself. The key must have a forced command on the server (panel gate-enable --pubkey-file).
            'key_path' => getenv('LARENA_ENV_SSH_KEY') ?: '',
            'known_hosts_path' => getenv('LARENA_ENV_SSH_KNOWN_HOSTS') ?: '',
            'binary' => getenv('LARENA_ENV_SSH_BINARY') ?: '/usr/bin/ssh',
            'timeout_seconds' => (float) (getenv('LARENA_ENV_SSH_TIMEOUT') ?: 30),
        ],
    ],
    'admin' => [
        'enabled' => true,
        'prefix' => 'admin/server',
        'middleware' => ['web', 'larena-auth.entry', 'larena-auth.admin-required', 'larena-admin.locale', 'access:env.server.read'],
    ],
];
