<?php

declare(strict_types=1);

namespace Larena\Env\Firewall;

/**
 * The server's firewall (Panel plan 5.2) and who reaches the panel (5.3), as simai-env 1.35.0 reads them
 * (`simai-firewall/1`): whether it is on, what it does by default, its rules, the addresses kept out (some until a time),
 * the one change waiting to be kept, and the panel's own allow list (null when no panel is installed there; `any` is
 * everyone).
 */
final readonly class FirewallStatus
{
    /**
     * @param list<FirewallRule> $rules
     * @param list<array{address: string, until: ?string}> $blocks
     * @param list<string>|'any'|null $panelAccess
     */
    public function __construct(
        public bool $active,
        public ?string $backend,
        public ?string $defaultIncoming,
        public array $rules,
        public array $blocks,
        public ?TimedChange $pending,
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
                $blocks[] = ['address' => $block['address'], 'until' => is_string($block['until'] ?? null) ? $block['until'] : null];
            }
        }
        // One change of a kind waits at a time; the server names the one waiting.
        $pending = is_array($data['pending_change'] ?? null) ? TimedChange::fromData($data['pending_change']) : null;
        $allow = is_array($data['panel_access'] ?? null) ? ($data['panel_access']['allow'] ?? null) : null;
        $panelAccess = $allow === 'any' ? 'any'
            : (is_array($allow) ? array_values(array_filter($allow, 'is_string')) : null);

        return new self(
            ($data['active'] ?? false) === true,
            is_string($data['backend'] ?? null) ? $data['backend'] : null,
            is_string($data['default_incoming'] ?? null) ? $data['default_incoming'] : null,
            $rules,
            $blocks,
            $pending !== null && $pending->pending() ? $pending : null,
            $panelAccess,
        );
    }

    /** The change of this kind waiting to be kept, if any (`firewall`, `panel_access`). */
    public function pendingOf(string $kind): ?TimedChange
    {
        return $this->pending?->kind === $kind ? $this->pending : null;
    }
}
