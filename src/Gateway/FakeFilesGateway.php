<?php

declare(strict_types=1);

namespace Larena\Env\Gateway;

use Larena\Env\Contracts\PanelFileGateway;
use Larena\Env\Contracts\PanelGateway;
use Throwable;

/**
 * A stand-in for the server's file commands (simai-env 1.39.0–1.40.0; the trash of 1.57.0; a replaced file kept in the trash and
 * the last backup before a site or its database goes, 1.64.0; a site's temporary link and a backup's files put back into an
 * empty site, 1.65.0) on recorded answers, for tests and the install
 * check only: the recordings hold one folder and a few plans, never the bytes of a download or an upload. It answers
 * file:* the way the gateway does, on a local folder per site; everything else goes to the recorded answers it wraps. Paths are checked as the gateway
 * checks them: nothing outside a site's folder, no `..`, no link that leaves it.
 */
final readonly class FakeFilesGateway implements PanelGateway, PanelFileGateway
{
    /** The commands this stand-in answers, added to the host description's own list. */
    public const array COMMANDS = ['file:list', 'file:stat', 'file:read', 'file:preview', 'file:download', 'file:write', 'file:upload', 'file:mkdir', 'file:rename',
        'file:remove', 'file:archive', 'file:extract', 'file:trash-list', 'file:trash-restore', 'file:trash-empty', 'backup:data-list'];

    /**
     * A site's temporary link (simai-env 1.65.0), answered as the gateway does: a plan with the address and whether it renews,
     * a job whose result has the link with its key once (ONCE_RESULT), the status and site:describe without the key.
     */
    public const array PREVIEW = ['site:preview-link', 'site:preview-link-close', 'site:preview-link-status'];

    /** The values a job's result gives once, as the gateway's ONCE_RESULT: the first job:status after the job, never again. */
    private const array ONCE = ['admin_password', 'browser_url', 'db_password', 'preview_url'];

    /** The stand-in server's address and the first port of its temporary links. */
    public const string PREVIEW_IP = '203.0.113.10';
    public const int PREVIEW_PORT = 7700;

    /** What the stand-in backs up before passing on (simai-env 1.64.0): a last copy kept seven days, or nothing removed. */
    private const array BACKED_UP = ['site:remove' => 'site-remove', 'site:db-drop' => 'db-drop'];

    /** How long the stand-in keeps a removed entry, as simai-env 1.61.0 does. */
    public const int TRASH_DAYS = 7;

    private const array DESCRIPTIONS = [
        'file:write' => 'Replace or create a text file of a site', 'file:upload' => 'Put a file into a site', 'file:mkdir' => 'Make a directory',
        'file:rename' => 'Rename or move', 'file:remove' => 'Remove a file, a link or a directory with its contents',
        'file:archive' => 'Pack a file or directory into a new archive', 'file:extract' => 'Unpack an archive into a directory',
        'file:trash-restore' => 'Put a trash entry back where it was', 'file:trash-empty' => 'Delete trash entries for good',
    ];

    public function __construct(private PanelGateway $inner, private string $root)
    {
    }

    public function source(): string
    {
        return $this->inner->source();
    }

    public function ask(PanelRequest $request): PanelAnswer
    {
        $command = $request->command;
        if (in_array($command, ['job:status', 'job:log'], true) && is_file($this->jobFile($request->args['job_id'] ?? ''))) {
            return $this->job($request);
        }
        if (isset(self::BACKED_UP[$command]) && $request->confirm) {
            $kept = $this->keepLast($request);
            if ($kept !== null) {
                return $kept;
            }
        }
        if (in_array($command, self::PREVIEW, true)) {
            return $this->previewLink($request);
        }
        if ($command === 'backup:data-restore' && ($request->args['files-live'] ?? 'no') === 'yes' && ($request->args['files'] ?? 'yes') !== 'no') {
            return $this->restoreLive($request);
        }
        if ($command === 'site:describe') {
            return $this->described($request, $this->inner->ask($request));
        }
        if ($command === 'backup:data-list') {
            return $request->actor->owner === null ? $this->ok($request, 'result', $this->held($request->args['domain'] ?? null))
                : PanelAnswer::refused($request->requestId, 'owner_admin_only');
        }
        if (!in_array($command, self::COMMANDS, true)) {
            $answer = $this->inner->ask($request);

            return $command === 'self:describe' ? $this->listed($request, $answer) : $answer;
        }
        if ($command === 'file:download') {
            return PanelAnswer::refused($request->requestId, PanelReason::NOT_ALLOWED);
        }
        $domain = $request->args['domain'];
        $scope = $this->scope($request, $domain);
        if ($scope !== null) {
            return $scope;
        }
        try {
            if (PanelCommands::kind($command) === PanelCommands::READ) {
                return $this->ok($request, 'result', $this->read($command, $domain, $request->args));
            }
            $this->check($command, $domain, $request);
            if (!$request->confirm) {
                // As the gateway, a plan shows a text's size, never the text.
                $shown = isset($request->args['content']) ? ['content' => '('.strlen((string) $request->args['content']).' bytes)'] + $request->args : $request->args;

                // What emptying deletes for good (1.64.0): the entries, their bytes and ids; with an id that one only.
                $trash = $command === 'file:trash-empty' ? ['trash' => $this->purged($domain, $request->args['id'] ?? null)] : [];

                return $this->ok($request, 'plan', ['command' => $command, 'args' => $shown, 'target' => $domain,
                    'description' => self::DESCRIPTIONS[$command] ?? $command, 'effects' => 'write', 'lock' => $domain, 'applied' => false] + $trash);
            }
            $lines = $this->act($command, $domain, $request);
        } catch (FakeRefusal $refusal) {
            return PanelAnswer::refused($request->requestId, $refusal->reason);
        }

        return $this->started($request, $lines);
    }

    public function download(PanelRequest $request, string $into): PanelDownload
    {
        $domain = $request->args['domain'] ?? '';
        $scope = $this->scope($request, $domain);
        try {
            if ($scope !== null) {
                throw new FakeRefusal($scope->reason ?? PanelReason::NOT_ALLOWED);
            }
            $path = $this->path($domain, $request->args['path'] ?? '');
            if (!file_exists($path)) {
                throw new FakeRefusal('not_found');
            }
            if (!is_file($path) || is_link($path)) {
                throw new FakeRefusal('not_a_file');
            }
            $header = json_encode(['schema' => 'simai-panel-answer/1', 'request_id' => $request->requestId, 'status' => 'ok', 'kind' => 'download',
                'data' => ['path' => $request->args['path'], 'size' => (int) filesize($path), 'mtime' => (int) filemtime($path)]],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
            file_put_contents($into, $header);
            file_put_contents($into, (string) file_get_contents($path), FILE_APPEND);
        } catch (FakeRefusal $refusal) {
            file_put_contents($into, json_encode(['schema' => 'simai-panel-answer/1', 'request_id' => $request->requestId, 'status' => 'refused',
                'reason' => $refusal->reason], JSON_THROW_ON_ERROR)."\n");
        }

        return PanelDownload::read($request->requestId, $into);
    }

    /** The host description lists the file commands too: the screens offer what a server lists. */
    private function listed(PanelRequest $request, PanelAnswer $answer): PanelAnswer
    {
        if ($answer->status !== PanelAnswerStatus::Ok || !is_array($answer->data['gate']['commands'] ?? null)) {
            return $answer;
        }
        $data = $answer->data;
        $data['gate']['commands'] = array_values(array_unique([...$data['gate']['commands'], ...$this->offered($request)]));

        return PanelAnswer::fromDocument($request->requestId, ['status' => 'ok', 'kind' => 'result', 'data' => $data,
            'command_status' => $answer->commandStatus, 'operation_id' => $answer->operationId]);
    }

    /** @return list<string> */
    private function offered(PanelRequest $request): array
    {
        return [...self::COMMANDS, ...self::PREVIEW];
    }

    /**
     * site:preview-link, its close and its status, as simai-env 1.65.0 answers them. An owner asks for their own sites
     * only; an alias or a paused site is refused in the plan, and a file `preview-refuse` in the stand-in's folder names
     * another refusal (no_address, no_nginx, no_port). A second link while one is open keeps its port and key and moves
     * the deadline (renew).
     */
    private function previewLink(PanelRequest $request): PanelAnswer
    {
        $domain = (string) ($request->args['domain'] ?? '');
        $site = $this->inner->ask(PanelRequest::read($request->actor, 'site:describe', ['domain' => $domain]));
        if ($site->status !== PanelAnswerStatus::Ok) {
            return PanelAnswer::refused($request->requestId, $site->reason ?? 'site_missing');
        }
        $open = $this->previewOpen($domain);
        if ($request->command === 'site:preview-link-status') {
            return $this->ok($request, 'result', ['schema' => 'simai-preview-link/1', 'domain' => $domain, 'preview_link' => self::previewShown($open)]);
        }
        if ($request->command === 'site:preview-link-close') {
            if (!$request->confirm) {
                return $this->ok($request, 'plan', ['command' => $request->command, 'args' => $request->args, 'target' => $domain,
                    'description' => 'Close a site\'s temporary link now', 'effects' => 'write', 'lock' => $domain, 'applied' => false]);
            }
            @unlink($this->previewFile($domain));

            return $this->started($request, [['Closing the temporary link of '.$domain, 'Done'], ['domain' => $domain, 'closed' => $open !== null]]);
        }
        $profile = $site->data['profile'] ?? null;
        $refusal = match (true) {
            $profile === 'alias' => 'alias',
            ($site->data['suspended'] ?? false) === true => 'suspended',
            is_file($this->root.'/preview-refuse') => trim((string) file_get_contents($this->root.'/preview-refuse')),
            default => null,
        };
        if ($refusal !== null && $refusal !== '') {
            return PanelAnswer::refused($request->requestId, $refusal);
        }
        $minutes = (int) ($request->args['minutes'] ?? '60');
        $port = $open['port'] ?? self::PREVIEW_PORT + count(glob($this->root.'/preview/*.json') ?: []);
        $address = 'http://'.self::PREVIEW_IP.':'.$port.'/';
        if (!$request->confirm) {
            return $this->ok($request, 'plan', ['command' => $request->command, 'args' => $request->args, 'target' => $domain,
                'description' => 'Open a temporary link to a site before its domain points here (renews an open one)', 'effects' => 'write',
                'lock' => $domain, 'applied' => false, 'preview' => ['address' => $address, 'minutes' => $minutes, 'renew' => $open !== null, 'mode' => 'ip_port']]);
        }
        $state = ['port' => $port, 'key' => $open['key'] ?? bin2hex(random_bytes(16)), 'expires' => time() + $minutes * 60];
        @mkdir($this->root.'/preview', 0o700, true);
        file_put_contents($this->previewFile($domain), json_encode($state, JSON_THROW_ON_ERROR));

        // The job's lines never carry the key: the link goes to the panel in the result only.
        return $this->started($request, [['Opening a temporary link to '.$domain.' on port '.$port, 'Link: (handed to the panel)', 'Done'],
            ['preview_url' => $address.'.simai-preview/'.$state['key'], 'address' => $address,
                'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $state['expires']), 'mode' => 'ip_port', 'renewed' => $open !== null]]);
    }

    /** site:describe with the site's open temporary link (address and deadline, never the key), as 1.65.0 describes it. */
    private function described(PanelRequest $request, PanelAnswer $answer): PanelAnswer
    {
        if ($answer->status !== PanelAnswerStatus::Ok) {
            return $answer;
        }
        $data = $answer->data;
        $data['preview_link'] = self::previewShown($this->previewOpen((string) ($request->args['domain'] ?? '')));

        return PanelAnswer::fromDocument($request->requestId, ['status' => 'ok', 'kind' => 'result', 'data' => $data,
            'command_status' => $answer->commandStatus, 'operation_id' => $answer->operationId]);
    }

    /** @return array{port: int, key: string, expires: int}|null the site's temporary link while it is open */
    private function previewOpen(string $domain): ?array
    {
        $state = json_decode((string) @file_get_contents($this->previewFile($domain)), true);
        if (!is_array($state) || !is_int($state['port'] ?? null) || !is_string($state['key'] ?? null) || !is_int($state['expires'] ?? null)) {
            return null;
        }

        return $state['expires'] > time() ? ['port' => $state['port'], 'key' => $state['key'], 'expires' => $state['expires']] : null;
    }

    /**
     * @param array{port: int, key: string, expires: int}|null $open
     * @return array{url: string, expires_at: string, mode: string}|null
     */
    private static function previewShown(?array $open): ?array
    {
        return $open === null ? null : ['url' => 'http://'.self::PREVIEW_IP.':'.$open['port'].'/', 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $open['expires']), 'mode' => 'ip_port'];
    }

    private function previewFile(string $domain): string
    {
        return $this->root.'/preview/'.(preg_match(\Larena\Env\Backup\HeldBackup::DOMAIN, $domain) === 1 ? $domain : 'none').'.json';
    }

    /**
     * backup:data-restore with files-live (simai-env 1.65.0): the files go straight into the site only when its folder
     * holds nothing but what site:add put there (a placeholder, the health check, empty folders); otherwise refused
     * root_not_empty, in the plan as on the confirmation. The stand-in unpacks nothing: it leaves a note of the files.
     */
    private function restoreLive(PanelRequest $request): PanelAnswer
    {
        $domain = (string) ($request->args['domain'] ?? '');
        if ($request->actor->owner !== null) {
            return PanelAnswer::refused($request->requestId, 'owner_admin_only');
        }
        if (!$this->siteEmpty($this->root.'/sites/'.(preg_match(\Larena\Env\Backup\HeldBackup::DOMAIN, $domain) === 1 ? $domain : 'none'))) {
            return PanelAnswer::refused($request->requestId, 'root_not_empty');
        }
        if (!$request->confirm) {
            return $this->ok($request, 'plan', ['command' => $request->command, 'args' => $request->args, 'target' => $domain,
                'description' => 'Restore a site database and put its files live into the empty site', 'effects' => 'write', 'lock' => $domain, 'applied' => false]);
        }
        $site = $this->root.'/sites/'.$domain;
        @mkdir($site.'/public', 0o775, true);
        file_put_contents($site.'/public/index.html', "<h1>{$domain}</h1>\n");

        return $this->started($request, ['Restoring the database of '.$domain, 'Putting the files live into the site directory', 'Done']);
    }

    /** Whether a site's folder holds nothing of its own: missing, or only placeholders, the health check and empty folders. */
    private function siteEmpty(string $folder): bool
    {
        if (!is_dir($folder)) {
            return true;
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (str_contains($file->getPathname(), '/.simai-trash/') || $file->getFilename() === 'healthcheck.php') {
                continue;
            }
            if (!str_contains((string) @file_get_contents($file->getPathname(), false, null, 0, 4096), 'SIMAI placeholder')) {
                return false;
            }
        }

        return true;
    }

    /** An owner works on their own sites only: as the gateway, through the recorded description. */
    private function scope(PanelRequest $request, string $domain): ?PanelAnswer
    {
        if ($request->actor->owner === null) {
            return null;
        }
        if (!str_starts_with($request->command, 'file:')) {
            return PanelAnswer::refused($request->requestId, 'owner_admin_only');
        }
        $own = $this->inner->ask(PanelRequest::read($request->actor, 'site:describe', ['domain' => $domain]));

        return $own->status === PanelAnswerStatus::Ok ? null : PanelAnswer::refused($request->requestId, $own->reason ?? 'owner_scope');
    }

    /**
     * @param array<string, string> $args
     *
     * @return array<string, mixed>
     *
     * @throws FakeRefusal
     */
    private function read(string $command, string $domain, array $args): array
    {
        $relative = $args['path'] ?? '';
        $path = $this->path($domain, $relative);
        if ($command === 'file:trash-list') {
            return $this->trashList($domain);
        }
        if ($command === 'file:list') {
            if (!is_dir($path)) {
                throw new FakeRefusal(file_exists($path) ? 'not_a_directory' : 'not_found');
            }
            $entries = [];
            foreach (array_diff((array) scandir($path), ['.', '..']) as $name) {
                $entries[] = ['name' => (string) $name] + self::stat($path.'/'.$name) + self::mark((string) $name, $path.'/'.$name);
            }
            // Directories first, as the gateway lists them.
            usort($entries, static fn (array $a, array $b): int => [$a['type'] === 'dir' ? 0 : 1, $a['name']] <=> [$b['type'] === 'dir' ? 0 : 1, $b['name']]);

            return ['schema' => 'simai-files/1', 'path' => $relative === '.' ? '' : $relative, 'entries' => $entries, 'truncated' => false];
        }
        if (!file_exists($path) && !is_link($path)) {
            throw new FakeRefusal('not_found');
        }
        if ($command === 'file:stat') {
            return ['schema' => 'simai-file-stat/1', 'path' => $relative, 'name' => basename($relative)] + self::stat($path) + self::mark(basename($relative), $path);
        }
        if (!is_file($path) || is_link($path)) {
            throw new FakeRefusal('not_a_file');
        }
        if ($command === 'file:preview') {
            return self::preview($relative, $path, (int) ($args['max-bytes'] ?? 1_048_576));
        }
        if (filesize($path) > 1_048_576) {
            throw new FakeRefusal('too_large');
        }
        $bytes = (string) file_get_contents($path);
        if (!mb_check_encoding($bytes, 'UTF-8') || str_contains($bytes, "\0")) {
            throw new FakeRefusal('not_text');
        }

        return ['schema' => 'simai-file/1', 'path' => $relative, 'size' => strlen($bytes), 'mtime' => (int) filemtime($path), 'sha256' => hash('sha256', $bytes), 'content' => $bytes];
    }

    /**
     * file:preview as simai-env 1.61.0 answers it: a picture whole (base64) when it fits, a text's first bytes (UTF-8)
     * with `truncated`, anything else not previewable.
     *
     * @return array<string, mixed>
     *
     * @throws FakeRefusal
     */
    private static function preview(string $relative, string $path, int $max): array
    {
        $size = (int) filesize($path);
        $head = (string) file_get_contents($path, false, null, 0, 16);
        $image = match (true) {
            str_starts_with($head, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($head, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($head, 'GIF87a'), str_starts_with($head, 'GIF89a') => 'image/gif',
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
        $base = ['schema' => 'simai-file-preview/1', 'path' => $relative, 'size' => $size, 'mtime' => (int) filemtime($path)];
        if ($image !== null) {
            if ($size > $max) {
                throw new FakeRefusal('too_large');
            }
            $bytes = (string) file_get_contents($path);

            return $base + ['kind' => 'image', 'mime' => $image, 'encoding' => 'base64', 'truncated' => false, 'bytes' => strlen($bytes),
                'sha256' => hash('sha256', $bytes), 'content' => base64_encode($bytes)];
        }
        $bytes = (string) file_get_contents($path, false, null, 0, $max);
        $cut = mb_strcut($bytes, 0, strlen($bytes), 'UTF-8');
        if (str_contains($bytes, "\0") || !mb_check_encoding($size > $max ? $cut : $bytes, 'UTF-8')) {
            throw new FakeRefusal('not_previewable');
        }
        $text = $size > $max ? $cut : $bytes;

        return $base + ['kind' => 'text', 'mime' => 'text/plain', 'encoding' => 'utf-8', 'truncated' => $size > $max, 'bytes' => strlen($text),
            'sha256' => hash('sha256', $text), 'content' => $text];
    }

    /** @return array{type: string, size: int, mtime: int, mode: string, target?: string} */
    private static function stat(string $full): array
    {
        $type = is_link($full) ? 'link' : (is_dir($full) ? 'dir' : (is_file($full) ? 'file' : 'other'));
        $stat = ['type' => $type, 'size' => $type === 'link' ? 0 : (int) @filesize($full), 'mtime' => (int) @filemtime($full),
            'mode' => sprintf('%o', (int) @fileperms($full) & 0o777)];

        return $type === 'link' ? $stat + ['target' => (string) readlink($full)] : $stat;
    }

    /**
     * The server's mark of a file with passwords (simai-env 1.62.2): its own list, which the panel's FileEntry::secret
     * copies; every file has the mark, true or false.
     *
     * @return array{secret?: bool}
     */
    private static function mark(string $name, string $full): array
    {
        return is_file($full) && !is_link($full) ? ['secret' => \Larena\Env\Files\FileEntry::secret($name)] : [];
    }

    /**
     * The files with passwords under a path, as the site's paths: what `without-secrets yes` leaves out of an archive.
     *
     * @return list<string>
     */
    private function secretsUnder(string $domain, string $relative): array
    {
        $full = $this->path($domain, $relative);
        if (is_file($full)) {
            return \Larena\Env\Files\FileEntry::secret(basename($full)) ? [trim($relative, '/')] : [];
        }
        $found = [];
        foreach (is_dir($full) && !is_link($full) ? array_diff((array) scandir($full), ['.', '..']) : [] as $name) {
            $found = [...$found, ...$this->secretsUnder($domain, trim($relative, '/').'/'.$name)];
        }

        return $found;
    }

    /** @throws FakeRefusal what the gateway refuses before it plans */
    private function check(string $command, string $domain, PanelRequest $request): void
    {
        $args = $request->args;
        match ($command) {
            'file:rename' => $this->free($this->path($domain, $args['to'])) && $this->existing($this->path($domain, $args['path'])),
            'file:mkdir' => $this->free($this->path($domain, $args['path'])),
            'file:remove' => $this->existing($this->path($domain, $args['path'])) && $this->notSite($args['path']),
            'file:archive' => $this->existing($this->path($domain, $args['path'])) && $this->free($this->path($domain, $args['to']))
                && $this->packable($domain, $args),
            'file:extract' => $this->existing($this->path($domain, $args['path'])) && $this->path($domain, $args['to']) !== '',
            'file:write', 'file:upload' => $this->expected($this->path($domain, $args['path']), $args['expect-sha256'] ?? 'any'),
            // As 1.57.0: a restore's plan fails while its place is taken or the entry is gone.
            'file:trash-restore' => $this->restorable($domain, $args['id']),
            'file:trash-empty' => !isset($args['id']) || $this->inTrash($domain, $args['id']),
            default => true,
        };
    }

    /**
     * What the person expected at the path is still there: nothing (`new`), the text read before (its sha256), or
     * anything (`any`).
     *
     * @throws FakeRefusal
     */
    private function expected(string $path, string $expect): bool
    {
        if ($expect === 'new') {
            return $this->free($path);
        }
        if ($expect !== 'any' && (!is_file($path) || !hash_equals($expect, (string) hash_file('sha256', $path)))) {
            throw new FakeRefusal(is_file($path) ? 'changed' : 'not_found');
        }

        return true;
    }

    /** @throws FakeRefusal the site's folder itself is never removed */
    private function notSite(string $relative): bool
    {
        if (trim($relative, '/.') === '') {
            throw new FakeRefusal('bad_path');
        }

        return true;
    }

    /**
     * @return list<string>|array{0: list<string>, 1: array<string, mixed>} the job's lines, or its lines and its result
     *
     * @throws FakeRefusal
     */
    private function act(string $command, string $domain, PanelRequest $request): array
    {
        $args = $request->args;
        switch ($command) {
            case 'file:write':
                $target = $this->path($domain, $args['path']);
                // A file replaced goes to the trash first (1.64.0, reason overwritten); nothing is replaced without it.
                $previous = is_file($target) ? $this->intoTrash($domain, $args['path'], 'overwritten') : null;
                file_put_contents($target, (string) $args['content']);

                return [["Wrote {$args['path']} (".strlen((string) $args['content']).' bytes)'], ['previous_trash_id' => $previous]];
            case 'file:upload':
                $target = $this->path($domain, $args['path']);
                $attachment = $request->attachment ?? throw new FakeRefusal('bad_request');
                if (!hash_equals($attachment->sha256, (string) hash_file('sha256', $attachment->path))) {
                    throw new FakeRefusal('attachment_mismatch');
                }
                $previous = is_file($target) ? $this->intoTrash($domain, $args['path'], 'overwritten') : null;
                copy($attachment->path, $target);

                return [["Uploaded {$args['path']} ({$attachment->size} bytes)"], ['previous_trash_id' => $previous]];
            case 'file:mkdir':
                if (!is_dir(dirname($this->path($domain, $args['path'])))) {
                    throw new FakeRefusal('not_found');
                }
                mkdir($this->path($domain, $args['path']), 0o775);

                return ["Made {$args['path']}"];
            case 'file:rename':
                rename($this->path($domain, $args['path']), $this->path($domain, $args['to']));

                return ["Renamed {$args['path']} to {$args['to']}"];
            case 'file:remove':
                // Into the site's trash, as simai-env 1.57.0: kept there, put back by file:trash-restore.
                $id = $this->intoTrash($domain, $args['path'], 'removed');

                return ["Moved {$args['path']} to the trash ({$id})"];
            case 'file:trash-restore':
                $meta = $this->trashMeta($domain, $args['id']);
                $to = $this->path($domain, $args['to'] ?? (string) $meta['path']);
                @mkdir(dirname($to), 0o775, true);
                rename($this->path($domain, '.').'/.simai-trash/'.$args['id'].'/item', $to);
                self::removeTree($this->path($domain, '.').'/.simai-trash/'.$args['id']);

                return ["Restored {$meta['path']}"];
            case 'file:trash-empty':
                $ids = isset($args['id']) ? [$args['id']] : array_column($this->trashList($domain)['entries'], 'id');
                foreach ($ids as $id) {
                    self::removeTree($this->path($domain, '.').'/.simai-trash/'.$id);
                }

                return ['Deleted '.count($ids).' trash entries for good'];
            case 'file:archive':
                // Without the files with passwords (1.62.2): the stand-in packs nothing, and names what it would leave out.
                if (($args['without-secrets'] ?? 'no') === 'yes') {
                    $skipped = $this->secretsUnder($domain, $args['path']);

                    return [["Packed {$args['path']} into {$args['to']}", 'packed: 1'], self::skipped($skipped)];
                }

                return ["Packed {$args['path']} into {$args['to']}", 'packed: 1'];
            case 'file:extract':
                if (($args['overwrite'] ?? 'no') !== 'yes' && is_dir($this->path($domain, $args['to'])) && $this->path($domain, $args['to']) !== $this->path($domain, '.')) {
                    // The stand-in unpacks nothing; a folder that is there already holds files of the same name.
                    throw new FakeRefusal('exists');
                }
                if (($args['without-secrets'] ?? 'no') === 'yes') {
                    // The entries of a zip with passwords, as the site's paths they would have had: neither made nor overwritten.
                    $skipped = [];
                    $zip = class_exists(\ZipArchive::class) ? new \ZipArchive() : null;
                    if ($zip !== null && $zip->open($this->path($domain, $args['path'])) === true) {
                        for ($index = 0; $index < $zip->numFiles; $index++) {
                            $name = (string) $zip->getNameIndex($index);
                            if (!str_ends_with($name, '/') && \Larena\Env\Files\FileEntry::secret(basename($name))) {
                                $into = trim($args['to'], '/');
                                $skipped[] = ($into === '' || $into === '.' ? '' : $into.'/').$name;
                            }
                        }
                        $zip->close();
                    }

                    return [["Unpacked {$args['path']} into {$args['to']}", 'files: 0, dirs: 0, skipped: 0'], self::skipped($skipped) + ['trashed' => 0, 'trash_ids' => []]];
                }

                // The stand-in unpacks nothing, so nothing it replaced went to the trash (1.64.0 names what did).
                return [["Unpacked {$args['path']} into {$args['to']}", 'files: 0, dirs: 0, skipped: 0'], ['trashed' => 0, 'trash_ids' => []]];
        }

        throw new FakeRefusal('not_allowed');
    }

    /**
     * The local path of a site's path: its folder is made with a page and a note the first time; anything that would
     * leave it is refused path_outside, as the gateway does.
     *
     * @throws FakeRefusal
     */
    private function path(string $domain, string $relative): string
    {
        $site = $this->root.'/sites/'.$domain;
        if (!is_dir($site)) {
            mkdir($site.'/public', 0o775, true);
            file_put_contents($site.'/public/index.html', "<h1>{$domain}</h1>\n");
            file_put_contents($site.'/notes.txt', "Notes of {$domain}\n");
        }
        if ($relative === '.' || $relative === '') {
            return $site;
        }
        if (str_starts_with($relative, '/') || preg_match('#(\A|/)\.\.(/|\z)#', $relative) === 1) {
            throw new FakeRefusal('path_outside');
        }
        $path = $site.'/'.trim($relative, '/');
        $real = realpath(file_exists($path) ? $path : dirname($path));
        $siteReal = (string) realpath($site);
        if ($real === false || ($real !== $siteReal && !str_starts_with($real, $siteReal.'/'))) {
            throw new FakeRefusal(file_exists(dirname($path)) || $real !== false ? 'path_outside' : 'not_found');
        }

        return $path;
    }

    /** @return array<string, mixed> the site's trash, simai-file-trash/1 */
    private function trashList(string $domain): array
    {
        $dir = $this->path($domain, '.').'/.simai-trash';
        $entries = [];
        foreach (is_dir($dir) ? array_diff((array) scandir($dir), ['.', '..']) : [] as $id) {
            $meta = json_decode((string) @file_get_contents($dir.'/'.$id.'/meta.json'), true);
            if (is_array($meta)) {
                $entries[] = ['id' => (string) $id, 'path' => $meta['path'] ?? null, 'name' => $meta['name'] ?? null, 'type' => $meta['type'] ?? 'file',
                    'size' => (int) ($meta['size'] ?? 0), 'deleted_at' => $meta['deleted_at'] ?? null, 'expires_at' => $meta['expires_at'] ?? null,
                    'secret' => ($meta['type'] ?? 'file') === 'file' && \Larena\Env\Files\FileEntry::secret((string) ($meta['name'] ?? '')),
                    'reason' => ($meta['reason'] ?? 'removed') === 'overwritten' ? 'overwritten' : 'removed'];
            }
        }
        rsort($entries);

        return ['schema' => 'simai-file-trash/1', 'dir' => '.simai-trash', 'retention_days' => self::TRASH_DAYS, 'entries' => $entries,
            'count' => count($entries), 'total_bytes' => array_sum(array_column($entries, 'size'))];
    }

    /**
     * A path moved into the site's trash with why it went (removed, or overwritten by a write, an upload or an unpacking).
     *
     * @throws FakeRefusal
     */
    private function intoTrash(string $domain, string $relative, string $reason): string
    {
        $id = gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(3));
        $entry = $this->path($domain, '.').'/.simai-trash/'.$id;
        mkdir($entry, 0o700, true);
        $from = $this->path($domain, $relative);
        $meta = ['id' => $id, 'path' => trim($relative, '/'), 'name' => basename($relative)] + self::stat($from)
            + ['deleted_at' => gmdate('Y-m-d\TH:i:s\Z'), 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + self::TRASH_DAYS * 86400), 'reason' => $reason];
        $reason === 'overwritten' ? copy($from, $entry.'/item') : rename($from, $entry.'/item');
        file_put_contents($entry.'/meta.json', json_encode($meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $id;
    }

    /**
     * What file:trash-empty would delete for good (1.64.0 plan `trash`): all entries, or the one asked.
     *
     * @return array{items: int, bytes: int, ids: list<string>}
     */
    private function purged(string $domain, ?string $id): array
    {
        $entries = array_values(array_filter($this->trashList($domain)['entries'], static fn (array $entry): bool => $id === null || $entry['id'] === $id));

        return ['items' => count($entries), 'bytes' => array_sum(array_column($entries, 'size')), 'ids' => array_column($entries, 'id')];
    }

    /**
     * The last backup before a site or its database goes (1.64.0): kept in the stand-in's list seven days, then the
     * action passes on; a file `backup-fails` in its folder makes the backup fail, and nothing is removed.
     */
    private function keepLast(PanelRequest $request): ?PanelAnswer
    {
        if (is_file($this->root.'/backup-fails')) {
            return PanelAnswer::refused($request->requestId, 'backup_failed');
        }
        $domain = (string) ($request->args['domain'] ?? '');
        $stamp = gmdate('Ymd-His');
        $held = $this->heldItems();
        $held[] = ['domain' => $domain, 'site_exists' => $request->command !== 'site:remove', 'backup' => $stamp,
            'path' => '/var/backups/simai/'.$domain.'/'.$stamp, 'created_at' => gmdate('Y-m-d\TH:i:s'), 'size_bytes' => 4374,
            'parts' => $request->command === 'site:remove' ? ['config', 'db', 'files'] : ['db'], 'checksums' => true, 'encrypted' => false,
            'reason' => self::BACKED_UP[$request->command], 'keep_until' => gmdate('Y-m-d\TH:i:s', time() + 7 * 86400)];
        @mkdir($this->root, 0o775, true);
        file_put_contents($this->root.'/held-backups.json', json_encode($held, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return null;
    }

    /** @return array<string, mixed> backup:data-list as 1.64.0 answers it (simai-backup-list/1) */
    private function held(?string $domain): array
    {
        $items = array_values(array_filter($this->heldItems(), static fn (array $item): bool => $domain === null || $item['domain'] === $domain));

        return ['schema' => 'simai-backup-list/1', 'dest' => '/var/backups/simai', 'count' => count($items), 'items' => $items];
    }

    /** @return list<array<string, mixed>> */
    private function heldItems(): array
    {
        $held = json_decode((string) @file_get_contents($this->root.'/held-backups.json'), true);

        return is_array($held) ? array_values(array_filter($held, 'is_array')) : [];
    }

    /** @return array<string, mixed> @throws FakeRefusal */
    private function trashMeta(string $domain, string $id): array
    {
        $meta = json_decode((string) @file_get_contents($this->path($domain, '.').'/.simai-trash/'.$id.'/meta.json'), true);
        if (!is_array($meta)) {
            throw new FakeRefusal('plan_failed');
        }

        return $meta;
    }

    /** @throws FakeRefusal */
    private function inTrash(string $domain, string $id): bool
    {
        $this->trashMeta($domain, $id);

        return true;
    }

    /** @throws FakeRefusal */
    private function restorable(string $domain, string $id): bool
    {
        if (file_exists($this->path($domain, (string) $this->trashMeta($domain, $id)['path']))) {
            throw new FakeRefusal('plan_failed');
        }

        return true;
    }

    /**
     * @param array<string, string> $args
     *
     * @throws FakeRefusal packing a file with passwords itself without them is refused `secret`, as 1.62.2 does
     */
    private function packable(string $domain, array $args): bool
    {
        if (($args['without-secrets'] ?? 'no') === 'yes' && is_file($this->path($domain, $args['path']))
            && \Larena\Env\Files\FileEntry::secret(basename($args['path']))) {
            throw new FakeRefusal('secret');
        }

        return true;
    }

    /** @throws FakeRefusal */
    private function free(string $path): bool
    {
        if (file_exists($path)) {
            throw new FakeRefusal('exists');
        }

        return true;
    }

    /** @throws FakeRefusal */
    private function existing(string $path): bool
    {
        if (!file_exists($path)) {
            throw new FakeRefusal('not_found');
        }

        return true;
    }

    /** @param array<string, mixed> $data */
    private function ok(PanelRequest $request, string $kind, array $data): PanelAnswer
    {
        return PanelAnswer::fromDocument($request->requestId, ['status' => 'ok', 'kind' => $kind, 'data' => $data, 'command_status' => 'success']);
    }

    /**
     * The job's result of what was left out, as simai-env 1.62.2 keeps it: at most 30 paths, and the count.
     *
     * @param list<string> $skipped
     * @return array{secret_skipped: list<string>, secret_skipped_total: int}
     */
    private static function skipped(array $skipped): array
    {
        return ['secret_skipped' => array_slice($skipped, 0, 30), 'secret_skipped_total' => count($skipped)];
    }

    /** @param list<string>|array{0: list<string>, 1: array<string, mixed>} $lines the job's lines, or its lines and its result */
    private function started(PanelRequest $request, array $lines): PanelAnswer
    {
        $result = null;
        if (isset($lines[1]) && is_array($lines[1]) && is_array($lines[0])) {
            [$lines, $result] = $lines;
        }
        $id = bin2hex(random_bytes(12));
        @mkdir($this->root.'/jobs', 0o775, true);
        file_put_contents($this->jobFile($id), json_encode(['job_id' => $id, 'state' => 'succeeded', 'exit_code' => 0, 'command' => $request->command,
            'args' => $request->args, 'created_at' => gmdate('Y-m-d\TH:i:s\Z'), 'request_id' => $request->requestId,
            'actor' => ['user' => $request->actor->user, 'owner' => $request->actor->owner], 'lines' => $lines]
            + ($result !== null ? ['result' => $result] : []), JSON_THROW_ON_ERROR));

        return PanelAnswer::fromDocument($request->requestId, ['status' => 'ok', 'kind' => 'job', 'job_id' => $id]);
    }

    private function job(PanelRequest $request): PanelAnswer
    {
        try {
            $job = json_decode((string) file_get_contents($this->jobFile($request->args['job_id'] ?? '')), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return PanelAnswer::failed($request->requestId, PanelReason::RECORDING_MISSING);
        }
        $lines = (array) $job['lines'];
        unset($job['lines']);
        $after = (int) ($request->args['after'] ?? 0);
        // ONCE_RESULT: the first job:status of a finished job hands the values out and the file keeps only their names.
        $once = is_array($job['result'] ?? null) ? array_values(array_intersect(self::ONCE, array_keys($job['result']))) : [];
        if ($request->command === 'job:status' && $once !== []) {
            $kept = $job + ['lines' => $lines];
            $kept['result'] = array_diff_key($job['result'], array_flip($once)) + ['shown_once' => $once];
            file_put_contents($this->jobFile($request->args['job_id'] ?? ''), json_encode($kept, JSON_THROW_ON_ERROR));
        }

        return $this->ok($request, 'result', $request->command === 'job:status' ? $job
            : ['job_id' => $job['job_id'], 'state' => $job['state'], 'lines' => array_slice($lines, $after), 'next' => count($lines)]);
    }

    private function jobFile(string $id): string
    {
        return $this->root.'/jobs/'.(preg_match('/\A[a-f0-9]{24}\z/D', $id) === 1 ? $id : 'none').'.json';
    }

    private static function removeTree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (array_diff((array) scandir($path), ['.', '..']) as $name) {
                self::removeTree($path.'/'.$name);
            }
            rmdir($path);

            return;
        }
        @unlink($path);
    }
}
