<?php

declare(strict_types=1);

namespace Larena\Env\Files;

/**
 * A path inside a site as the panel takes it from a form or a link: from the site's root, `.` for the root itself.
 * Anything that is not such a path becomes null, and the screen says so; the gateway checks again on the host.
 */
final class FilePath
{
    private function __construct()
    {
    }

    public static function clean(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === '.' || $value === '/') {
            return '.';
        }
        if (!is_string($value) || strlen($value) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            return null;
        }
        $parts = [];
        foreach (explode('/', trim($value, '/')) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                return null;
            }
            $parts[] = $part;
        }

        return $parts === [] ? '.' : implode('/', $parts);
    }

    /** The path of a name inside a folder. */
    public static function join(string $folder, string $name): string
    {
        return $folder === '.' ? $name : $folder.'/'.$name;
    }

    /**
     * The name a copy is offered beside its original: «index-copy.php», «archive-copy.tar.gz», «assets-copy» for a folder
     * or a name without an extension, «.env-copy» for a dot file.
     */
    public static function copyName(string $name): string
    {
        foreach (['.tar.gz', '.sql.gz', '.sql.zip'] as $double) {
            if (strlen($name) > strlen($double) && str_ends_with(strtolower($name), $double)) {
                return substr($name, 0, -strlen($double)).'-copy'.substr($name, -strlen($double));
            }
        }
        $dot = strrpos($name, '.');

        return $dot === false || $dot === 0 ? $name.'-copy' : substr($name, 0, $dot).'-copy'.substr($name, $dot);
    }

    public static function parent(string $path): string
    {
        $parent = dirname($path);

        return $parent === '' || $parent === '/' ? '.' : $parent;
    }

    /**
     * The folders above a path, for the breadcrumb: each with its name and its own path.
     *
     * @return list<array{name: string, path: string}>
     */
    public static function crumbs(string $path): array
    {
        if ($path === '.') {
            return [];
        }
        $crumbs = [];
        $walked = '.';
        foreach (explode('/', $path) as $part) {
            $walked = self::join($walked, $part);
            $crumbs[] = ['name' => $part, 'path' => $walked];
        }

        return $crumbs;
    }
}
