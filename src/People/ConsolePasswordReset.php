<?php

declare(strict_types=1);

namespace Larena\Env\People;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Larena\Auth\Runtime\PasswordPolicy;

/**
 * A panel administrator's new password set from the server's console (`larena:panel-reset-password`), for when the
 * panel has no mail and nobody else to ask. The password is checked by the sign-in package's one rule, kept only as its
 * hash, every session of the person ends, and «История» says «пароль сброшен с консоли» — never the password. A blocked
 * or removed person is refused: unblocking is a decision for the panel, not the console.
 */
final readonly class ConsolePasswordReset
{
    public const string AUDIT_TYPE = 'env.person.password_reset_console';

    /** @param (Closure(int): void)|null $endSessions ends the person's sessions inside the same transaction */
    public function __construct(
        private ConnectionInterface $connection,
        private ?Closure $endSessions = null,
        private ?PanelAudit $audit = null,
    ) {
    }

    /** @return string 'reset', or why not: not_found, blocked, too_short, too_long, too_weak */
    public function reset(string $email, #[\SensitiveParameter] string $password): string
    {
        $violation = class_exists(PasswordPolicy::class) ? PasswordPolicy::violation($password) : (strlen($password) < 12 ? 'too_short' : null);
        if ($violation !== null) {
            return $violation;
        }
        $identity = $this->connection->table('larena_admin_identities')->where('email', mb_strtolower(trim($email)))->first();
        if (!is_object($identity)) {
            return 'not_found';
        }
        if (!in_array((string) ($identity->status ?? ''), ['active', 'rotation_required'], true) || ($identity->disabled_at ?? null) !== null) {
            return 'blocked';
        }
        $id = (int) $identity->id;
        $this->connection->transaction(function () use ($id, $password): void {
            $this->connection->table('larena_admin_identities')->where('id', $id)->update([
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'status' => 'active',
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
            if ($this->endSessions !== null) {
                ($this->endSessions)($id);
            }
        });
        $this->audit?->record(self::AUDIT_TYPE, 'console', 'user:admin_identity:'.$id);

        return 'reset';
    }
}
