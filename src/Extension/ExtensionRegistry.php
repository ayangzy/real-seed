<?php

namespace Ayangzy\RealSeed\Extension;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves the extension classes registered in config/realseed.php.
 */
final class ExtensionRegistry
{
    /** @var array<string, object> */
    private array $instances = [];

    /**
     * @param  array{generators?: array<string, class-string>, row_generators?: array<string, class-string>, reference_pickers?: array<string, class-string>, scenarios?: array<string, class-string>, analyzers?: list<class-string>}  $config
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $config = [],
    ) {
    }

    /**
     * Checks every configured class up front, so a typo fails before any database work.
     *
     * @throws InvalidArgumentException
     */
    public function validate(): void
    {
        $contracts = [
            'generators' => FieldGenerator::class,
            'row_generators' => RowGenerator::class,
            'reference_pickers' => ReferencePicker::class,
            'scenarios' => ScenarioProvider::class,
            'analyzers' => ApplicationAnalyzer::class,
        ];

        foreach ($contracts as $key => $contract) {
            foreach ((array) ($this->config[$key] ?? []) as $name => $class) {
                if (! is_string($class) || ! is_subclass_of($class, $contract)) {
                    throw new InvalidArgumentException(sprintf(
                        '[%s] is registered in config/realseed.php under "%s" but does not implement %s.',
                        is_string($class) ? $class : get_debug_type($class), is_int($name) ? $key : "{$key}.{$name}", $contract,
                    ));
                }
            }
        }
    }

    public function fieldGenerator(string $table, string $column, string $semantic): ?FieldGenerator
    {
        $generators = $this->config['generators'] ?? [];

        foreach (["{$table}.{$column}", "*.{$column}", "semantic:{$semantic}"] as $key) {
            if (isset($generators[$key])) {
                return $this->make($generators[$key], FieldGenerator::class);
            }
        }

        return null;
    }

    public function rowGenerator(string $table): ?RowGenerator
    {
        $class = $this->config['row_generators'][$table] ?? null;

        return $class === null ? null : $this->make($class, RowGenerator::class);
    }

    public function referencePicker(string $table, string $column): ?ReferencePicker
    {
        $class = $this->config['reference_pickers']["{$table}.{$column}"] ?? null;

        return $class === null ? null : $this->make($class, ReferencePicker::class);
    }

    public function scenario(string $name): ?ScenarioProvider
    {
        $class = $this->config['scenarios'][$name] ?? null;

        return $class === null ? null : $this->make($class, ScenarioProvider::class);
    }

    /**
     * @return list<ApplicationAnalyzer>
     */
    public function analyzers(): array
    {
        return array_map(fn (string $class) => $this->make($class, ApplicationAnalyzer::class), array_values($this->config['analyzers'] ?? []));
    }

    /**
     * @template T
     *
     * @param  class-string<T>  $contract
     * @return T
     */
    private function make(string $class, string $contract): object
    {
        if (! is_subclass_of($class, $contract)) {
            throw new InvalidArgumentException("[{$class}] is registered in config/realseed.php but does not implement {$contract}.");
        }

        return $this->instances[$class] ??= $this->container->make($class);
    }
}
