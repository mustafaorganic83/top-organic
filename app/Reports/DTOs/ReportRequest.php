<?php

namespace App\Reports\DTOs;

class ReportRequest
{
    /** @param array<string,mixed> $filters */
    public function __construct(
        public array $filters = [],
        public ?string $groupBy = null,
        public ?string $drillKey = null,
        public ?string $format = 'json', // json, csv, excel, pdf, chart
    ) {}

    public function dateFrom(): ?string { return $this->filters['date_from'] ?? null; }
    public function dateTo(): ?string { return $this->filters['date_to'] ?? null; }
    public function branchId(): ?string { return $this->filters['branch_id'] ?? null; }
    public function warehouseId(): ?string { return $this->filters['warehouse_id'] ?? null; }
    public function departmentId(): ?string { return $this->filters['department_id'] ?? null; }
    public function recipeId(): ?string { return $this->filters['recipe_id'] ?? null; }
    public function itemId(): ?string { return $this->filters['item_id'] ?? null; }
}
