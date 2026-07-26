<?php

namespace App\Services;

use App\Contracts\Repositories\CostHistoryRepositoryInterface;
use App\Contracts\Repositories\CostSnapshotRepositoryInterface;
use App\DTOs\CostHistoryDTO;
use App\DTOs\CostSnapshotDTO;
use App\Models\CostHistory;
use App\Models\CostSnapshot;
use App\Validation\CostHistoryValidator;
use App\Validation\CostSnapshotValidator;
use Illuminate\Support\Facades\DB;

class CostingService
{
    public function __construct(
        private CostSnapshotRepositoryInterface $snapshots,
        private CostHistoryRepositoryInterface $history,
    ) {
    }

    public function snapshot(array $data): CostSnapshot
    {
        $valid = CostSnapshotValidator::validate($data);
        $dto = CostSnapshotDTO::fromArray($valid);
        return DB::transaction(fn () => $this->snapshots->create($dto->toArray()));
    }

    public function recordHistory(array $data): CostHistory
    {
        $valid = CostHistoryValidator::validate($data);
        $dto = CostHistoryDTO::fromArray($valid);
        return DB::transaction(fn () => $this->history->create($dto->toArray()));
    }
}
