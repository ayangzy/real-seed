<?php

namespace AISeeder\Database;

use AISeeder\Analysis\ModelInfo;
use AISeeder\Schema\TableSchema;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Encryption\Encrypter;
use UnitEnum;

/**
 * Converts generated values into what the database stores.
 *
 * Bulk inserts bypass Eloquent, so the storage side of model casts is applied here:
 * encrypted casts are encrypted with the app key, arrays become JSON, and dates use
 * the connection's format. Model events and observers intentionally do not fire.
 */
final class RowSerializer
{
    public function __construct(
        private readonly string $dateFormat,
        private readonly ?Encrypter $encrypter = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function serialize(TableSchema $table, ?ModelInfo $model, array $row): array
    {
        foreach ($row as $column => $value) {
            if ($value === null) {
                continue;
            }

            $family = $table->column($column)?->family();

            $value = match (true) {
                $value instanceof DateTimeInterface => match ($family) {
                    'date' => $value->format('Y-m-d'),
                    'time' => $value->format('H:i:s'),
                    'year' => (int) $value->format('Y'),
                    default => $value->format($this->dateFormat),
                },
                $value instanceof BackedEnum => $value->value,
                $value instanceof UnitEnum => $value->name,
                is_array($value) => json_encode($value),
                default => $value,
            };

            $cast = strtolower((string) ($model?->casts[$column] ?? ''));

            if ($this->encrypter !== null && str_starts_with($cast, 'encrypted')) {
                $value = $this->encrypter->encryptString(is_string($value) ? $value : json_encode($value));
            }

            $row[$column] = $value;
        }

        return $row;
    }
}
