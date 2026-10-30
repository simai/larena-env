<?php

declare(strict_types=1);

namespace Larena\Env\Gateway;

/** Who asks, as the gateway logs and checks it. `owner` narrows the answer to that owner's sites. */
final readonly class PanelActor
{
    private const string OWNER_PATTERN = '/\A[a-z][a-z0-9_-]{0,31}\z/D';

    public function __construct(
        public string $user,
        public ?string $owner = null,
        public ?string $impersonatedBy = null,
    ) {
        if (!self::printable($user)
            || ($owner !== null && preg_match(self::OWNER_PATTERN, $owner) !== 1)
            || ($impersonatedBy !== null && !self::printable($impersonatedBy))) {
            throw new PanelRefused(PanelReason::BAD_ACTOR);
        }
    }

    /** @return array{user: string, owner: ?string, impersonated_by: ?string} */
    public function toDocument(): array
    {
        return ['user' => $this->user, 'owner' => $this->owner, 'impersonated_by' => $this->impersonatedBy];
    }

    /** The gateway's rule: 1 to 254 printable characters; control and separator characters other than a space are refused. */
    private static function printable(string $value): bool
    {
        return mb_check_encoding($value, 'UTF-8')
            && $value !== ''
            && mb_strlen($value, 'UTF-8') <= 254
            && preg_match('/[\p{C}\p{Zl}\p{Zp}]|[^\P{Zs} ]/u', $value) === 0;
    }
}
