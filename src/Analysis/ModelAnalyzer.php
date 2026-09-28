<?php

namespace Ayangzy\RealSeed\Analysis;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionMethod;
use ReflectionNamedType;
use SplFileObject;
use Throwable;

/**
 * Extracts table mapping, casts, and relationship metadata from models.
 *
 * Relation methods are found the same way Laravel's ModelInspector (model:show) finds
 * them, and invoked on an unsaved instance. Building a relation doesn't query the
 * database, and each method is isolated so one broken relation can't hide the rest.
 */
final class ModelAnalyzer
{
    private const RELATION_METHODS = [
        'hasMany', 'hasManyThrough', 'hasOneThrough', 'belongsToMany', 'hasOne', 'belongsTo',
        'morphOne', 'morphTo', 'morphMany', 'morphToMany', 'morphedByMany',
    ];

    /** @var list<string> */
    private array $warnings = [];

    /**
     * @param  class-string<Model>  $class
     */
    public function analyze(string $class): ?ModelInfo
    {
        try {
            /** @var Model $model */
            $model = new $class;
        } catch (Throwable $e) {
            $this->warnings[] = "Could not instantiate [{$class}]: {$e->getMessage()}";

            return null;
        }

        $traits = class_uses_recursive($model);

        return new ModelInfo(
            class: $class,
            table: $model->getTable(),
            keyName: $model->getKeyName(),
            keyType: $model->getKeyType(),
            incrementing: $model->getIncrementing(),
            morphClass: $model->getMorphClass(),
            casts: array_map(fn ($cast) => is_string($cast) ? $cast : get_debug_type($cast), $model->getCasts()),
            relations: $this->relations($model),
            uniqueIdType: match (true) {
                in_array(HasUuids::class, $traits, true) => 'uuid',
                in_array(HasUlids::class, $traits, true) => 'ulid',
                default => null,
            },
        );
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return list<RelationInfo>
     */
    private function relations(Model $model): array
    {
        $relations = [];

        foreach (get_class_methods($model) as $method) {
            try {
                $reflection = new ReflectionMethod($model, $method);

                if (! $this->looksLikeRelation($reflection)) {
                    continue;
                }

                $relation = $reflection->invoke($model);

                if ($relation instanceof Relation && ($info = $this->describe($method, $relation)) !== null) {
                    $relations[] = $info;
                }
            } catch (Throwable $e) {
                $this->warnings[] = sprintf('Skipped relation [%s::%s]: %s', $model::class, $method, $e->getMessage());
            }
        }

        return $relations;
    }

    private function looksLikeRelation(ReflectionMethod $method): bool
    {
        if ($method->isStatic() || $method->isAbstract() || $method->getNumberOfParameters() > 0
            || $method->getDeclaringClass()->getName() === Model::class) {
            return false;
        }

        $returnType = $method->getReturnType();

        if ($returnType instanceof ReflectionNamedType && is_subclass_of($returnType->getName(), Relation::class)) {
            return true;
        }

        if ($method->getFileName() === false) {
            return false;
        }

        $file = new SplFileObject($method->getFileName());
        $file->seek($method->getStartLine() - 1);
        $code = '';

        while ($file->key() < $method->getEndLine()) {
            $code .= trim($file->current());
            $file->next();
        }

        foreach (self::RELATION_METHODS as $relationMethod) {
            if (str_contains($code, '$this->'.$relationMethod.'(')) {
                return true;
            }
        }

        return false;
    }

    private function describe(string $name, Relation $relation): ?RelationInfo
    {
        // Order matters: MorphTo extends BelongsTo, MorphToMany extends BelongsToMany,
        // and MorphOne/MorphMany extend HasOneOrMany.
        return match (true) {
            $relation instanceof MorphTo => new RelationInfo(
                name: $name,
                type: RelationInfo::MORPH_TO,
                foreignKey: $relation->getForeignKeyName(),
                morphType: $relation->getMorphType(),
            ),
            $relation instanceof BelongsTo => new RelationInfo(
                name: $name,
                type: RelationInfo::BELONGS_TO,
                relatedClass: $relation->getRelated()::class,
                relatedTable: $relation->getRelated()->getTable(),
                foreignKey: $relation->getForeignKeyName(),
                ownerKey: $relation->getOwnerKeyName(),
            ),
            $relation instanceof MorphToMany => new RelationInfo(
                name: $name,
                type: RelationInfo::MORPH_TO_MANY,
                relatedClass: $relation->getRelated()::class,
                relatedTable: $relation->getRelated()->getTable(),
                ownerKey: $relation->getRelatedKeyName(),
                pivotTable: $relation->getTable(),
                foreignPivotKey: $relation->getForeignPivotKeyName(),
                relatedPivotKey: $relation->getRelatedPivotKeyName(),
                morphType: $relation->getMorphType(),
                morphClass: $relation->getMorphClass(),
            ),
            $relation instanceof BelongsToMany => new RelationInfo(
                name: $name,
                type: RelationInfo::BELONGS_TO_MANY,
                relatedClass: $relation->getRelated()::class,
                relatedTable: $relation->getRelated()->getTable(),
                ownerKey: $relation->getRelatedKeyName(),
                pivotTable: $relation->getTable(),
                foreignPivotKey: $relation->getForeignPivotKeyName(),
                relatedPivotKey: $relation->getRelatedPivotKeyName(),
            ),
            $relation instanceof MorphOneOrMany => new RelationInfo(
                name: $name,
                type: $relation instanceof MorphOne ? RelationInfo::MORPH_ONE : RelationInfo::MORPH_MANY,
                relatedClass: $relation->getRelated()::class,
                relatedTable: $relation->getRelated()->getTable(),
                foreignKey: $relation->getForeignKeyName(),
                morphType: $relation->getMorphType(),
                morphClass: $relation->getMorphClass(),
            ),
            $relation instanceof HasOneOrMany => new RelationInfo(
                name: $name,
                type: $relation instanceof HasOne ? RelationInfo::HAS_ONE : RelationInfo::HAS_MANY,
                relatedClass: $relation->getRelated()::class,
                relatedTable: $relation->getRelated()->getTable(),
                foreignKey: $relation->getForeignKeyName(),
            ),
            default => null, // Through relations add no new edges.
        };
    }
}
