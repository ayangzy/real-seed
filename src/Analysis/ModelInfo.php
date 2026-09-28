<?php

namespace Ayangzy\RealSeed\Analysis;

final readonly class ModelInfo
{
    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $class
     * @param  array<string, string>  $casts
     * @param  list<RelationInfo>  $relations
     */
    public function __construct(
        public string $class,
        public string $table,
        public string $keyName,
        public string $keyType,
        public bool $incrementing,
        public string $morphClass,
        public array $casts,
        public array $relations,
        public ?string $uniqueIdType = null,
    ) {
    }

    public function shortName(): string
    {
        return class_basename($this->class);
    }
}
