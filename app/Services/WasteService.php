<?php

namespace App\Services;

use App\Contracts\Repositories\WasteRepositoryInterface;
use App\DTOs\WasteDTO;
use App\Models\Waste;
use App\Validation\WasteValidator;
use Illuminate\Support\Facades\DB;

class WasteService
{
    public function __construct(private WasteRepositoryInterface $repo)
    {
    }

    public function log(array $data): Waste
    {
        $valid = WasteValidator::validate($data);
        $dto = WasteDTO::fromArray($valid);
        return DB::transaction(fn () => $this->repo->create($dto->toArray()));
    }

    public function update(Waste $waste, array $data): Waste
    {
        $valid = WasteValidator::validate($data);
        $dto = WasteDTO::fromArray($valid);
        return DB::transaction(fn () => $this->repo->update($waste, $dto->toArray()));
    }

    public function delete(Waste $waste): void
    {
        DB::transaction(fn () => $this->repo->delete($waste));
    }
}
