<?php

declare(strict_types=1);

namespace Larena\Env\Gateway;

/**
 * A file the gateway handed over (file:download, simai-env 1.39.0): its answer, and where its checked bytes lie in the
 * local file (after the answer's line). Only an ok answer whose bytes matched its size has a name and an offset.
 */
final readonly class PanelDownload
{
    private function __construct(
        public PanelAnswer $answer,
        public ?string $name = null,
        public ?string $mime = null,
        public int $size = 0,
        public int $offset = 0,
    ) {
    }

    public static function of(PanelAnswer $answer): self
    {
        return new self($answer);
    }

    public static function file(PanelAnswer $answer, string $name, string $mime, int $size, int $offset): self
    {
        return new self($answer, $name, $mime, $size, $offset);
    }

    public function ok(): bool
    {
        return $this->name !== null;
    }

    /**
     * Reads what a gateway wrote for file:download: one JSON line of the `download` kind with the file's path, size and
     * time, then exactly that many bytes. A refusal is the line alone. Fewer bytes mean the file changed while it was
     * sent, more mean something else came: either is a failed answer, so a cut file is never served.
     */
    public static function read(string $requestId, string $path): self
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            return self::of(PanelAnswer::failed($requestId, PanelReason::ANSWER_INVALID));
        }
        try {
            $line = fgets($stream, 65_537);
            if ($line === false || !str_ends_with($line, "\n")) {
                return self::of(PanelAnswer::failed($requestId, PanelReason::ANSWER_INVALID));
            }
            $document = json_decode($line, true, 64);
            if (!is_array($document) || ($document['schema'] ?? null) !== 'simai-panel-answer/1'
                || (($document['request_id'] ?? null) !== $requestId && !(($document['request_id'] ?? null) === null && ($document['status'] ?? null) === 'refused'))) {
                return self::of(PanelAnswer::failed($requestId, PanelReason::ANSWER_INVALID));
            }
            $answer = PanelAnswer::fromDocument($requestId, $document);
            $offset = strlen($line);
            $total = (int) filesize($path);
            if ($answer->status !== PanelAnswerStatus::Ok) {
                return self::of($total === $offset ? $answer : PanelAnswer::failed($requestId, PanelReason::ANSWER_INVALID));
            }
            $data = $answer->data;
            $size = $data['size'] ?? null;
            $name = is_string($data['path'] ?? null) ? basename(str_replace('\\', '/', $data['path'])) : '';
            if ($answer->kind !== 'download' || !is_int($size) || $size < 0 || $name === '' || $name === '.') {
                return self::of(PanelAnswer::failed($requestId, PanelReason::ANSWER_SHAPE_INVALID));
            }
            if ($size > PanelCommands::DOWNLOAD_MAX_BYTES) {
                return self::of(PanelAnswer::failed($requestId, PanelReason::TOO_LARGE));
            }
            if ($total - $offset !== $size) {
                return self::of(PanelAnswer::failed($requestId, PanelReason::DOWNLOAD_MISMATCH));
            }

            return self::file($answer, $name, 'application/octet-stream', $size, $offset);
        } finally {
            fclose($stream);
        }
    }
}
