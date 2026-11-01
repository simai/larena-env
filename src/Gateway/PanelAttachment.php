<?php

declare(strict_types=1);

namespace Larena\Env\Gateway;

/**
 * A file the panel sends after the request line (Panel plan 6.1, 6.3): the request names its sha256 and size, and the
 * gateway refuses anything else (attachment_mismatch). The bytes are read from disk as they are sent, never held whole.
 */
final readonly class PanelAttachment
{
    private function __construct(public string $path, public string $sha256, public int $size)
    {
    }

    /** @throws PanelRefused when the file cannot be read */
    public static function fromFile(string $path): self
    {
        $size = is_file($path) && is_readable($path) ? filesize($path) : false;
        $sha256 = $size === false ? false : hash_file('sha256', $path);
        if ($size === false || !is_string($sha256)) {
            throw new PanelRefused(PanelReason::BAD_REQUEST);
        }

        return new self($path, $sha256, $size);
    }

    /** @return array{sha256: string, size: int} */
    public function toDocument(): array
    {
        return ['sha256' => $this->sha256, 'size' => $this->size];
    }

    /**
     * The gateway's stdin: the request line, then the file's bytes, as one stream read in chunks.
     *
     * @return resource
     *
     * @throws PanelRefused
     */
    public function streamAfter(string $line): mixed
    {
        $stream = @fopen($this->path, 'rb');
        if ($stream === false) {
            throw new PanelRefused(PanelReason::BAD_REQUEST);
        }
        PrependFilter::register();
        if (@stream_filter_append($stream, PrependFilter::NAME, STREAM_FILTER_READ, $line) === false) {
            fclose($stream);
            throw new PanelRefused(PanelReason::BAD_REQUEST);
        }

        return $stream;
    }
}
