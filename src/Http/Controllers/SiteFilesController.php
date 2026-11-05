<?php

declare(strict_types=1);

namespace Larena\Env\Http\Controllers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Larena\Env\Files\FilePath;
use Larena\Env\Files\SiteFiles;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelAttachment;
use Larena\Env\Gateway\PanelCapabilities;
use Larena\Env\Gateway\PanelCommands;
use Larena\Env\Gateway\PanelReason;
use Larena\Env\Gateway\PanelRefused;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Http\Support\NoticeRedirect;
use Larena\Env\Operations\PanelOperations;
use Larena\Env\Server\ServerOverviewReader;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A site's files (simai-env 1.39.0–1.40.0) and its own nginx directives (Panel plan 6.1–6.3). Every change goes the
 * panel's one way: the server says what it would do, a person confirms, and only then it runs as a job. A saved text
 * travels in the request with the sha256 it was read with, so a change made meanwhile is refused, never overwritten.
 * An uploaded file waits on the panel's disk between the plan and the confirmation, named in the person's session by
 * its sha256, and is sent after the confirmed request; it is removed once sent. A download is checked against the size
 * the server named before a byte of it reaches the browser. There is no trash: a removal is final, and the plan says so.
 */
final readonly class SiteFilesController
{
    /** The changes of the «Файлы» tab and the gateway command of each. */
    public const array FILE_OPERATIONS = ['write' => 'file:write', 'create' => 'file:write', 'upload' => 'file:upload', 'mkdir' => 'file:mkdir',
        'rename' => 'file:rename', 'remove' => 'file:remove', 'archive' => 'file:archive', 'extract' => 'file:extract'];
    /** The formats file:archive makes, by the name it is given. */
    public const array ARCHIVE_FORMATS = ['zip', 'tar.gz'];
    /** The nginx directives', the system level's only. */
    public const array OVERLAY_OPERATIONS = ['overlay-set' => 'site:nginx-overlay-set', 'overlay-clear' => 'site:nginx-overlay-clear',
        'overlay-confirm' => 'change:confirm'];

    private const string STAGED = 'larena_env_staged';
    private const int STAGED_SECONDS = 3600;

    public function __construct(
        private PanelOperations $operations,
        private ServerOverviewReader $overviews,
        private SiteFiles $files,
        private Factory $views,
        private Translator $translator,
        private Application $app,
    ) {
    }

    /** What the change would do, with a confirmation that does it and nothing else. */
    public function plan(Request $request, string $domain): mixed
    {
        $actor = ResolvePanelActor::of($request);
        $op = (string) $request->input('op');
        $command = $this->offered($actor, $op);
        $tab = isset(self::OVERLAY_OPERATIONS[$op]) ? 'nginx' : 'files';
        if ($command === null) {
            return $this->back($request, $domain, $tab, PanelReason::NOT_ALLOWED);
        }
        $args = $this->arguments($request, $op, $domain);
        if ($args === null) {
            return $this->back($request, $domain, $tab, 'path_invalid');
        }
        $staged = null;
        if (PanelCommands::attachmentLimit($command) !== null) {
            $staged = $this->stage($request, $domain, $command, $op);
            if (is_string($staged)) {
                return $this->back($request, $domain, $tab, $staged);
            }
        }
        $result = $this->operations->planCommand($actor, $command, $args, $staged['attachment'] ?? null);
        if ($result->plan === null) {
            if ($staged !== null) {
                $this->unstage($request, $staged['id']);
            }

            return $this->back($request, $domain, $tab, $result->reason ?? PanelReason::ANSWER_SHAPE_INVALID, $args['path'] ?? null, $op);
        }
        $hidden = ['op' => $op];
        foreach ($args as $name => $value) {
            $hidden['args['.$name.']'] = $value;
        }
        if ($staged !== null) {
            $hidden['staged'] = $staged['id'];
        }

        return $this->views->make('larena-env::admin.plan', [
            'heading' => (string) $this->translator->get('larena-env::admin.files.operations.'.$op),
            'eyebrow' => $domain,
            'target' => $domain,
            'source' => $result->source,
            'plan' => $result->plan,
            'runUrl' => route($tab === 'nginx' ? 'larena.env.admin.site.nginx.run' : 'larena.env.admin.site.files.run', ['domain' => $domain], false),
            'cancelUrl' => route('larena.env.admin.site', ['domain' => $domain], false).'?tab='.$tab,
            'hidden' => $hidden,
            'replaces' => $this->replaces($op, $args),
        ]);
    }

    /** The confirmation itself: the server starts the change, with its file when it has one, and the screen follows the job. */
    public function run(Request $request, string $domain): RedirectResponse
    {
        $actor = ResolvePanelActor::of($request);
        $op = (string) $request->input('op');
        $command = $this->offered($actor, $op);
        $tab = isset(self::OVERLAY_OPERATIONS[$op]) ? 'nginx' : 'files';
        $args = array_filter((array) $request->input('args', []), static fn (mixed $value, mixed $name): bool => is_string($name) && is_string($value), ARRAY_FILTER_USE_BOTH);
        // A saved text goes back as it was planned, its spaces and last line end kept.
        if (array_key_exists('content', $args)) {
            $args['content'] = str_replace("\r\n", "\n", self::rawText($request, 'content', 'args'));
        }
        // Keeping a timed change names the change only (change:confirm); every other change names its site.
        $site = $op === 'overlay-confirm' ? $domain : ($args['domain'] ?? null);
        if ($command === null || $site !== $domain) {
            return $this->back($request, $domain, $tab, PanelReason::NOT_ALLOWED);
        }
        $attachment = null;
        $stagedId = (string) $request->input('staged');
        if (PanelCommands::attachmentLimit($command) !== null) {
            $attachment = $this->staged($request, $stagedId, $domain, $command);
            if ($attachment === null) {
                return $this->back($request, $domain, $tab, 'staged_missing');
            }
        }
        try {
            $started = $this->operations->startCommand($actor, $command, $args, $attachment);
        } finally {
            if ($attachment !== null) {
                $this->unstage($request, $stagedId);
            }
        }
        if ($started->jobId === null) {
            return $this->back($request, $domain, $tab, $started->reason ?? PanelReason::ANSWER_SHAPE_INVALID, $args['path'] ?? null, $op);
        }

        return new RedirectResponse(route('larena.env.admin.job', ['job' => $started->jobId], false));
    }

    /** A site's file, handed to the browser only once its bytes match what the server said it sent. */
    public function download(Request $request, string $domain): mixed
    {
        $actor = ResolvePanelActor::of($request);
        $path = FilePath::clean($request->query('path'));
        if (!$this->capabilities($actor)->allows('file:download')) {
            return $this->back($request, $domain, 'files', PanelReason::NOT_ALLOWED);
        }
        if ($path === null || $path === '.') {
            return $this->back($request, $domain, 'files', 'path_invalid');
        }
        $folder = $this->app->storagePath('app/larena-env/downloads');
        if (!is_dir($folder) && !@mkdir($folder, 0o700, true) && !is_dir($folder)) {
            return $this->back($request, $domain, 'files', PanelReason::BAD_REQUEST);
        }
        $into = $folder.'/'.bin2hex(random_bytes(16));
        $download = $this->files->download($actor, $domain, $path, $into);
        if (!$download->ok() || $download->name === null) {
            @unlink($into);

            return $this->back($request, $domain, 'files', $download->answer->reason ?? PanelReason::ANSWER_INVALID, FilePath::parent($path));
        }
        $offset = $download->offset;
        $name = preg_replace('/[^\x20-\x7e]|["\\\\]/', '_', $download->name) ?: 'file';

        return new StreamedResponse(static function () use ($into, $offset): void {
            $stream = fopen($into, 'rb');
            if ($stream !== false) {
                fseek($stream, $offset);
                while (!feof($stream)) {
                    echo fread($stream, 1_048_576);
                    flush();
                }
                fclose($stream);
            }
            @unlink($into);
        }, 200, [
            // Never shown in the panel's own origin: a downloaded page or script cannot act as the panel.
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) $download->size,
            'Content-Disposition' => 'attachment; filename="'.$name.'"; filename*=UTF-8\'\''.rawurlencode($download->name),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * The gateway arguments of the change, from the form: paths are cleaned, never trusted as typed.
     *
     * @return array<string, string>|null null when a path or name is not one
     */
    private function arguments(Request $request, string $op, string $domain): ?array
    {
        $path = FilePath::clean($request->input('path'));
        $folder = FilePath::clean($request->input('folder'));
        $name = self::name($request->input('name'));
        $args = ['domain' => $domain];
        $entry = $path !== null && $path !== '.';
        switch ($op) {
            case 'write':
                // The sha256 the text was read with: a change made meanwhile is refused `changed`.
                $sha256 = $request->input('sha256');
                $content = str_replace("\r\n", "\n", self::rawText($request, 'content'));
                if (!$entry || !is_string($sha256) || preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1 || !mb_check_encoding($content, 'UTF-8')) {
                    return null;
                }

                return $args + ['path' => $path, 'content' => $content, 'expect-sha256' => $sha256];
            case 'create':
                return $folder === null || $name === null ? null : $args + ['path' => FilePath::join($folder, $name), 'content' => '', 'expect-sha256' => 'new'];
            case 'remove':
                return $entry ? $args + ['path' => $path] : null;
            case 'upload':
                $file = $request->file('file');
                $uploaded = $file instanceof UploadedFile ? self::name($file->getClientOriginalName()) : null;
                if ($folder === null || $uploaded === null) {
                    return null;
                }

                // A file already there is replaced only when the person said so.
                return $args + ['path' => FilePath::join($folder, $uploaded), 'expect-sha256' => $request->input('overwrite') === 'yes' ? 'any' : 'new'];
            case 'mkdir':
                return $folder === null || $name === null ? null : $args + ['path' => FilePath::join($folder, $name)];
            case 'rename':
                return !$entry || $name === null ? null : $args + ['path' => $path, 'to' => FilePath::join(FilePath::parent($path), $name)];
            case 'archive':
                $format = in_array($request->input('format'), self::ARCHIVE_FORMATS, true) ? (string) $request->input('format') : 'zip';
                if (!$entry) {
                    return null;
                }
                $archive = ($name ?? basename($path)).'.'.$format;

                return self::name($archive) === null ? null : $args + ['path' => $path, 'to' => FilePath::join(FilePath::parent($path), $archive)];
            case 'extract':
                return !$entry || $folder === null ? null : $args + ['path' => $path, 'to' => $folder, 'overwrite' => $request->input('overwrite') === 'yes' ? 'yes' : 'no'];
            case 'overlay-set':
            case 'overlay-clear':
                return $args;
            case 'overlay-confirm':
                $change = $request->input('change');

                // The shared confirmation of a timed change names the change only; the server knows its site.
                return is_string($change) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D', $change) === 1 ? ['change_id' => $change] : null;
        }

        return null;
    }

    /**
     * Keeps the file a change sends on the panel's disk until it is confirmed: an upload, nginx directives. The session names it with its sha256, so another file cannot be sent in its place.
     *
     * @return array{id: string, attachment: PanelAttachment}|string the staged file, or why it cannot be
     */
    private function stage(Request $request, string $domain, string $command, string $op): array|string
    {
        $folder = $this->app->storagePath('app/larena-env/staged');
        if (!is_dir($folder) && !@mkdir($folder, 0o700, true) && !is_dir($folder)) {
            return PanelReason::BAD_REQUEST;
        }
        $this->pruneStaged($request, $folder);
        $id = bin2hex(random_bytes(16));
        $path = $folder.'/'.$id;
        if ($op === 'upload') {
            $file = $request->file('file');
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                return 'upload_failed';
            }
            $file->move($folder, $id);
        } else {
            // A browser sends a text area's lines ended by CR LF; the file keeps the lines a server's files have.
            $text = str_replace("\r\n", "\n", self::rawText($request, 'content'));
            if (file_put_contents($path, $text) === false) {
                return PanelReason::BAD_REQUEST;
            }
        }
        try {
            $attachment = PanelAttachment::fromFile($path);
        } catch (PanelRefused $refused) {
            @unlink($path);

            return $refused->reasonCode;
        }
        if ($attachment->size > (int) PanelCommands::attachmentLimit($command)) {
            @unlink($path);

            return PanelReason::TOO_LARGE;
        }
        $staged = (array) $request->session()->get(self::STAGED, []);
        $staged[$id] = ['domain' => $domain, 'command' => $command, 'sha256' => $attachment->sha256, 'at' => time()];
        $request->session()->put(self::STAGED, $staged);

        return ['id' => $id, 'attachment' => $attachment];
    }

    /** The staged file of this person's plan, unchanged since: anything else is not sent. */
    private function staged(Request $request, string $id, string $domain, string $command): ?PanelAttachment
    {
        $entry = ((array) $request->session()->get(self::STAGED, []))[$id] ?? null;
        if (preg_match('/\A[a-f0-9]{32}\z/D', $id) !== 1 || !is_array($entry) || ($entry['domain'] ?? null) !== $domain || ($entry['command'] ?? null) !== $command) {
            return null;
        }
        try {
            $attachment = PanelAttachment::fromFile($this->app->storagePath('app/larena-env/staged/'.$id));
        } catch (PanelRefused) {
            return null;
        }

        return hash_equals((string) ($entry['sha256'] ?? ''), $attachment->sha256) ? $attachment : null;
    }

    private function unstage(Request $request, string $id): void
    {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $id) === 1) {
            @unlink($this->app->storagePath('app/larena-env/staged/'.$id));
        }
        $staged = (array) $request->session()->get(self::STAGED, []);
        unset($staged[$id]);
        $request->session()->put(self::STAGED, $staged);
    }

    /** A plan left unconfirmed for an hour is dropped, with its file. */
    private function pruneStaged(Request $request, string $folder): void
    {
        $staged = (array) $request->session()->get(self::STAGED, []);
        foreach ($staged as $id => $entry) {
            if (!is_array($entry) || (int) ($entry['at'] ?? 0) < time() - self::STAGED_SECONDS) {
                @unlink($folder.'/'.$id);
                unset($staged[$id]);
            }
        }
        $request->session()->put(self::STAGED, $staged);
    }

    /** @param array<string, string> $args */
    private function replaces(string $op, array $args): ?string
    {
        $key = match ($op) {
            'write' => 'replaces_write',
            'upload' => ($args['expect-sha256'] ?? 'new') === 'any' ? 'replaces_upload' : null,
            'remove' => 'replaces_remove',
            'extract' => ($args['overwrite'] ?? 'no') === 'yes' ? 'replaces_extract' : null,
            'overlay-set', 'overlay-clear' => 'replaces_overlay',
            default => null,
        };

        return $key === null ? null : (string) $this->translator->get('larena-env::admin.files.'.$key, ['path' => $args['path'] ?? '', 'to' => $args['to'] ?? '']);
    }

    /** The change's command, when this server's gateway has it; the nginx directives only for the system level. */
    private function offered(PanelActor $actor, string $op): ?string
    {
        $command = self::FILE_OPERATIONS[$op] ?? (($actor->owner === null) ? (self::OVERLAY_OPERATIONS[$op] ?? null) : null);

        return $command !== null && $this->capabilities($actor)->allows($command) ? $command : null;
    }

    private function capabilities(PanelActor $actor): PanelCapabilities
    {
        return $this->overviews->read($actor)->overview->capabilities ?? PanelCapabilities::none();
    }

    /**
     * A text field as the browser sent it. The application's middleware trims input and turns an empty one into null,
     * which would take a file's last line end or leading spaces; the form's own body keeps them.
     */
    private static function rawText(Request $request, string $field, ?string $within = null): string
    {
        if (str_starts_with((string) $request->headers->get('Content-Type'), 'application/x-www-form-urlencoded')) {
            parse_str((string) $request->getContent(), $body);
            $value = $within === null ? ($body[$field] ?? null) : (is_array($body[$within] ?? null) ? ($body[$within][$field] ?? null) : null);
            if (is_string($value)) {
                return $value;
            }
        }

        return (string) $request->input($within === null ? $field : $within.'.'.$field, '');
    }

    /** A file or folder's own name: no slash, not `.` or `..`, no control characters. */
    private static function name(mixed $value): ?string
    {
        $name = is_string($value) ? trim($value) : '';

        return $name === '' || $name === '.' || $name === '..' || strlen($name) > 255 || preg_match('/[\/\x00-\x1f\x7f]/', $name) === 1 ? null : $name;
    }

    private function back(Request $request, string $domain, string $tab, string $reason, ?string $path = null, ?string $op = null): RedirectResponse
    {
        $key = 'larena-env::admin.files.reasons.'.$reason;
        $line = (string) $this->translator->get($key);
        if ($line === $key) {
            $key = 'larena-env::admin.reasons.'.$reason;
            $line = (string) $this->translator->get($key);
            $line = $line !== $key ? $line : (string) $this->translator->get('larena-env::admin.reasons.unknown', ['reason' => $reason]);
        }
        // A text changed meanwhile is opened again as it is now; the person's own text is in the plan they left.
        $folder = $path === null ? '' : ($op === 'write' && $reason === 'changed' ? '&view='.rawurlencode($path) : '&path='.rawurlencode(FilePath::parent($path)));

        return NoticeRedirect::to($request, route('larena.env.admin.site', ['domain' => $domain], false).'?tab='.$tab.($tab === 'files' ? $folder : ''), $line);
    }
}
