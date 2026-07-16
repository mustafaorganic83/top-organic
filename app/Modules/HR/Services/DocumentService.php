<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Models\EmployeeDocument;
use App\Modules\HR\Data\HrContext;
use App\Modules\HR\Exceptions\HrException;
use App\Modules\HR\Services\Concerns\GuardsHrWrites;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/** Employee document management (contracts, ID copies, certificates). */
final class DocumentService
{
    use GuardsHrWrites;

    /** @return Collection<int, EmployeeDocument> */
    public function list(HrContext $context, string $employeeId): Collection
    {
        return EmployeeDocument::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where('employee_id', $employeeId)
            ->orderByDesc('created_at')
            ->get();
    }

    public function find(HrContext $context, string $id): EmployeeDocument
    {
        return EmployeeDocument::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->whereKey($id)->first()
            ?? throw HrException::notFound('Document not found.');
    }

    /** @param array<string, mixed> $data */
    public function create(HrContext $context, array $data): EmployeeDocument
    {
        return DB::transaction(function () use ($context, $data): EmployeeDocument {
            $doc = EmployeeDocument::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'branch_id' => $context->branchId,
                'employee_id' => $data['employee_id'],
                'document_type' => $data['document_type'],
                'title' => $data['title'],
                'file_path' => $data['file_path'] ?? null,
                'file_name' => $data['file_name'] ?? null,
                'expiry_date' => $data['expiry_date'] ?? null,
                'lock_version' => 0,
            ]);
            $this->audit($context, 'document', $doc->id, 'created');

            return $doc;
        }, 3);
    }

    public function delete(HrContext $context, string $id): void
    {
        DB::transaction(function () use ($context, $id): void {
            $doc = EmployeeDocument::withoutGlobalScopes()
                ->where('tenant_id', $context->tenantId)
                ->whereKey($id)->first()
                ?? throw HrException::notFound('Document not found.');
            $this->audit($context, 'document', $doc->id, 'deleted');
            $doc->delete();
        }, 3);
    }
}
