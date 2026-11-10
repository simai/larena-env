<?php

declare(strict_types=1);

namespace Larena\Env\Server;

use UnexpectedValueException;

/**
 * Where a site stands on its creation route (site:status, simai-site-status/1, simai-env 1.56.0), the same for every kind:
 * the route (new, restore, repo, installed or none), the step (an id of the route's steps, or done), the gateway command
 * of the next step (or open_url, or none), the addresses an open_url step opens and what the next step changes.
 */
final readonly class SiteStatus
{
    private const string SCHEMA = 'simai-site-status/1';

    /** @param list<string> $pending */
    public function __construct(
        public string $domain,
        public ?string $profile,
        public string $route,
        public string $step,
        public ?string $next,
        public ?string $restoreUrl,
        public ?string $installerUrl,
        public array $pending,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws UnexpectedValueException
     */
    public static function fromData(array $data): self
    {
        if (($data['schema'] ?? null) !== self::SCHEMA || !is_string($data['domain'] ?? null) || !is_string($data['route'] ?? null) || !is_string($data['step'] ?? null)) {
            throw new UnexpectedValueException('site_status_shape_invalid');
        }
        $url = static fn (string $name): ?string => is_string($data[$name] ?? null) && preg_match('#\Ahttps?://[^\s<>"]{1,500}\z#', $data[$name]) === 1 ? $data[$name] : null;
        $pending = [];
        foreach (is_array($data['pending'] ?? null) ? $data['pending'] : [] as $item) {
            if (is_string($item) && $item !== '') {
                $pending[] = $item;
            } elseif (is_array($item) && is_string($item['title'] ?? $item['id'] ?? null)) {
                $pending[] = (string) ($item['title'] ?? $item['id']);
            }
        }

        return new self($data['domain'], is_string($data['profile'] ?? null) ? $data['profile'] : null, $data['route'], $data['step'],
            is_string($data['next'] ?? null) && $data['next'] !== '' ? $data['next'] : null, $url('restore_url'), $url('installer_url'), $pending);
    }

    /** Whether the site is still on its way: a step to take, or a creation route just finished. */
    public function worthShowing(): bool
    {
        return $this->step !== 'done' || in_array($this->route, ['new', 'restore', 'repo'], true);
    }

    /** The address the next step opens in the browser, when it is one. */
    public function openUrl(): ?string
    {
        return $this->next === 'open_url' ? ($this->restoreUrl ?? $this->installerUrl) : null;
    }
}
