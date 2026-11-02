<?php

namespace App\Console\Commands;

use App\Domain\Storage\Condition;
use App\Domain\Storage\ContractStatus;
use App\Domain\Storage\GarageSetState;
use App\Domain\Storage\SetMover;
use App\Domain\Storage\SetStatus;
use App\Domain\Support\Normalizer;
use App\Domain\Support\NumberGenerator;
use App\Models\Client;
use App\Models\GarageSet;
use App\Models\Location;
use App\Models\StorageCell;
use App\Models\StorageContract;
use App\Models\WheelSet;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Миграция текущих хранений из CSV (этап 5 плана). Экспортируйте Excel в CSV (UTF-8, разделитель «;»).
 *
 * Колонки: number;accepted_at;store_code;client_name;phone;plate;make;model;qty;tire_size;with_rims;condition;season;address;paid_until
 * address — XX_YY_ZZ (размещён) или пусто (лежит в буфере магазина).
 */
class ImportLegacy extends Command
{
    protected $signature = 'storage:import {file} {--dry-run : Только проверить файл}';

    protected $description = 'Импорт текущих хранений из CSV (номер договора сохраняется, бирки не перепечатываются)';

    public function handle(SetMover $mover, NumberGenerator $numbers): int
    {
        $path = $this->argument('file');
        if (! is_readable($path)) {
            $this->error("Файл не найден: {$path}");

            return self::FAILURE;
        }
        $fh = fopen($path, 'r');
        $header = array_map(fn ($h) => trim(preg_replace('/^\xEF\xBB\xBF/', '', $h)), fgetcsv($fh, 0, ';'));
        $ok = 0;
        $errors = [];
        $line = 1;
        $maxNumber = 0;
        while (($row = fgetcsv($fh, 0, ';')) !== false) {
            $line++;
            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $r = array_combine($header, array_pad($row, count($header), ''));
            try {
                DB::transaction(function () use ($r, $mover) {
                    $this->importRow($r, $mover);
                    if ($this->option('dry-run')) {
                        throw new DryRun;
                    }
                });
                $ok++;
            } catch (DryRun) {
                $ok++;
            } catch (\Throwable $e) {
                $errors[] = "Строка {$line}: ".$e->getMessage();
            }
            if (ctype_digit((string) ($r['number'] ?? ''))) {
                $maxNumber = max($maxNumber, (int) $r['number']);
            }
        }
        fclose($fh);
        if (! $this->option('dry-run') && $maxNumber > 0) {
            $numbers->ensureAtLeast('contract', $maxNumber); // новые номера продолжат старую нумерацию
        }
        $this->info(($this->option('dry-run') ? 'Проверено' : 'Импортировано').": {$ok}");
        foreach ($errors as $err) {
            $this->warn($err);
        }

        return $errors ? self::FAILURE : self::SUCCESS;
    }

    private function importRow(array $r, SetMover $mover): void
    {
        $number = trim($r['number'] ?? '');
        if ($number === '' || StorageContract::where('number', $number)->exists()) {
            throw new \RuntimeException("пустой или повторный номер договора «{$number}»");
        }
        $store = Location::where('code', trim($r['store_code'] ?? ''))->firstOrFail();
        $phone = Normalizer::phone($r['phone'] ?? null);
        $client = ($phone ? Client::where('phone', $phone)->first() : null)
            ?? Client::create(['full_name' => trim($r['client_name'] ?: 'Без имени'), 'phone' => $phone, 'consent_pd' => true]);
        // У старых договоров авто может быть не заполнено — дозаполняется при выдаче или следующем приёме
        $vehicle = $client->vehicles()->firstOrCreate(
            ['plate' => Normalizer::plate($r['plate'] ?? null)],
            ['make' => $r['make'] ?: null, 'model' => $r['model'] ?: null]
        );
        $size = Normalizer::tireSize($r['tire_size'] ?? '');
        $condition = mb_strtoupper(trim($r['condition'] ?? '')) === 'НОВЫЕ' || $r['condition'] === 'new' ? Condition::New : Condition::Used;
        $withRims = in_array(mb_strtolower(trim($r['with_rims'] ?? '')), ['1', 'да', 'yes', 'true', 'с дисками'], true);
        $qty = (int) ($r['qty'] ?: 4);
        $acceptedAt = ! empty($r['accepted_at']) ? Carbon::parse($r['accepted_at']) : now();

        $contract = StorageContract::create([
            'number' => $number, 'client_id' => $client->id, 'vehicle_id' => $vehicle->id, 'store_id' => $store->id,
            'accepted_at' => $acceptedAt, 'paid_until' => $r['paid_until'] ?: null,
            'storage_until' => $r['paid_until'] ?: $acceptedAt->copy()->addMonths(6)->toDateString(),
            'status' => ContractStatus::Open, 'notes' => 'Импорт из старого учёта',
        ]);
        // При импорте каждое хранение сразу становится комплектом в гараже клиента
        $garage = GarageSet::create([
            'vehicle_id' => $vehicle->id, 'title' => ($r['season'] ?: 'Комплект').' '.$size, 'qty' => $qty,
            'tire_size' => $size, 'with_rims' => $withRims, 'condition' => $condition,
            'state' => GarageSetState::InStorage, 'last_contract_id' => $contract->id,
        ]);
        $address = trim($r['address'] ?? '');
        $cell = $address !== '' ? StorageCell::where('address', $address)->first() : null;
        if ($address !== '' && ! $cell) {
            throw new \RuntimeException("ячейка {$address} не заведена");
        }
        $set = WheelSet::create([
            'garage_set_id' => $garage->id, 'contract_id' => $contract->id, 'qr_code' => $number, 'qty' => $qty,
            'tire_size' => $size, 'with_rims' => $withRims, 'condition' => $condition,
            'status' => $cell ? SetStatus::Placed : SetStatus::Accepted,
            'current_cell_id' => $cell?->id, 'current_location_id' => $cell?->location_id ?? $store->id,
        ]);
        $mover->log($set, 'accept', null, $set->status, null, ['qty' => $qty, 'note' => 'Импорт из старого учёта']);
    }
}

class DryRun extends \RuntimeException {}
