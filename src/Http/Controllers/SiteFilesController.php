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
use Larena\Env\Http\Support\PlanReceipts;
use Larena\Env\Operations\EnvOperationCatalog;
use Larena\Env\Operations\EnvOperations;
use Larena\Env\People\SecretFileAccess;
use Larena\Env\Server\ServerOverviewReader;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A site's files (simai-env 1.39.0–1.40.0) and its own nginx directives (1.41.0). Every change goes the
 * panel's one way: the server says what it would do, a person confirms, and only then it runs as a job. A saved text
 * travels in the request with the sha256 it was read with, so a change made meanwhile is refused, never overwritten.
 * An uploaded file waits on the panel's disk between the plan and the confirmation, named in the person's session by
 * its sha256, and is sent after the confirmed request; it is removed once sent. A download is checked against the size
 * the server named before a byte of it reaches the browser. A removal goes to the site's trash where the server keeps one
 * (simai-env 1.57.0), and is put back from there; on an older server it is final, and the plan says so. Several files are
 * uploaded at once, each planned and sent as its own job. A file with passwords is downloaded only when the person said
 * so, and a picture is shown in place only once its bytes are a picture (PX-92, PX-96). A file with passwords is opened,
 * downloaded and changed only by a person with that right (q-4997ae4e93), refused here and not only hidden on the
 * screen; each download and confirmed change of one is written to «История» without its text.
 */
final readonly class SiteFilesController
{
    /** The changes of the «Файлы» tab and the gateway command of each. */
    public const array FILE_OPERATIONS = ['write' => 'file:write', 'create' => 'file:write', 'upload' => 'file:upload', 'mkdir' => 'file:mkdir',
        'rename' => 'file:rename', 'copy' => 'file:copy', 'remove' => 'file:remove', 'archive' => 'file:archive', 'extract' => 'file:extract',
        'trash-restore' => 'file:trash-restore', 'trash-empty' => 'file:trash-empty'];
    /** The most files one upload sends, each its own plan and job. */
    public const int UPLOAD_MAX_FILES = 20;
    /** The largest picture shown in place: a bigger one is downloaded. */
    public const int PREVIEW_MAX_BYTES = 5_242_880;
    /** The formats file:archive makes, by the name it is given. */
    public const array ARCHIVE_FORMATS = ['zip', 'tar.gz'];
    /** Why packing or unpacking is refused to a person without the right on a server that cannot leave such files out. */
    public const string SECRETS_KEPT = 'secret_files_server_old';
    /** The nginx directives', the system level's only (simai-env 1.41.0); removing them is saving none. */
    public const array OVERLAY_OPERATIONS = ['nginx-save' => 'site:nginx-custom', 'nginx-clear' => 'site:nginx-custom'];

    private const string STAGED = 'larena_env_staged';
    private const int STAGED_SECONDS = 3600;

    public function __construct(
        private EnvOperations $operations,
        private ServerOverviewReader $overviews,
        private SiteFiles $files,
        private Factory $views,
        private Translator $translator,
        private Application $app,
        private ?SecretFileAccess $secrets = null,
        private PlanReceipts $receipts = new PlanReceipts(),
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
        if ($op === 'upload') {
            return $this->planUpload($request, $actor, $domain);
        }
        $args = $this->arguments($request, $op, $domain);
        if ($args === null) {
            return $this->back($request, $domain, $tab, $tab === 'nginx' ? PanelReason::TOO_LARGE : 'path_invalid');
        }
        if ($this->secretRefused($actor, $args)) {
            return $this->back($request, $domain, $tab, SecretFileAccess::REFUSED, $args['path'] ?? null);
        }
        $guarded = $this->withoutSecrets($actor, $op, $domain, $args);
        if (is_string($guarded)) {
            return $this->back($request, $domain, $tab, $guarded, $args['path'] ?? null);
        }
        $args = $guarded;
        $staged = null;
        if (PanelCommands::attachmentLimit($command) !== null) {
            $staged = $this->stage($request, $domain, $command, $op);
            if (is_string($staged)) {
                return $this->back($request, $domain, $tab, $staged);
            }
        }
        $result = $this->operations->plan($actor, EnvOperationCatalog::primaryFor($command) ?? '', $args, $staged['attachment'] ?? null);
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
        $hidden[PlanReceipts::FIELD] = $this->receipts->issue($request, $command, ['op' => $op, 'args' => $args, 'staged' => $staged['id'] ?? null]);

        return $this->views->make('larena-env::admin.plan', [
            'heading' => (string) $this->translator->get('larena-env::admin.files.operations.'.$op),
            'eyebrow' => $domain,
            'target' => $domain,
            'source' => $result->source,
            'plan' => $result->plan,
            'runUrl' => route($tab === 'nginx' ? 'larena.env.admin.site.nginx.run' : 'larena.env.admin.site.files.run', ['domain' => $domain], false),
            'cancelUrl' => route('larena.env.admin.site', ['domain' => $domain], false).'?tab='.$tab,
            'hidden' => $hidden,
            'replaces' => $this->replaces($op, $args, $this->capabilities($actor)->allows('file:trash-list'),
                $this->capabilities($actor)->allows('backup:data-list'), $result->plan->purge),
        ]);
    }

    /**
     * Files dropped or chosen together (PX-91, «загрузка пачкой»): each waits on the panel's disk and is planned by the
     * server on its own; one confirmation sends them all, each as its job. A file the server would refuse (one of the same
     * name, unless replacing was ticked) stops the whole upload before anything is sent, and is named.
     */
    private function planUpload(Request $request, PanelActor $actor, string $domain): mixed
    {
        $folder = FilePath::clean($request->input('folder'));
        $files = array_values(array_filter(\Illuminate\Support\Arr::flatten([$request->file('files') ?? [], $request->file('file') ?? []]),
            static fn (mixed $file): bool => $file instanceof UploadedFile));
        if ($folder === null || $files === [] || count($files) > self::UPLOAD_MAX_FILES) {
            return $this->back($request, $domain, 'files', $files === [] ? 'upload_failed' : (count($files) > self::UPLOAD_MAX_FILES ? 'too_many_files' : 'path_invalid'));
        }
        $expect = $request->input('overwrite') === 'yes' ? 'any' : 'new';
        $planned = [];
        $first = null;
        foreach ($files as $file) {
            $name = self::name($file->getClientOriginalName());
            $args = $name === null ? null : ['domain' => $domain, 'path' => FilePath::join($folder, $name), 'expect-sha256' => $expect];
            if ($args !== null && $this->secretRefused($actor, $args)) {
                $this->unstageAll($request, array_column($planned, 'id'));

                return $this->back($request, $domain, 'files', SecretFileAccess::REFUSED, $args['path'], null, count($files) > 1 ? (string) $name : null);
            }
            $staged = $args === null ? 'path_invalid' : $this->stage($request, $domain, 'file:upload', 'upload', $file, $args);
            if (is_string($staged)) {
                $this->unstageAll($request, array_column($planned, 'id'));

                return $this->back($request, $domain, 'files', $staged, FilePath::join($folder, (string) $name));
            }
            $result = $this->operations->plan($actor, 'env.file.upload', $args, $staged['attachment']);
            if ($result->plan === null) {
                $this->unstageAll($request, [...array_column($planned, 'id'), $staged['id']]);

                return $this->back($request, $domain, 'files', $result->reason ?? PanelReason::ANSWER_SHAPE_INVALID, $args['path'], 'upload',
                    count($files) > 1 ? (string) $name : null);
            }
            $first ??= $result;
            $planned[] = ['id' => $staged['id'], 'name' => (string) $name];
        }
        $hidden = ['op' => 'upload', 'args[domain]' => $domain, 'args[path]' => FilePath::join($folder, $planned[0]['name']), 'args[expect-sha256]' => $expect,
            'staged' => implode(',', array_column($planned, 'id'))];
        $hidden[PlanReceipts::FIELD] = $this->receipts->issue($request, 'file:upload', ['op' => 'upload', 'staged' => $hidden['staged'],
            'args' => ['domain' => $domain, 'path' => $hidden['args[path]'], 'expect-sha256' => $expect]]);
        $names = array_column($planned, 'name');
        $keeps = $this->capabilities($actor)->allows('backup:data-list');

        return $this->views->make('larena-env::admin.plan', [
            'heading' => count($planned) === 1 ? (string) $this->translator->get('larena-env::admin.files.operations.upload')
                : (string) $this->translator->get('larena-env::admin.files.upload_many', ['count' => (string) count($planned)]),
            'eyebrow' => $domain,
            'target' => $domain,
            'source' => $first->source,
            'plan' => $first->plan,
            'runUrl' => route('larena.env.admin.site.files.run', ['domain' => $domain], false),
            'cancelUrl' => route('larena.env.admin.site', ['domain' => $domain], false).'?tab=files&path='.rawurlencode($folder),
            'hidden' => $hidden,
            'replaces' => count($planned) === 1 ? $this->replaces('upload', ['path' => FilePath::join($folder, $names[0]), 'expect-sha256' => $expect], false, $keeps)
                : (string) $this->translator->get('larena-env::admin.files.upload_into', ['folder' => $folder === '.' ? '/' : $folder.'/', 'names' => implode(', ', $names)])
                    .($expect === 'any' ? ' '.(string) $this->translator->get('larena-env::admin.files.'.($keeps ? 'upload_replaces_trash' : 'upload_replaces')) : ''),
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
        // A saved text goes back as it was planned, its spaces and last line end kept, an empty one too.
        if ($command === 'file:write' || $command === 'site:nginx-custom') {
            $args['content'] = str_replace("\r\n", "\n", self::rawText($request, 'content', 'args'));
        }
        if ($command === null || ($args['domain'] ?? null) !== $domain) {
            return $this->back($request, $domain, $tab, PanelReason::NOT_ALLOWED);
        }
        // Only the change the person saw planned, with these values and this file: its receipt, once.
        if (!$this->receipts->confirms($request, $command, ['op' => $op, 'args' => $args, 'staged' => (string) $request->input('staged')])) {
            // A file waiting for this confirmation is let go: the plan is made again with it.
            $this->unstageAll($request, array_filter(explode(',', (string) $request->input('staged'))));

            return $this->back($request, $domain, $tab, PlanReceipts::REFUSED);
        }
        // The confirmation is an address of its own: the right is asked again, never taken from the plan's page.
        if ($this->secretRefused($actor, $args)) {
            return $this->back($request, $domain, $tab, SecretFileAccess::REFUSED, $args['path'] ?? null);
        }
        // Packing, unpacking and a trash restore are guarded again here, whatever the form sent.
        $guarded = $this->withoutSecrets($actor, $op, $domain, $args);
        if (is_string($guarded)) {
            return $this->back($request, $domain, $tab, $guarded, $args['path'] ?? null);
        }
        $args = $guarded;
        if ($op === 'upload') {
            return $this->runUpload($request, $actor, $domain, (string) $request->input('staged'));
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
            $started = $this->operations->start($actor, EnvOperationCatalog::primaryFor($command) ?? '', $args, $attachment);
        } finally {
            if ($attachment !== null) {
                $this->unstage($request, $stagedId);
            }
        }
        if ($started->jobId === null) {
            return $this->back($request, $domain, $tab, $started->reason ?? PanelReason::ANSWER_SHAPE_INVALID, $args['path'] ?? null, $op);
        }
        $this->recordSaved($actor, $domain, $args);

        return new RedirectResponse(route('larena.env.admin.job', ['job' => $started->jobId], false));
    }

    /**
     * The confirmed upload: each staged file is sent as its own job, with the path and the expectation it was planned
     * with. One file follows its job; several go back to the folder, their runs in «История».
     */
    private function runUpload(Request $request, PanelActor $actor, string $domain, string $ids): RedirectResponse
    {
        $staged = (array) $request->session()->get(self::STAGED, []);
        $jobs = [];
        $folder = '.';
        foreach (array_slice(array_filter(explode(',', $ids)), 0, self::UPLOAD_MAX_FILES) as $id) {
            $entry = $staged[$id] ?? null;
            $attachment = $this->staged($request, $id, $domain, 'file:upload');
            $args = is_array($entry) && is_array($entry['args'] ?? null) ? $entry['args'] : null;
            if ($attachment === null || $args === null || ($args['domain'] ?? null) !== $domain) {
                $this->unstageAll($request, explode(',', $ids));

                return $this->back($request, $domain, 'files', 'staged_missing');
            }
            if ($this->secretRefused($actor, $args)) {
                $this->unstageAll($request, explode(',', $ids));

                return $this->back($request, $domain, 'files', SecretFileAccess::REFUSED, (string) $args['path']);
            }
            try {
                $started = $this->operations->start($actor, 'env.file.upload', $args, $attachment);
            } finally {
                $this->unstage($request, $id);
            }
            $folder = FilePath::parent((string) $args['path']);
            if ($started->jobId === null) {
                return $this->back($request, $domain, 'files', $started->reason ?? PanelReason::ANSWER_SHAPE_INVALID, (string) $args['path'], 'upload');
            }
            $jobs[] = $started->jobId;
            $this->recordSaved($actor, $domain, $args);
        }
        if ($jobs === []) {
            return $this->back($request, $domain, 'files', 'staged_missing');
        }
        if (count($jobs) === 1) {
            return new RedirectResponse(route('larena.env.admin.job', ['job' => $jobs[0]], false));
        }

        return NoticeRedirect::to($request, route('larena.env.admin.site', ['domain' => $domain], false).'?tab=files&path='.rawurlencode($folder),
            (string) $this->translator->get('larena-env::admin.files.uploaded_many', ['count' => (string) count($jobs)]));
    }

    /**
     * A picture of the site shown in place (PX-96): raster images only, up to 5 MB, by their name and then by their bytes;
     * served as the picture it is, sandboxed, never kept. Anything else is downloaded.
     */
    public function preview(Request $request, string $domain): mixed
    {
        $actor = ResolvePanelActor::of($request);
        $path = FilePath::clean($request->query('path'));
        $type = $path === null ? null : \Larena\Env\Files\FileEntry::imageType($path);
        if ($path !== null && SecretFileAccess::secret($path) && !$this->secretAllowed($actor)) {
            return new \Illuminate\Http\Response((string) $this->translator->get('larena-env::admin.files.reasons.'.SecretFileAccess::REFUSED), 403, ['Cache-Control' => 'no-store']);
        }
        if (!($this->capabilities($actor)->allows('file:download') || $this->capabilities($actor)->allows('file:preview')) || $path === null || $path === '.' || $type === null) {
            return new \Illuminate\Http\Response('', 404, ['Cache-Control' => 'no-store']);
        }
        // simai-env 1.61.0 gives the picture itself in one look (file:preview), refusing what is larger than asked.
        if ($this->capabilities($actor)->allows('file:preview')) {
            $look = $this->files->preview($actor, $domain, $path, self::PREVIEW_MAX_BYTES);
            if (!is_array($look)) {
                return new \Illuminate\Http\Response('', $look === 'too_large' ? 413 : ($look === 'not_previewable' ? 415 : 404), ['Cache-Control' => 'no-store']);
            }
            $bytes = $look['kind'] === 'image' ? $look['content'] : null;
            if ($bytes === null || !self::isPicture($bytes, $type)) {
                return new \Illuminate\Http\Response('', 415, ['Cache-Control' => 'no-store']);
            }

            return self::picture($bytes, $type);
        }
        $stat = $this->files->stat($actor, $domain, $path);
        if (!$stat instanceof \Larena\Env\Files\FileEntry || $stat->type !== 'file' || ($stat->size ?? PHP_INT_MAX) > self::PREVIEW_MAX_BYTES) {
            return new \Illuminate\Http\Response('', $stat instanceof \Larena\Env\Files\FileEntry ? 413 : 404, ['Cache-Control' => 'no-store']);
        }
        $folder = $this->app->storagePath('app/larena-env/downloads');
        if (!is_dir($folder) && !@mkdir($folder, 0o700, true) && !is_dir($folder)) {
            return new \Illuminate\Http\Response('', 503, ['Cache-Control' => 'no-store']);
        }
        $into = $folder.'/'.bin2hex(random_bytes(16));
        try {
            $download = $this->files->download($actor, $domain, $path, $into);
            $bytes = $download->ok() && $download->size <= self::PREVIEW_MAX_BYTES ? (string) file_get_contents($into, false, null, $download->offset) : null;
        } finally {
            @unlink($into);
        }
        if ($bytes === null || !self::isPicture($bytes, $type)) {
            return new \Illuminate\Http\Response('', 415, ['Cache-Control' => 'no-store']);
        }

        return self::picture($bytes, $type);
    }

    /** A picture served as the picture it is, sandboxed, never kept. */
    private static function picture(string $bytes, string $type): \Illuminate\Http\Response
    {
        return new \Illuminate\Http\Response($bytes, 200, [
            'Content-Type' => $type,
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => 'inline',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Whether the bytes are the picture their name says: PNG, JPEG, GIF or WebP by their signature. */
    public static function isPicture(string $bytes, string $type): bool
    {
        return match ($type) {
            'image/png' => str_starts_with($bytes, "\x89PNG\r\n\x1a\n"),
            'image/jpeg' => str_starts_with($bytes, "\xFF\xD8\xFF"),
            'image/gif' => str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a'),
            'image/webp' => str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP',
            default => false,
        };
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
        // Without the right to files with passwords (q-4997ae4e93) nothing is asked of the server, whatever the address says.
        $secret = SecretFileAccess::secret($path);
        if ($secret && !$this->secretAllowed($actor)) {
            return $this->back($request, $domain, 'files', SecretFileAccess::REFUSED, $path);
        }
        // A file with passwords leaves the server only when the person said so on its own page (PX-92).
        if ($secret && $request->query('confirm') !== 'secret') {
            return NoticeRedirect::to($request, route('larena.env.admin.site', ['domain' => $domain], false).'?tab=files&view='.rawurlencode($path),
                (string) $this->translator->get('larena-env::admin.files.secret_ask'));
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
        if ($secret) {
            $this->secrets?->downloaded($actor, $domain, $path);
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
            case 'copy':
                // A copy beside the original under the name given (simai-env 1.57.0 file:copy refuses an existing one).
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
            case 'trash-restore':
                $id = $request->input('id');

                return is_string($id) && preg_match(\Larena\Env\Files\TrashEntry::ID, $id) === 1 ? $args + ['id' => $id] : null;
            case 'trash-empty':
                $id = $request->input('id');
                if ($id === null || $id === '') {
                    return $args;
                }

                return is_string($id) && preg_match(\Larena\Env\Files\TrashEntry::ID, $id) === 1 ? $args + ['id' => $id] : null;
            case 'nginx-save':
                $content = str_replace("\r\n", "\n", self::rawText($request, 'content'));

                return mb_check_encoding($content, 'UTF-8') && SiteFiles::savable($content) ? $args + ['content' => $content] : null;
            case 'nginx-clear':
                return $args + ['content' => ''];
        }

        return null;
    }

    /**
     * Keeps the file an upload sends on the panel's disk until it is confirmed. The session names it with its sha256, so another file cannot be sent in its place.
     *
     * @return array{id: string, attachment: PanelAttachment}|string the staged file, or why it cannot be
     */
    private function stage(Request $request, string $domain, string $command, string $op, ?UploadedFile $upload = null, array $args = []): array|string
    {
        $folder = $this->app->storagePath('app/larena-env/staged');
        if (!is_dir($folder) && !@mkdir($folder, 0o700, true) && !is_dir($folder)) {
            return PanelReason::BAD_REQUEST;
        }
        $this->pruneStaged($request, $folder);
        $id = bin2hex(random_bytes(16));
        $path = $folder.'/'.$id;
        if ($op === 'upload') {
            $file = $upload ?? $request->file('file');
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
        $staged[$id] = ['domain' => $domain, 'command' => $command, 'sha256' => $attachment->sha256, 'at' => time()] + ($args !== [] ? ['args' => $args] : []);
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

    /** @param list<string> $ids */
    private function unstageAll(Request $request, array $ids): void
    {
        foreach ($ids as $id) {
            $this->unstage($request, $id);
        }
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

    /**
     * What the change replaces or deletes, in a sentence. A server that keeps what it replaces (simai-env 1.64.0) says the
     * previous version waits in the site's trash; emptying the trash says how many entries and bytes go for good.
     *
     * @param array<string, string> $args
     * @param array{items: int, bytes: int}|null $purge
     */
    private function replaces(string $op, array $args, bool $trash = false, bool $keeps = false, ?array $purge = null): ?string
    {
        if ($op === 'trash-empty' && $purge !== null) {
            return (string) $this->translator->get('larena-env::admin.files.replaces_purge_counted', ['count' => (string) $purge['items'],
                'size' => \Larena\Env\Server\Bytes::human($purge['bytes'])]);
        }
        $kept = $keeps ? '_trash' : '';
        $key = match ($op) {
            'write' => 'replaces_write'.$kept,
            'upload' => ($args['expect-sha256'] ?? 'new') === 'any' ? 'replaces_upload'.$kept : null,
            // Into the trash where the server keeps one (simai-env 1.57.0); final otherwise.
            'remove' => $trash ? 'replaces_remove_trash' : 'replaces_remove',
            'trash-empty' => isset($args['id']) ? 'replaces_purge' : 'replaces_purge_all',
            'extract' => ($args['overwrite'] ?? 'no') === 'yes' ? 'replaces_extract'.$kept : null,
            'nginx-save', 'nginx-clear' => 'replaces_nginx',
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

    /** Whether the server lists its sites and this domain is not among them; an unread list is not a missing site. */
    private function unknownSite(PanelActor $actor, string $domain): bool
    {
        $sites = $this->overviews->read($actor)->overview?->sites;

        return $sites !== null && !in_array($domain, array_map(static fn (\Larena\Env\Server\SiteSummary $one): string => $one->domain, $sites), true);
    }

    /** Whether this person may open and change files with passwords; without the store, only the whole server may. */
    private function secretAllowed(PanelActor $actor): bool
    {
        return $this->secrets?->allows($actor) ?? $actor->owner === null;
    }

    /**
     * Whether the change touches a file with passwords (its path or where it goes: saved, made, uploaded, renamed, copied,
     * packed or removed) and the person has no right to such files.
     *
     * @param array<string, string> $args
     */
    private function secretRefused(PanelActor $actor, array $args): bool
    {
        return self::secretOf($args) !== null && !$this->secretAllowed($actor);
    }

    /**
     * The files with passwords inside a folder or an archive (q-4997ae4e93, simai-env 1.62.2): a person without the right
     * packs and unpacks with `without-secrets yes`, so such files are left out of an archive and are neither made nor
     * overwritten from one; the job's page names what was left out. The panel adds it on every plan and run, never the form;
     * a server that cannot leave them out (before 1.62.2) refuses packing and unpacking to such a person. A trash entry is
     * put back only where it was (the panel never sends `to`: elsewhere a .env would come back under a name anyone reads),
     * and one with passwords only by a person with the right.
     *
     * @param array<string, string> $args
     * @return array<string, string>|string the arguments to send, or why it is refused
     */
    private function withoutSecrets(PanelActor $actor, string $op, string $domain, array $args): array|string
    {
        if ($op === 'trash-restore') {
            unset($args['to']);
            if (!$this->secretAllowed($actor)) {
                $trash = $this->files->trash($actor, $domain);
                foreach (is_array($trash) ? $trash['entries'] : [] as $entry) {
                    if ($entry->id === ($args['id'] ?? null) && $entry->secret) {
                        return SecretFileAccess::REFUSED;
                    }
                }
            }

            return $args;
        }
        if ($op !== 'archive' && $op !== 'extract') {
            return $args;
        }
        if ($this->secretAllowed($actor)) {
            unset($args['without-secrets']);

            return $args;
        }
        $command = self::FILE_OPERATIONS[$op];

        return $this->capabilities($actor)->takes($command, 'without-secrets') ? ['without-secrets' => 'yes'] + $args : self::SECRETS_KEPT;
    }

    /** @param array<string, string> $args */
    private static function secretOf(array $args): ?string
    {
        foreach (['path', 'to'] as $field) {
            if (is_string($args[$field] ?? null) && $args[$field] !== '' && SecretFileAccess::secret($args[$field])) {
                return $args[$field];
            }
        }

        return null;
    }

    /**
     * A confirmed change of a file with passwords in «История»: who, the site and the file, never its text.
     *
     * @param array<string, string> $args
     */
    private function recordSaved(PanelActor $actor, string $domain, array $args): void
    {
        $file = self::secretOf($args);
        if ($file !== null) {
            $this->secrets?->saved($actor, $domain, $file);
        }
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

    private function back(Request $request, string $domain, string $tab, string $reason, ?string $path = null, ?string $op = null, ?string $name = null): RedirectResponse
    {
        // A site the server has not is said once by the site's page («Сайт не найден»), not also as a file that is gone
        // (critics 2026-11-07).
        if ($this->unknownSite(ResolvePanelActor::of($request), $domain)) {
            return new RedirectResponse(route('larena.env.admin.site', ['domain' => $domain], false));
        }
        $key = 'larena-env::admin.'.($tab === 'nginx' ? 'nginx' : 'files').'.reasons.'.$reason;
        $line = (string) $this->translator->get($key);
        if ($line === $key && $tab === 'nginx') {
            $key = 'larena-env::admin.files.reasons.'.$reason;
            $line = (string) $this->translator->get($key);
        }
        if ($line === $key) {
            $key = 'larena-env::admin.reasons.'.$reason;
            $line = (string) $this->translator->get($key);
            $line = $line !== $key ? $line : (string) $this->translator->get('larena-env::admin.reasons.unknown', ['reason' => $reason]);
        }
        // A file of several uploaded together is named: «report.pdf: Там уже есть…».
        if ($name !== null) {
            $line = $name.': '.$line;
        }
        // A text changed meanwhile is opened again as it is now; the person's own text is in the plan they left.
        $folder = $path === null ? '' : ($op === 'write' && $reason === 'changed' ? '&view='.rawurlencode($path) : '&path='.rawurlencode(FilePath::parent($path)));

        return NoticeRedirect::to($request, route('larena.env.admin.site', ['domain' => $domain], false).'?tab='.$tab.($tab === 'files' ? $folder : ''), $line);
    }
}
