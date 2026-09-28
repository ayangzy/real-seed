<?php

namespace Ayangzy\RealSeed\Analysis;

final readonly class FactoryInfo
{
    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @param  class-string<\Illuminate\Database\Eloquent\Factories\Factory>  $factory
     * @param  list<string>  $states  Public state methods that take no required arguments.
     */
    public function __construct(
        public string $model,
        public string $factory,
        public array $states,
    ) {
    }
}
