<?php

namespace App\Services;

use App\Contracts\Repositories\RecipeComponentRepositoryInterface;
use App\Contracts\Repositories\RecipeRepositoryInterface;
use App\Contracts\Repositories\RecipeVersionRepositoryInterface;
use App\DTOs\RecipeCloneDTO;
use App\DTOs\RecipeComponentDTO;
use App\DTOs\RecipeVersionDTO;
use App\Models\Recipe;
use App\Models\RecipeComponent;
use App\Models\RecipeVersion;
use App\Validation\RecipeCloneValidator;
use App\Validation\RecipeComponentValidator;
use App\Validation\RecipeVersionValidator;
use Illuminate\Support\Facades\DB;

class RecipeService
{
    public function __construct(
        private RecipeRepositoryInterface $recipes,
        private RecipeVersionRepositoryInterface $versions,
        private RecipeComponentRepositoryInterface $components,
    ) {}

    // Recipe Versions / Serving Size (yield_quantity) / Cooking Loss (waste_bps)
    public function createVersion(Recipe $recipe, array $data): RecipeVersion
    {
        $valid = RecipeVersionValidator::validate($data);
        $dto = RecipeVersionDTO::fromArray($valid);
        return DB::transaction(fn () => $this->versions->createFor($recipe, $dto->toArray()));
    }

    public function updateVersion(RecipeVersion $version, array $data): RecipeVersion
    {
        $valid = RecipeVersionValidator::validate($data);
        $dto = RecipeVersionDTO::fromArray($valid);
        return DB::transaction(fn () => $this->versions->update($version, $dto->toArray()));
    }

    // Recipe Ingredients (raw + prepared) + Department Waste (as per-line waste_bps)
    public function addIngredient(RecipeVersion $version, array $data): RecipeComponent
    {
        $valid = RecipeComponentValidator::validate($data);
        $dto = RecipeComponentDTO::fromArray($valid);
        return DB::transaction(function () use ($version, $dto) {
            return match ($dto->component_type) {
                'stock_item' => $this->components->addItem($version, $dto->component_id, $dto->quantity, $dto->waste_bps, $dto->sort_order),
                'semi_finished_product' => $this->components->addPrepared($version, $dto->component_id, $dto->quantity, $dto->waste_bps, $dto->sort_order),
                'packaging' => $this->components->addPackaging($version, $dto->component_id, $dto->quantity, $dto->waste_bps, $dto->sort_order),
                'modifier_option' => $this->components->addModifier($version, $dto->component_id, $dto->quantity, $dto->waste_bps, $dto->sort_order),
                default => throw new \InvalidArgumentException('Unsupported component_type'),
            };
        });
    }

    public function updateIngredient(RecipeComponent $line, array $data): RecipeComponent
    {
        $valid = RecipeComponentValidator::validate($data);
        $dto = RecipeComponentDTO::fromArray($valid);
        return DB::transaction(fn () => $this->components->updateLine($line, $dto->toArray()));
    }

    public function removeIngredient(RecipeComponent $line): void
    {
        DB::transaction(fn () => $this->components->remove($line));
    }

    // Recipe Approval (publish/activate), Archive, History, Compare
    public function publish(RecipeVersion $version): RecipeVersion
    {
        return DB::transaction(fn () => $this->versions->publish($version));
    }

    public function activate(Recipe $recipe, RecipeVersion $version): Recipe
    {
        return DB::transaction(function () use ($recipe, $version) {
            $this->versions->activate($version);
            return $this->recipes->setActiveVersion($recipe, $version);
        });
    }

    public function deactivate(RecipeVersion $version): RecipeVersion
    {
        return DB::transaction(fn () => $this->versions->deactivate($version));
    }

    public function archive(Recipe $recipe, RecipeVersion $version): Recipe
    {
        // Minimal-risk approach: simply deactivate if active
        return DB::transaction(function () use ($recipe, $version) {
            $this->versions->deactivate($version);
            $active = $this->recipes->activeVersion($recipe);
            if ($active && $active->getKey() === $version->getKey()) {
                $this->recipes->setActiveVersion($recipe, $version); // noop keeps pointer; caller may switch later
            }
            return $recipe->fresh();
        });
    }

    public function history(Recipe $recipe): array
    {
        $list = $this->versions->listByRecipe($recipe);
        return $list->map(fn ($v) => [
            'id' => $v->getKey(),
            'revision' => $v->revision,
            'yield_quantity' => $v->yield_quantity,
            'waste_bps' => $v->waste_bps,
            'published_at' => $v->published_at,
            'activated_at' => $v->activated_at,
        ])->all();
    }

    public function compare(RecipeVersion $a, RecipeVersion $b): array
    {
        $aLines = $a->components()->get()->map(fn($l)=>[$l->component_type,$l->component_id,$l->quantity,$l->waste_bps])->all();
        $bLines = $b->components()->get()->map(fn($l)=>[$l->component_type,$l->component_id,$l->quantity,$l->waste_bps])->all();
        $hash = fn($e)=>$e[0].'#'.$e[1];
        $aMap = []; foreach ($aLines as $e) { $aMap[$hash($e)] = $e; }
        $bMap = []; foreach ($bLines as $e) { $bMap[$hash($e)] = $e; }
        $added = []; $removed = []; $changed = [];
        foreach ($bMap as $k=>$e) {
            if (!isset($aMap[$k])) { $added[]=$e; continue; }
            $ae=$aMap[$k]; if ($ae[2]!=$e[2] || (int)$ae[3]!=(int)$e[3]) { $changed[]=['from'=>$ae,'to'=>$e]; }
        }
        foreach ($aMap as $k=>$e) if (!isset($bMap[$k])) $removed[]=$e;
        return [
            'meta' => [
                'yield' => [$a->yield_quantity, $b->yield_quantity],
                'waste_bps' => [$a->waste_bps, $b->waste_bps],
            ],
            'components' => compact('added','removed','changed'),
        ];
    }

    // Recipe Clone (clone recipe + version + components)
    public function cloneRecipe(array $data): Recipe
    {
        $valid = RecipeCloneValidator::validate($data);
        $dto = RecipeCloneDTO::fromArray($valid);
        return DB::transaction(function () use ($dto) {
            /** @var Recipe $source */
            $source = $this->recipes->findOrFail((string)$dto->source_recipe_id);
            /** @var Recipe $new */
            $new = $this->recipes->create([
                'code' => $dto->new_code ?? ($source->code.'-CLONE'),
                'name_ar' => $dto->new_name_ar ?? ($source->name_ar.' (Clone)'),
                'name_en' => $dto->new_name_en ?? ($source->name_en.' (Clone)'),
                'department_id' => $source->department_id,
                'active' => false,
            ]);
            $active = $this->recipes->activeVersion($source) ?? $source->versions()->latest('created_at')->first();
            if ($active) {
                $cloneV = $this->versions->duplicateWithComponents($active, $new);
                $this->versions->publish($cloneV);
            }
            return $new;
        });
    }
}
