<?php

declare(strict_types=1);

namespace Larena\Env\Providers;

use Illuminate\Container\Container;
use Illuminate\Contracts\Container\Container as ContainerContract;
use Larena\Core\Features\FeatureSet;

/**
 * Whether one of the package's features (EnvDeclarations::FEATURES) is on in this product, as core's FeatureSet answers
 * from the product's choice. Without the feature set bound, as in the package's own tests, every one is.
 */
final class EnvFeatures
{
    private function __construct()
    {
    }

    public static function on(string $feature, ?ContainerContract $container = null): bool
    {
        $container ??= Container::getInstance();

        return !$container->bound(FeatureSet::class) || $container->make(FeatureSet::class)->on($feature);
    }

    /** Whether any of the package's features is on: the package's own tables are made only then. */
    public static function any(?ContainerContract $container = null): bool
    {
        foreach (array_keys(EnvDeclarations::FEATURES) as $feature) {
            if (self::on($feature, $container)) {
                return true;
            }
        }

        return false;
    }

    /** @return \Closure(string): bool */
    public static function of(?ContainerContract $container = null): \Closure
    {
        return static fn (string $feature): bool => self::on($feature, $container);
    }
}
