<?php

namespace AISeeder\Analysis;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Finds each model's factory and its states. Factories are only located here, not run:
 * resolving a factory instantiates the class but doesn't evaluate its definition.
 */
final class FactoryAnalyzer
{
    private const NOT_STATES = ['definition', 'configure', 'modelName', 'newModel'];

    /**
     * @param  array<string, ModelInfo>  $models  Keyed by table name.
     * @return array<string, FactoryInfo> Keyed by table name.
     */
    public function analyze(array $models): array
    {
        $factories = [];

        foreach ($models as $table => $model) {
            if (($info = $this->factoryFor($model->class)) !== null) {
                $factories[$table] = $info;
            }
        }

        return $factories;
    }

    private function factoryFor(string $model): ?FactoryInfo
    {
        if (! in_array(HasFactory::class, class_uses_recursive($model), true)) {
            return null;
        }

        try {
            $factory = $model::factory();
        } catch (Throwable) {
            return null; // HasFactory without a resolvable factory class.
        }

        if (! $factory instanceof Factory) {
            return null;
        }

        return new FactoryInfo($model, $factory::class, $this->states($factory::class));
    }

    /**
     * @return list<string>
     */
    private function states(string $factory): array
    {
        $states = [];

        foreach ((new ReflectionClass($factory))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() === Factory::class
                || $method->isStatic()
                || $method->getNumberOfRequiredParameters() > 0
                || in_array($method->getName(), self::NOT_STATES, true)
                || ! is_subclass_of($method->getDeclaringClass()->getName(), Factory::class)) {
                continue;
            }

            $states[] = $method->getName();
        }

        sort($states);

        return $states;
    }
}
