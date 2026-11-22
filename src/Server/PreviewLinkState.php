<?php

declare(strict_types=1);

namespace Larena\Env\Server;

/**
 * Whether a site's temporary link is open and until when (site:describe `preview_link` and site:preview-link-status,
 * simai-env 1.65.0): the address on the server's own IP and port, never the key; the link with the key is given once, by
 * the job that opened it.
 */
final readonly class PreviewLinkState
{
    /** The reasons the server names when it will not open a link (site:preview-link plan refusals). */
    public const array REFUSALS = ['site_missing', 'alias', 'suspended', 'no_address', 'no_nginx', 'no_port'];

    public function __construct(public string $address, public ?string $expiresAt)
    {
    }

    /** An address as the server gives it without the key: http://<IP>:<port>/ and nothing more. */
    public static function isAddress(string $address): bool
    {
        return preg_match('#\Ahttp://(?:[0-9]{1,3}(?:\.[0-9]{1,3}){3}|\[[0-9A-Fa-f:.]{2,45}\]):[0-9]{1,5}/\z#D', $address) === 1;
    }

    /** @param mixed $data `{url, expires_at, mode}` or null (closed) */
    public static function fromData(mixed $data): ?self
    {
        if (!is_array($data) || !is_string($data['url'] ?? null) || !self::isAddress($data['url'])) {
            return null;
        }
        $expires = $data['expires_at'] ?? null;

        return new self($data['url'], is_string($expires) && $expires !== '' ? $expires : null);
    }

    /**
     * What stops a link on this site, as far as the panel knows it without asking: an alias runs as the site it points to,
     * a paused site answers nobody. Every other reason (no address, no nginx, no free port) the server names in the plan.
     */
    public static function refusal(SiteDetails $site): ?string
    {
        if ($site->profile === 'alias') {
            return 'alias';
        }

        return $site->suspended ? 'suspended' : null;
    }
}
