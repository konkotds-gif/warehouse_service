<?php

namespace App\Domain\Warehouse;

use App\Domain\Support\DomainError;
use App\Models\Location;
use App\Models\StorageCell;
use App\Models\StorageRow;
use Illuminate\Support\Facades\DB;

/**
 * Массовое создание рядов и ячеек склада по схеме «ряды × ячейки».
 */
final class CellGenerator
{
    /** @return int количество созданных ячеек */
    public function generate(Location $location, int $rowFrom, int $rowTo, int $cellsPerRow, int $capacity = 1, int $cellFrom = 1): int
    {
        if (! $location->isWarehouse()) {
            throw DomainError::invalid('Ячейки заводятся только на складе (стеллаж магазина заводится как склад со своим кодом XX)');
        }
        if (! preg_match('/^\d{2}$/', $location->code)) {
            throw DomainError::invalid('Код склада должен состоять из двух цифр (XX)');
        }
        if ($rowFrom < 1 || $rowTo > 99 || $rowFrom > $rowTo || $cellsPerRow < 1 || $cellFrom < 1 || $cellFrom + $cellsPerRow - 1 > 99) {
            throw DomainError::invalid('Ряды и ячейки — от 01 до 99');
        }

        return DB::transaction(function () use ($location, $rowFrom, $rowTo, $cellsPerRow, $capacity, $cellFrom) {
            $created = 0;
            for ($r = $rowFrom; $r <= $rowTo; $r++) {
                $rowNo = sprintf('%02d', $r);
                $row = StorageRow::firstOrCreate(['location_id' => $location->id, 'row_no' => $rowNo]);
                for ($c = $cellFrom; $c < $cellFrom + $cellsPerRow; $c++) {
                    $cellNo = sprintf('%02d', $c);
                    $cell = StorageCell::firstOrCreate(
                        ['row_id' => $row->id, 'cell_no' => $cellNo],
                        [
                            'location_id' => $location->id,
                            'address' => StorageCell::makeAddress($location->code, $rowNo, $cellNo),
                            'capacity' => max(1, $capacity),
                        ]
                    );
                    $created += $cell->wasRecentlyCreated ? 1 : 0;
                }
            }

            return $created;
        });
    }
}
