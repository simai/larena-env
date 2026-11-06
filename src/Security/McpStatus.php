<?php

declare(strict_types=1);

namespace Larena\Env\Security;

/**
 * Access for AI assistants over MCP (mcp:status, simai-mcp-status/1, simai-env 1.49.0): whether it is on, the account the
 * assistants connect as, the keys with their role (the whole server or one owner's sites) and fingerprint, and the
 * ready lines for Claude Code, Codex and any other client's JSON.
 */
final readonly class McpStatus
{
    public const string SCHEMA = 'simai-mcp-status/1';

    /**
     * @param list<array{role: string, owner: ?string, fingerprint: string}> $keys
     * @param array<string, string> $clients the line for each client: claude_code, codex, json
     */
    public function __construct(
        public bool $enabled,
        public ?string $account,
        public array $keys,
        public ?string $connect,
        public array $clients,
    ) {
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): ?self
    {
        if (($data['schema'] ?? null) !== self::SCHEMA || !is_bool($data['enabled'] ?? null)) {
            return null;
        }
        $keys = [];
        foreach (is_array($data['keys'] ?? null) ? $data['keys'] : [] as $key) {
            if (!is_array($key) || !is_string($key['fingerprint'] ?? null) || preg_match('/\ASHA256:[A-Za-z0-9+\/]{8,64}\z/D', $key['fingerprint']) !== 1) {
                continue;
            }
            $owner = is_string($key['owner'] ?? null) && preg_match('/\A[a-z][a-z0-9_-]{0,31}\z/D', $key['owner']) === 1 ? $key['owner'] : null;
            $keys[] = ['role' => ($key['role'] ?? null) === 'owner' && $owner !== null ? 'owner' : 'administrator', 'owner' => $owner, 'fingerprint' => $key['fingerprint']];
        }
        $clients = [];
        $given = is_array($data['clients'] ?? null) ? $data['clients'] : [];
        foreach (['claude_code', 'codex'] as $client) {
            if (is_string($given[$client] ?? null) && $given[$client] !== '' && strlen($given[$client]) <= 500) {
                $clients[$client] = $given[$client];
            }
        }
        if (is_array($given['json'] ?? null)) {
            $clients['json'] = (string) json_encode($given['json'], JSON_UNESCAPED_SLASHES);
        }

        return new self(
            enabled: $data['enabled'],
            account: is_string($data['account'] ?? null) && $data['account'] !== '' ? $data['account'] : null,
            keys: $keys,
            connect: is_string($data['connect'] ?? null) && $data['connect'] !== '' ? $data['connect'] : null,
            clients: $clients,
        );
    }
}
