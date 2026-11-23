<?php

declare(strict_types=1);

namespace Larena\Env\Operations;

use Closure;
use Larena\Core\Contracts\OperationDeclaration;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Contracts\OperationProvider;
use Larena\Core\Enums\OperationExecutionMode;
use Larena\Core\Enums\OperationRiskClass;
use Larena\Core\Exceptions\OperationDeclarationInvalid;
use Larena\Core\Registry\OperationDeclarationLoader;

/**
 * The panel's contribution to Core's operation registry, the way storage contributes its own: declarations from this
 * package's operations.yaml, descriptors from EnvOperationCatalog, and the registry refuses a pair that disagrees
 * (DescriptorPolicy). Only the operations of the features that are on are given: env.dns's come with env.dns alone.
 */
final readonly class EnvOperationProvider implements OperationProvider
{
    /** @param (Closure(string): bool)|null $featureOn */
    public function __construct(
        private ?Closure $featureOn = null,
        private ?string $declarationPath = null,
        private OperationDeclarationLoader $loader = new OperationDeclarationLoader(),
    ) {
    }

    /**
     * @return list<array{declaration: OperationDeclaration, descriptor: OperationDescriptor, handler_ref: string}>
     */
    public function operations(): array
    {
        $operations = [];
        foreach ($this->loader->loadFile($this->path(), EnvOperationCatalog::PACKAGE) as $declaration) {
            if (!EnvOperationCatalog::has($declaration->name)) {
                throw new OperationDeclarationInvalid('declared_without_descriptor', $this->path(),
                    $declaration->name.' is declared but EnvOperationCatalog does not name it');
            }
            if ($this->featureOn !== null && !($this->featureOn)(EnvOperationCatalog::feature($declaration->name))) {
                continue;
            }
            $operations[] = [
                'declaration' => $declaration,
                'descriptor' => self::descriptor($declaration->name),
                'handler_ref' => EnvOperationCatalog::handlerRef($declaration->name),
            ];
        }

        return $operations;
    }

    /** The operation bound to its handler, with the gateway command it is carried out by. */
    public static function descriptor(string $name): OperationDescriptor
    {
        $command = EnvOperationCatalog::command($name);

        return new OperationDescriptor(
            name: $name,
            executionMode: OperationExecutionMode::Sync,
            accessScope: EnvOperationCatalog::right($name),
            auditEvent: EnvOperationCatalog::auditEvent($name),
            metadata: array_filter([
                'gateway_command' => $command,
                'reversibility' => EnvOperationCatalog::reversibility($name),
                'feature' => EnvOperationCatalog::feature($name),
            ], static fn (?string $value): bool => $value !== null),
            riskClass: OperationRiskClass::External,
            reversible: EnvOperationCatalog::reversible($name),
            inputSchemaRef: EnvOperationCatalog::PACKAGE.':operations.yaml#'.$name.'.input',
            outputSchemaRef: EnvOperationCatalog::PACKAGE.':operations.yaml#'.$name.'.output',
            receiptSchemaRef: EnvOperationCatalog::PACKAGE.':operations.yaml#'.$name.'.receipt',
        );
    }

    public function path(): string
    {
        return $this->declarationPath ?? dirname(__DIR__, 2).'/operations.yaml';
    }
}
