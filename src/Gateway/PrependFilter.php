<?php

declare(strict_types=1);

namespace Larena\Env\Gateway;

use php_user_filter;

/**
 * A read filter that puts its parameter before the stream's first byte, so the request line and an attachment reach
 * the gateway as one stream without copying the file. An empty file still yields the line.
 *
 * @internal
 */
final class PrependFilter extends php_user_filter
{
    public const string NAME = 'larena-env.prepend';

    private bool $sent = false;

    public static function register(): void
    {
        if (!in_array(self::NAME, stream_get_filters(), true)) {
            stream_filter_register(self::NAME, self::class);
        }
    }

    /**
     * @param resource $in
     * @param resource $out
     * @param int $consumed
     */
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        if (!$this->sent) {
            $this->sent = true;
            $prefix = is_string($this->params) ? $this->params : '';
            if ($prefix !== '') {
                $bucket = stream_bucket_new($this->stream, $prefix);
                stream_bucket_append($out, $bucket);
            }
        }
        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += (int) $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
