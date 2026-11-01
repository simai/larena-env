<?php

declare(strict_types=1);

namespace Larena\Env\Gateway;

use JsonException;
use Larena\Env\Contracts\PanelGateway;

/**
 * Replays answers recorded from a real gateway, for tests and demos. The folder holds simai-env's recorded files and
 * their manifest (`simai-panel-fixtures/1`); a file that no longer matches its digest is refused, so a change of the
 * format cannot pass unnoticed. A file's name is the request. Nothing here reaches a server.
 */
final readonly class RecordedAnswersGateway implements PanelGateway
{
    private const string FIXTURES_SCHEMA = 'simai-panel-fixtures/1';
    private const string ANSWER_SCHEMA = 'simai-panel-answer/1';

    public function __construct(private string $directory)
    {
    }

    public function source(): string
    {
        return 'recorded';
    }

    public function ask(PanelRequest $request): PanelAnswer
    {
        $owner = $request->actor->owner;
        $domain = $request->args['domain'] ?? null;
        // As the gateway: an owner may not make owners, and a new site is always the owner's own, whatever its domain.
        if ($owner !== null && str_starts_with($request->command, 'owner:')) {
            return $this->replay($request, 'refused.not-allowed.json');
        }
        if ($owner !== null && $domain !== null && $request->command !== 'site:add') {
            $own = $this->document("self-describe.owner-{$owner}.json");
            if (is_string($own)) {
                return $this->unavailable($request, $own);
            }
            $sites = is_array($own['data']['sites'] ?? null) ? $own['data']['sites'] : [];
            if (!in_array($domain, array_column($sites, 'domain'), true)) {
                return $this->replay($request, 'refused.owner-scope.json');
            }
        }

        $answer = $this->replay($request, $this->fileFor($request, $domain));
        // The set holds one job; a question about another one is missing, never answered with this one's.
        $recordedJob = is_string($answer->data['job_id'] ?? null) ? $answer->data['job_id'] : null;
        $askedJob = $request->args['job_id'] ?? null;
        if ($askedJob !== null && $recordedJob !== null && $recordedJob !== $askedJob) {
            return $this->unavailable($request, PanelReason::RECORDING_MISSING);
        }

        return $answer;
    }

    private function fileFor(PanelRequest $request, ?string $domain): string
    {
        $name = str_replace(':', '-', $request->command);
        // A searched log is recorded once, for the search the recording made.
        if ($request->command === 'logs:nginx' && isset($request->args['match'])) {
            return 'logs-nginx.match.json';
        }
        // An action is recorded twice: what it would do, and the start of the one job the recording holds.
        if (PanelCommands::kind($request->command) === PanelCommands::ACTION) {
            return $request->confirm ? 'job-start.json' : "plan.{$name}.json";
        }
        if ($domain !== null) {
            return "{$name}.{$domain}.json";
        }

        return $request->actor->owner === null ? "{$name}.json" : "{$name}.owner-{$request->actor->owner}.json";
    }

    private function replay(PanelRequest $request, string $file): PanelAnswer
    {
        $document = $this->document($file);

        return is_string($document)
            ? $this->unavailable($request, $document)
            : PanelAnswer::fromDocument($request->requestId, $document);
    }

    private function unavailable(PanelRequest $request, string $reason): PanelAnswer
    {
        return PanelAnswer::failed($request->requestId, $reason);
    }

    /**
     * The recorded answer, or the reason it cannot be used.
     *
     * @return array<array-key, mixed>|string
     */
    private function document(string $file): array|string
    {
        $manifest = $this->json('manifest.json');
        if ($manifest === null || ($manifest['schema'] ?? null) !== self::FIXTURES_SCHEMA
            || ($manifest['request_schema'] ?? null) !== PanelRequest::SCHEMA || ($manifest['answer_schema'] ?? null) !== self::ANSWER_SCHEMA) {
            return PanelReason::RECORDING_TAMPERED;
        }
        $digest = $manifest['files'][$file]['sha256'] ?? null;
        if (!is_string($digest)) {
            return PanelReason::RECORDING_MISSING;
        }
        $path = $this->directory.'/'.$file;
        if (!is_file($path) || !hash_equals($digest, (string) hash_file('sha256', $path))) {
            return PanelReason::RECORDING_TAMPERED;
        }
        $document = $this->json($file);

        return $document === null || ($document['schema'] ?? null) !== self::ANSWER_SCHEMA ? PanelReason::RECORDING_TAMPERED : $document;
    }

    /** @return array<array-key, mixed>|null */
    private function json(string $file): ?array
    {
        $bytes = is_file($this->directory.'/'.$file) ? file_get_contents($this->directory.'/'.$file) : false;
        if ($bytes === false) {
            return null;
        }
        try {
            $document = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($document) ? $document : null;
    }
}
