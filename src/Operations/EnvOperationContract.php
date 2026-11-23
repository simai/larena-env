<?php

declare(strict_types=1);

namespace Larena\Env\Operations;

use Larena\Env\Gateway\PanelCommands;

/**
 * operations.yaml as the catalog and the gateway's command table make it (one source): `composer run
 * operations:write` writes it, and declarations:check fails when the file says anything else. An operation's input is
 * its command's arguments with the gateway's own patterns, kept as PanelCommands writes them (PHP's), and its `required`;
 * a command that carries a file also takes the file's sha256 and size. A gateway change answers the job it started with
 * its audit chain; its proposal answers the server's plan.
 */
final class EnvOperationContract
{
    public const string SCHEMA = 'larena.operations.contract';

    private const array STRING = ['type' => 'string'];

    private function __construct()
    {
    }

    /** @return array<string, mixed> the whole file */
    public static function document(): array
    {
        $operations = [];
        foreach (EnvOperationCatalog::names() as $name) {
            $operations[] = self::operation($name);
        }

        return [
            'schema' => self::SCHEMA,
            'schema_version' => 1,
            'package' => EnvOperationCatalog::PACKAGE,
            'operations' => $operations,
        ];
    }

    /** @return array<string, mixed> */
    public static function operation(string $name): array
    {
        return [
            'name' => $name,
            'execution_mode' => 'sync',
            'risk' => 'external',
            'reversible' => EnvOperationCatalog::reversible($name),
            'access_scope' => EnvOperationCatalog::right($name),
            'audit_event' => EnvOperationCatalog::auditEvent($name),
            'transports' => ['local'],
            'input' => self::input($name),
            'output' => self::output($name),
            'receipt' => self::receipt($name),
        ];
    }

    /** @return array<string, mixed> */
    private static function input(string $name): array
    {
        $command = EnvOperationCatalog::command($name);
        if ($command === null) {
            return self::dnsInput($name);
        }
        $rules = PanelCommands::rules($command) ?? ['args' => [], 'required' => [], 'lists' => []];
        $properties = [];
        foreach ($rules['args'] as $argument => $pattern) {
            $one = ['type' => 'string', 'pattern' => $pattern];
            $properties[$argument] = isset($rules['lists'][$argument]) ? ['type' => 'array', 'items' => $one] : $one;
        }
        $limit = PanelCommands::attachmentLimit($command);
        if ($limit !== null) {
            $properties['attachment'] = ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                'sha256' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
                'size' => ['type' => 'integer', 'minimum' => 0, 'maximum' => $limit],
            ]];
        }
        $schema = ['type' => 'object', 'additionalProperties' => false];
        if ($properties !== []) {
            $schema['properties'] = $properties;
        }
        if ($rules['required'] !== []) {
            $schema['required'] = $rules['required'];
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    private static function dnsInput(string $name): array
    {
        $changes = ['type' => 'array', 'items' => ['type' => 'object']];

        return match ($name) {
            'env.dns.connect' => ['type' => 'object', 'additionalProperties' => false,
                'properties' => ['token' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200]], 'required' => ['token']],
            'env.dns.disconnect' => ['type' => 'object', 'additionalProperties' => false],
            'env.site.dns_point' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                'domain' => ['type' => 'string', 'maxLength' => 253], 'changes' => $changes, 'plan_id' => self::STRING,
            ], 'required' => ['domain', 'changes']],
            default => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                'changes' => $changes, 'undo_of' => self::STRING, 'plan_id' => self::STRING,
            ], 'required' => ['changes']],
        };
    }

    /** @return array<string, mixed> */
    private static function output(string $name): array
    {
        if (EnvOperationCatalog::command($name) === null) {
            return ['type' => 'object', 'properties' => match ($name) {
                'env.dns.connect' => ['zones' => ['type' => 'integer', 'minimum' => 0]],
                'env.dns.disconnect' => ['disconnected' => ['type' => 'boolean']],
                default => ['batch' => self::STRING, 'changes' => ['type' => 'integer', 'minimum' => 0]],
            }];
        }
        $fields = $name === 'env.job.cancel'
            ? ['who', 'on_behalf_of', 'operation', 'reversible', 'command', 'job_id', 'state']
            : ['who', 'on_behalf_of', 'operation', 'reversible', 'command', 'request_id', 'plan_id', 'plan_operation_id', 'job_id', 'operation_id'];

        return ['type' => 'object', 'properties' => array_fill_keys($fields, self::STRING)];
    }

    /** @return array<string, mixed> */
    private static function receipt(string $name): array
    {
        $fields = EnvOperationCatalog::command($name) === null
            ? ['operation' => self::STRING, 'reversible' => self::STRING, 'changes' => ['type' => 'integer', 'minimum' => 0]]
            : ['operation' => self::STRING, 'reversible' => self::STRING, 'command' => self::STRING, 'plan_id' => self::STRING,
                'plan_operation_id' => self::STRING, 'expires_at' => self::STRING];

        return ['type' => 'object', 'properties' => $fields];
    }
}
