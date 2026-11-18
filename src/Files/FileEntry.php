<?php

declare(strict_types=1);

namespace Larena\Env\Files;

use UnexpectedValueException;

/** One entry of a site's folder, as file:list answers it (simai-files/1, simai-env 1.39.0). */
final readonly class FileEntry
{
    public const array TYPES = ['dir', 'file', 'link', 'other'];

    /** @param ?string $target where a link points; it is shown, never followed */
    public function __construct(
        public string $name,
        public string $type,
        public ?int $size,
        public ?string $mode,
        public ?string $mtime,
        public ?string $target = null,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws UnexpectedValueException
     */
    public static function fromData(array $data): self
    {
        $name = $data['name'] ?? null;
        $type = $data['type'] ?? null;
        if (!is_string($name) || $name === '' || $name === '.' || $name === '..' || str_contains($name, '/') || !in_array($type, self::TYPES, true)) {
            throw new UnexpectedValueException('file_entry_invalid');
        }

        return new self($name, $type, is_int($data['size'] ?? null) ? $data['size'] : null,
            is_string($data['mode'] ?? null) && preg_match('/\A[0-7]{3,4}\z/D', $data['mode']) === 1 ? $data['mode'] : null,
            self::moment($data['mtime'] ?? null), is_string($data['target'] ?? null) && $data['target'] !== '' ? $data['target'] : null);
    }

    /** The server's time as seconds since 1970; a written time is kept as it came. */
    public static function moment(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return gmdate('Y-m-d\TH:i:s\Z', (int) $value);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function isDir(): bool
    {
        return $this->type === 'dir';
    }

    /**
     * The pictures the panel shows in place (PX-96): raster images only, by their name; an SVG may hold a script and is
     * downloaded instead. The preview reads the bytes and checks their signature before a browser sees them.
     */
    public const array IMAGES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];

    public function isImage(): bool
    {
        return $this->type === 'file' && self::imageType($this->name) !== null;
    }

    /** The media type of a picture shown in place, by its name; null for anything else. */
    public static function imageType(string $name): ?string
    {
        return self::IMAGES[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? null;
    }

    /**
     * A file that holds passwords or keys (PX-92): .env and its copies, the database settings of WordPress and 1C-Bitrix,
     * private keys and password files. The list marks it «секреты», and its download asks first.
     */
    public function isSecret(): bool
    {
        return $this->type === 'file' && self::secret($this->name);
    }

    public static function secret(string $name): bool
    {
        $lower = strtolower($name);
        if (in_array($lower, ['.env.example', '.env.sample', '.env.dist'], true)) {
            return false;
        }

        return $lower === '.env' || str_starts_with($lower, '.env.') || in_array($lower, ['wp-config.php', '.settings.php', '.settings_extra.php',
            'dbconn.php', '.htpasswd', 'auth.json', 'id_rsa', 'id_ed25519', 'id_ecdsa'], true) || preg_match('/\.(?:pem|key|p12|pfx)\z/D', $lower) === 1;
    }

    /** An archive file:extract unpacks, by its name. */
    public function isArchive(): bool
    {
        return $this->type === 'file' && preg_match('/\.(?:zip|tar\.gz|tgz|tar)\z/iD', $this->name) === 1;
    }
}
