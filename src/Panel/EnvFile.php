<?php

declare(strict_types=1);

namespace Larena\Env\Panel;

/**
 * The server's .env, read and changed in place under a lock. In place, because in a release the file is a link to the
 * site's shared .env whose owner and mode simai-env set. Read from the file rather than the process environment: the
 * file is what `panel setup-link` changes.
 */
final readonly class EnvFile
{
    public function __construct(private string $path)
    {
    }

    public function value(string $key): ?string
    {
        $bytes = @file_get_contents($this->path);
        if ($bytes === false || preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $bytes, $match) !== 1) {
            return null;
        }
        $value = trim($match[1], " \t\r\"'");

        return $value === '' ? null : $value;
    }

    /** Sets a key only when it has no value yet. False when the file cannot be written; the caller carries on without. */
    public function fill(string $key, string $value): bool
    {
        return $this->rewrite(static function (string $bytes) use ($key, $value): string {
            $pattern = '/^'.preg_quote($key, '/').'=[ \t]*(?:""|\'\')?[ \t]*$/m';
            if (preg_match($pattern, $bytes) === 1) {
                return (string) preg_replace_callback($pattern, static fn (): string => $key.'='.$value, $bytes, 1);
            }
            if (preg_match('/^'.preg_quote($key, '/').'=/m', $bytes) === 1) {
                return $bytes;
            }

            return ($bytes === '' || str_ends_with($bytes, "\n") ? $bytes : $bytes."\n").$key.'='.$value."\n";
        });
    }

    public function remove(string $key): bool
    {
        return $this->rewrite(static fn (string $bytes): string => (string) preg_replace('/^'.preg_quote($key, '/').'=.*(?:\R|\z)/m', '', $bytes));
    }

    /** @param callable(string): string $change */
    private function rewrite(callable $change): bool
    {
        // Never creates the file: a release keeps no .env of its own, and an empty one would hide the shared one.
        $handle = is_file($this->path) ? @fopen($this->path, 'c+') : false;
        if ($handle === false) {
            return false;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return false;
            }
            $bytes = (string) stream_get_contents($handle);
            $changed = $change($bytes);
            if ($changed === $bytes) {
                return true;
            }
            rewind($handle);

            return ftruncate($handle, 0) && fwrite($handle, $changed) === strlen($changed) && fflush($handle);
        } finally {
            fclose($handle);
        }
    }
}
