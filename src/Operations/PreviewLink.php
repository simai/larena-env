<?php

declare(strict_types=1);

namespace Larena\Env\Operations;

/**
 * A site's temporary link before its domain points here (site:preview-link, simai-env 1.65.0):
 * http://<server IP>:<port>/.simai-preview/<key> on a port of the server's own address, closing by itself. The server
 * gives the link with its key in one answer only (the first job:status after the job ended, ONCE_RESULT preview_url) and
 * never in the job's log; the panel shows it on that screen and keeps it nowhere: not in its cache, its session, its logs,
 * its history or an address it builds. The address without the key, the deadline and whether an open link was renewed
 * come with it.
 */
final readonly class PreviewLink
{
    /** http://<IPv4 or [IPv6]>:<port>/.simai-preview/<hex key>, nothing else: another address is never shown as the site's link. */
    public const string URL = '#\Ahttp://(?:[0-9]{1,3}(?:\.[0-9]{1,3}){3}|\[[0-9A-Fa-f:.]{2,45}\]):[0-9]{1,5}/\.simai-preview/[a-f0-9]{16,128}\z#D';

    public function __construct(
        #[\SensitiveParameter] public string $url,
        public ?string $address,
        public ?string $expiresAt,
        public bool $renewed,
    ) {
    }

    /** @param array<array-key, mixed> $result */
    public static function fromData(array $result): ?self
    {
        $url = $result['preview_url'] ?? null;
        if (!is_string($url) || preg_match(self::URL, $url) !== 1) {
            return null;
        }
        $address = $result['address'] ?? null;
        $expires = $result['expires_at'] ?? null;

        return new self($url, is_string($address) && \Larena\Env\Server\PreviewLinkState::isAddress($address) ? $address : null,
            is_string($expires) && $expires !== '' ? $expires : null, ($result['renewed'] ?? false) === true);
    }

    /** Never printed by accident (var_dump, a log of the object). @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['url' => '(shown once)', 'address' => $this->address, 'expiresAt' => $this->expiresAt, 'renewed' => $this->renewed];
    }
}
