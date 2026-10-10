# ADR-0005: POS Inventory Source Location and Nested BOM Physical Consumption Policy

## Status
Accepted

## Context
In Phase 1C, the POS Sales Transaction Core finalizes sales orders and commits inventory deductions directly to the immutable inventory movement ledger. Two architectural risks were identified during the Phase 1C audit:

1. **Client-Controlled Inventory Location Vulnerability**: If the POS finalization endpoint naively trusts a client-supplied `location_id`, a malicious or misconfigured client could deduct inventory from another tenant, another outlet, or a warehouse storage location not designated for POS operations.
2. **Equating Recipe Explosion with Physical Inventory Consumption**: In Food & Beverage operations, a Product Variant frequently relies on nested sub-recipes (e.g., *Chicken Rice Bowl* requires 30g *Chili Sauce*, which is made from *Chili*, *Garlic*, and *Cooking Oil*). If the system blindly explodes recipes to leaf raw materials during checkout:
   - **Mode A (Stocked / Pre-Produced)**: If the kitchen has already pre-cooked 5 kg of Chili Sauce (recorded in inventory under type `semi_finished`), deducting raw chili, garlic, and oil would lead to inventory divergence and double-counting (since the raw ingredients were already consumed when producing the batch).
   - **Mode B (Make-to-Order / Fresh-Prep)**: If the sauce is mixed on-the-fly during order assembly and never held as pre-produced stock, exploding to raw ingredients is necessary.
   - **Double-Consumption Risk**: A catastrophic failure mode where the system simultaneously consumes both the 30g Chili Sauce AND its child raw ingredients (chili, garlic, oil) for the same physical sale.

---

## Architectural Decisions

### 1. Server-Authoritative POS Inventory Location Resolution
* **Outlet Default Location**: The `outlets` table designates a server-authoritative `default_pos_location_id` referencing `inventory_locations(id)`.
* **Database Foreign Key Integrity**: A PostgreSQL composite foreign key constraint (`fk_outlets_pos_location_tenant (tenant_id, default_pos_location_id) REFERENCES inventory_locations(tenant_id, id) ON DELETE SET NULL`) guarantees that an outlet cannot reference an inventory location from another tenant.
* **Deterministic Resolution Hierarchy**: When finalizing a sale:
  1. If the client optionally supplies a `location_id`, the server queries that location within the tenant and verifies `location.outlet_id == sale.outlet_id`.
  2. If the location does not belong to the sale's outlet, the server rejects the request with HTTP 422 `INVENTORY_LOCATION_OUTLET_MISMATCH`.
  3. If no `location_id` is passed, the server attempts to resolve `outlet.default_pos_location_id`.
  4. If `default_pos_location_id` is null, the server resolves the primary active location for that outlet (`type IN ('outlet', 'kitchen')`).
  5. If no active inventory location is found for the outlet, the transaction is rejected with HTTP 422 `NO_POS_INVENTORY_LOCATION_FOR_OUTLET`.
* **Client Trust Boundary**: The client is never trusted to pick an arbitrary location. Cross-tenant, cross-outlet, or unassociated locations are unconditionally rejected.

### 2. Decoupling Recipe Dependency Graph from Physical Consumption
We maintain a strict separation of concerns:
* **Recipe Graph (BOM)**: Represents the theoretical formulation or recipe structure of a product or semi-finished good. `RecipeService::explodeRecipe()` remains a pure, non-mutating calculation of theoretical leaf ingredients and packaging.
* **Physical Consumption Policy**: Governs how physical stock is depleted when a sale occurs, based on the operational readiness of intermediate components.

### 3. Production & Consumption Modes (Mode A vs Mode B)
* **Item-Level Default**: `inventory_items.production_mode` (`VARCHAR(20)`) specifies the default handling:
  - `'stocked'`: Semi-finished goods that are pre-produced in batches, held in stock, and tracked with on-hand quantities and moving weighted average (MWA) valuation.
  - `'make_to_order'`: Components that are assembled or mixed on-demand at the time of sale.
* **Component-Level Override**: `recipe_components.consumption_mode` (`VARCHAR(20)`, nullable) allows a parent recipe to explicitly override the consumption policy for a specific usage (e.g., normally stocked, but made fresh for a specialized dish).
* **Precedence Rule**:
  $$\text{effective\_mode} = \text{component.consumption\_mode} \mathbin{??} \text{item.production\_mode} \mathbin{??} \text{'stocked'}$$

### 4. Zero Double-Consumption Invariant
* **Mode A Enforcement**: When a semi-finished component is resolved as `stocked`, POS deducts the required quantity of the semi-finished item itself from inventory (`movement_type = 'sales_consumption'`). The child recipe is **not** traversed for stock deduction. No child raw materials are consumed.
* **Mode B Enforcement**: When a semi-finished component is resolved as `make_to_order`, POS traverses the child recipe and consumes the child components. The parent semi-finished item is **not** deducted from inventory.
* Under no circumstances may both the parent semi-finished item and its child ingredients be consumed for the same sales line item.

### 5. Outbound Valuation & COGS Accounting
* **Stocked Semi-Finished (Mode A)**:
  $$\text{Line COGS} = \text{consumed\_quantity} \times \text{semi\_finished\_current\_location\_MWA}$$
  The child raw ingredients were already accounted for in the batch production cost when the semi-finished stock was manufactured.
* **Make-to-Order (Mode B)**:
  $$\text{Line COGS} = \sum (\text{child\_consumed\_quantity} \times \text{child\_current\_location\_MWA})$$
  COGS is accumulated from the leaf raw ingredients consumed directly at checkout.

### 6. Historical Traceability & Snapshot Integrity
`sale_items.recipe_snapshot` captures:
* `recipe_version_id`: UUID of the published recipe active at checkout.
* `theoretical_leaves`: Theoretical leaf raw materials from pure BOM explosion (for yield and loss analysis).
* `consumption_plan`: Exact physical consumption breakdown showing:
  - `item_id`, `item_name`, `item_type`, `production_mode`, `consumption_mode`.
  - `quantity`, `uom`.
  - Nested children (if exploded via `make_to_order`).
* Historical audit can definitively explain whether a sale consumed pre-made stock or fresh ingredients, regardless of future recipe revisions or changes to production modes.

---

## Consequences

### Positive
* **Stock Accuracy**: Eliminates phantom deductions of raw ingredients when pre-batched sauces/pastes are sold.
* **Valuation Correctness**: Prevents double-counting COGS on pre-produced items while properly costing make-to-order items.
* **Tenant & Outlet Isolation**: Zero possibility of cashier endpoints drawing stock from unauthorized locations or neighboring branches.
* **Auditability**: Complete transparency in sales records between theoretical recipe requirements and physical inventory consumption.

### Limitations & Guardrails
* Phase 1C focuses exclusively on POS consumption. Manufacturing batch work orders (converting raw materials to stocked semi-finished inventory via kitchen production) will be formalized in the Kitchen/Production module.
