<?php

declare(strict_types=1);

namespace Larena\Env\Gateway;

/**
 * A file the gateway handed over: its answer, and where its checked bytes lie in the local file (after the answer's
 * line). Only an ok answer whose bytes matched its size and sha256 has a name and an offset.
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
     * Reads what a gateway wrote for fs:download: one JSON line, then exactly the bytes it names. A refusal is the line
     * alone. Anything that does not add up is a failed answer, so a cut or altered file is never served.
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
            $sha256 = $data['sha256'] ?? null;
            $name = $data['name'] ?? null;
            $mime = $data['mime'] ?? 'application/octet-stream';
            if ($answer->kind !== 'result' || !is_int($size) || $size < 0 || !is_string($sha256) || preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1
                || !is_string($name) || $name === '' || !is_string($mime)) {
                return self::of(PanelAnswer::failed($requestId, PanelReason::ANSWER_SHAPE_INVALID));
            }
            if ($size > PanelCommands::DOWNLOAD_MAX_BYTES) {
                return self::of(PanelAnswer::failed($requestId, PanelReason::TOO_LARGE));
            }
            // A short read, extra bytes or other bytes: the file is not what the server said it sent.
            if ($total - $offset !== $size) {
                return self::of(PanelAnswer::failed($requestId, PanelReason::DOWNLOAD_MISMATCH));
            }
            $hash = hash_init('sha256');
            while (!feof($stream)) {
                $chunk = fread($stream, 1_048_576);
                if ($chunk === false) {
                    return self::of(PanelAnswer::failed($requestId, PanelReason::DOWNLOAD_MISMATCH));
                }
                hash_update($hash, $chunk);
            }
            if (!hash_equals($sha256, hash_final($hash))) {
                return self::of(PanelAnswer::failed($requestId, PanelReason::DOWNLOAD_MISMATCH));
            }

            return self::file($answer, basename(str_replace('\\', '/', $name)), $mime, $size, $offset);
        } finally {
            fclose($stream);
        }
    }
}
