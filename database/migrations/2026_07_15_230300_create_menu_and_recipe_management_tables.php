<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menu & Recipe Management (architecture doc 01 FR-2). Additive schema that
 * builds on the existing Sales catalog (products / product_variants /
 * categories / modifiers) rather than duplicating it. Adds menu presentation
 * (rich descriptions, meal sizes, media), the recipe/BOM engine with
 * versioning, ingredient & semi-finished stock, costing (food cost / yield /
 * waste), nutrition & allergens, and the automatic inventory-consumption
 * ledger. Money is integer minor units; quantities decimal(18,6); ratios in
 * basis points (1 bps = 0.01%).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Menu presentation fields on the existing catalog tables.
        Schema::table('categories', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('name');
            $table->string('image_url', 1024)->nullable()->after('description');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('name');
            $table->boolean('is_meal')->default(false)->after('is_sellable');
            $table->unsignedInteger('calories')->nullable()->after('is_meal');
            $table->json('nutrition_summary')->nullable()->after('calories');
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            // A variant doubles as a meal size (e.g. Small / Medium / Large).
            $table->string('meal_size', 32)->nullable()->after('name');
            $table->unsignedInteger('calories')->nullable()->after('meal_size');
        });

        // Rich media (images & videos) attachable to any catalog entity.
        Schema::create('media_assets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('entity_type', 32); // product, product_variant, category
            $table->ulid('entity_id');
            $table->string('kind', 16)->default('image'); // image, video
            $table->string('url', 1024);
            $table->string('thumbnail_url', 1024)->nullable();
            $table->string('alt_text')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->string('status', 24)->default('active');
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'entity_type', 'entity_id', 'sort_order'], 'ix_media_entity_order');
        });

        // Ingredients / raw stock items, valued per stock unit.
        Schema::create('stock_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('sku', 96);
            $table->string('name');
            $table->string('kind', 24)->default('ingredient'); // ingredient, packaging
            $table->string('stock_unit', 24); // g, ml, unit, kg, l
            $table->unsignedBigInteger('unit_cost_amount')->default(0); // minor units per stock_unit
            $table->string('currency', 3);
            $table->unsignedInteger('default_waste_bps')->default(0); // expected trim/waste ratio
            $table->unsignedInteger('calories_per_unit')->nullable();
            $table->json('nutrition')->nullable();
            $table->string('status', 24)->default('active');
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'sku']);
            $table->index(['tenant_id', 'kind', 'status']);
        });

        // Per-branch stock levels for ingredients & semi-finished products.
        Schema::create('stock_levels', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->string('stockable_type', 32); // stock_item, semi_finished_product
            $table->ulid('stockable_id');
            $table->decimal('quantity_on_hand', 18, 6)->default(0);
            $table->decimal('reorder_level', 18, 6)->default(0);
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'branch_id', 'id']);
            $table->unique(['tenant_id', 'branch_id', 'stockable_type', 'stockable_id'], 'uq_stock_levels_stockable');
        });

        // Semi-finished products (sauces, doughs) produced from a recipe and
        // consumed by other recipes; also stockable.
        Schema::create('semi_finished_products', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('sku', 96);
            $table->string('name');
            $table->string('yield_unit', 24); // g, ml, unit
            $table->decimal('yield_quantity', 18, 6)->default(1); // batch output
            $table->unsignedInteger('calories_per_unit')->nullable();
            $table->json('nutrition')->nullable();
            $table->string('status', 24)->default('active');
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'sku']);
            $table->index(['tenant_id', 'status']);
        });

        // A recipe is owned by exactly one producible: either a sellable
        // product variant or a semi-finished product. It is a header; the
        // costed/nutrition detail lives in an immutable version.
        Schema::create('recipes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('owner_type', 32); // product_variant, semi_finished_product
            $table->ulid('owner_id');
            $table->string('name');
            $table->foreignUlid('active_version_id')->nullable(); // set once a version is activated
            $table->string('status', 24)->default('active');
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'owner_type', 'owner_id'], 'uq_recipes_owner');
            $table->index(['tenant_id', 'status']);
        });

        // An immutable, versioned snapshot of a recipe: its components, costed
        // and nutrition figures, and lifecycle (draft -> published -> active ->
        // archived). Exactly one version per recipe is active at a time.
        Schema::create('recipe_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('recipe_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('revision');
            $table->string('state', 24)->default('draft'); // draft, published, active, archived
            $table->decimal('yield_quantity', 18, 6)->default(1); // portions the batch produces
            $table->string('yield_unit', 24)->default('portion');
            $table->unsignedInteger('waste_bps')->default(0); // overall production waste ratio
            // Costed roll-up, captured at publish time (minor units).
            $table->unsignedBigInteger('ingredient_cost_amount')->default(0);
            $table->unsignedBigInteger('recipe_cost_amount')->default(0); // per yield unit incl. waste
            $table->string('currency', 3)->nullable();
            // Nutrition roll-up per yield unit.
            $table->unsignedInteger('calories')->nullable();
            $table->json('nutrition')->nullable();
            $table->text('instructions')->nullable();
            $table->foreignUlid('published_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'recipe_id', 'revision'], 'uq_recipe_versions_revision');
            $table->index(['tenant_id', 'recipe_id', 'state'], 'ix_recipe_versions_state');
        });

        // BOM line: a version consumes a stock item or a semi-finished product.
        Schema::create('recipe_components', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('recipe_version_id')->constrained()->restrictOnDelete();
            $table->string('component_type', 32); // stock_item, semi_finished_product
            $table->ulid('component_id');
            $table->decimal('quantity', 18, 6); // per full recipe batch
            $table->string('unit', 24);
            $table->unsignedInteger('waste_bps')->default(0); // per-line trim/waste
            $table->unsignedBigInteger('unit_cost_amount')->default(0); // snapshot at publish
            $table->unsignedBigInteger('line_cost_amount')->default(0); // qty*(1+waste)*unit_cost
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'recipe_version_id', 'component_type', 'component_id'], 'uq_recipe_components_line');
            $table->index(['tenant_id', 'recipe_version_id', 'sort_order'], 'ix_recipe_components_order');
        });

        // Allergen catalog + polymorphic tagging of catalog/stock entities.
        Schema::create('allergens', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->string('icon', 128)->nullable();
            $table->string('status', 24)->default('active');
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('entity_allergens', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('allergen_id')->constrained()->restrictOnDelete();
            $table->string('entity_type', 32); // product, stock_item, semi_finished_product
            $table->ulid('entity_id');
            $table->boolean('is_traces')->default(false); // "may contain traces of"
            $table->timestamps();
            $table->unique(['tenant_id', 'allergen_id', 'entity_type', 'entity_id'], 'uq_entity_allergens');
            $table->index(['tenant_id', 'entity_type', 'entity_id'], 'ix_entity_allergens_entity');
        });

        // Append-only inventory movement ledger (deductions from consumption,
        // additions from production/adjustments). One row per stock change.
        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->string('stockable_type', 32); // stock_item, semi_finished_product
            $table->ulid('stockable_id');
            $table->string('reason', 32); // consumption, production, adjustment, waste
            $table->decimal('quantity_delta', 18, 6); // negative = deduction
            $table->string('unit', 24);
            $table->unsignedBigInteger('unit_cost_amount')->default(0);
            $table->string('reference_type', 32)->nullable(); // order, order_item, recipe_version
            $table->ulid('reference_id')->nullable();
            $table->string('client_operation_id', 128)->nullable(); // offline idempotency
            $table->foreignUlid('actor_id')->nullable();
            $table->ulid('device_id')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'branch_id', 'client_operation_id', 'stockable_type', 'stockable_id'], 'uq_inventory_movements_replay');
            $table->index(['tenant_id', 'branch_id', 'stockable_type', 'stockable_id', 'occurred_at'], 'ix_inventory_movements_stockable');
            $table->index(['tenant_id', 'branch_id', 'reference_type', 'reference_id'], 'ix_inventory_movements_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('entity_allergens');
        Schema::dropIfExists('allergens');
        Schema::dropIfExists('recipe_components');
        Schema::dropIfExists('recipe_versions');
        Schema::dropIfExists('recipes');
        Schema::dropIfExists('semi_finished_products');
        Schema::dropIfExists('stock_levels');
        Schema::dropIfExists('stock_items');
        Schema::dropIfExists('media_assets');
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropColumn(['meal_size', 'calories']);
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['description', 'is_meal', 'calories', 'nutrition_summary']);
        });
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropColumn(['description', 'image_url']);
        });
    }
};
