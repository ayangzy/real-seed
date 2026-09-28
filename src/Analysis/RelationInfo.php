<?php

namespace AISeeder\Analysis;

final readonly class RelationInfo
{
    public const BELONGS_TO = 'belongsTo';
    public const HAS_ONE = 'hasOne';
    public const HAS_MANY = 'hasMany';
    public const BELONGS_TO_MANY = 'belongsToMany';
    public const MORPH_TO = 'morphTo';
    public const MORPH_ONE = 'morphOne';
    public const MORPH_MANY = 'morphMany';
    public const MORPH_TO_MANY = 'morphToMany';

    public function __construct(
        public string $name,
        public string $type,
        public ?string $relatedClass = null,
        public ?string $relatedTable = null,
        public ?string $foreignKey = null,
        public ?string $ownerKey = null,
        public ?string $pivotTable = null,
        public ?string $foreignPivotKey = null,
        public ?string $relatedPivotKey = null,
        public ?string $morphType = null,
        public ?string $morphClass = null,
    ) {
    }
}
