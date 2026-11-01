<?php

declare(strict_types=1);

namespace Larena\Env\Firewall;

/**
 * The server's firewall (Panel plan 5.2) and who reaches the panel (5.3): whether it is on, what it does by default,
 * its rules, the addresses kept out (some until a time), the changes waiting to be kept, and the panel's own allow
 * list (null when the server does not say; `any` is everyone).
 */
final readonly class FirewallStatus
{
    /**
     * @param list<FirewallRule> $rules
     * @param list<array{address: string, expires_at: ?string}> $blocks
     * @param list<TimedChange> $pending
     * @param list<string>|'any'|null $panelAccess
     */
    public function __construct(
        public bool $active,
        public ?string $backend,
        public ?string $defaultIncoming,
        public array $rules,
        public array $blocks,
        public array $pending,
        public array|string|null $panelAccess,
    ) {
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): self
    {
        $rules = [];
        foreach (is_array($data['rules'] ?? null) ? $data['rules'] : [] as $rule) {
            $made = is_array($rule) ? FirewallRule::fromData($rule) : null;
            if ($made !== null) {
                $rules[] = $made;
            }
        }
        $blocks = [];
        foreach (is_array($data['blocks'] ?? null) ? $data['blocks'] : [] as $block) {
            if (is_array($block) && is_string($block['address'] ?? null)) {
                $blocks[] = ['address' => $block['address'], 'expires_at' => is_string($block['expires_at'] ?? null) ? $block['expires_at'] : null];
            }
        }
        $pending = [];
        foreach (is_array($data['pending'] ?? null) ? $data['pending'] : [] as $change) {
            $made = is_array($change) ? TimedChange::fromData($change) : null;
            if ($made !== null && $made->pending()) {
                $pending[] = $made;
            }
        }
        $allow = is_array($data['panel_access'] ?? null) ? ($data['panel_access']['allow'] ?? null) : null;
        $panelAccess = $allow === 'any' ? 'any'
            : (is_array($allow) ? array_values(array_filter($allow, 'is_string')) : null);

        return new self(
            ($data['active'] ?? false) === true,
            is_string($data['backend'] ?? null) ? $data['backend'] : null,
            is_string($data['default_incoming'] ?? null) ? $data['default_incoming'] : null,
            $rules,
            $blocks,
            $pending,
            $panelAccess,
        );
    }

    /** The change of this kind waiting to be kept, if any (`firewall`, `panel_access`). */
    public function pendingOf(string $kind): ?TimedChange
    {
        foreach ($this->pending as $change) {
            if ($change->kind === $kind) {
                return $change;
            }
        }

        return null;
    }
}
