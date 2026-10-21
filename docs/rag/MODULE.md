# Shop Module: Developer Reference

Online sales channel for Laraplate. Orchestrates CMS content, ERP items and SAO
support into a storefront.

## Status

Catalog foundation plus a backoffice write surface. The module ships the product
catalog core (product, variant, variant composition), the seed of the product
content entity, and the actions and Filament resource that create and edit a
whole product (see «Admin write surface»). It has no stock, cart, checkout or
payment behaviour, and no read or API surface yet (see «Not in this module yet»).

The module is enabled in the committed `modules_statuses.json`. Enabling it registers
`shop.product` as the first production content extender in the CMS
`ContentExtenderRegistry` (see «Seam registration»).

## Boundaries

- **Depends on Core, CMS, ERP and SAO** (one-way: none of them knows Shop).
- Every table is prefixed `shop_` and centralised in `ShopTables`
  (`app/Enums/ShopTables.php`).
- Content belongs to CMS, items, stock and orders belong to ERP, support belongs
  to SAO. Shop composes them and must not duplicate their logic.
- CMS never references a Shop class. The only link is the content-extension seam,
  where Shop registers the opaque alias `shop.product`.

## Catalog model

```
CMS Content  <--1:1-->  Product  --1:n-->  ProductVariant  --1:n-->  VariantItem  -->  ERP Item
 (the body)          shop_products       shop_product_variants      shop_variant_items   erp_items
```

- The **body** of a product (title, slug, description, validity, translations,
  dynamic fields) is a CMS `Content`. Shop does not store it.
- The **`Product`** is the commercial anchor that extends that content.
- A **`ProductVariant`** is the sellable unit. A simple product has one variant.
- A **`VariantItem`** composes a variant from ERP `Item`s: one row is a simple
  product, several rows are a virtual bundle, no row is an item-less variant.
- `ProductKind` (`app/Enums/ProductKind.php`) says what the product is:
  `physical` (ships, stocked, variants resolve to ERP items), `digital`
  (delivered as a download or entitlement, no ERP item) and `display_only`
  (shown in the catalog, not sold through the shop). The kind, not the row
  count, tells an item-less variant apart.

## Product content entity

Shop owns the dynamic-content entity its products are created against (decision
E20). It is deliberately **not** a CMS `EntityType` case.

- `app/Casts/EntityType.php`: the Shop entity types, today only `products`.
- `app/Models/Entity.php`, `Preset.php`, `Pivot/Presettable.php`: Shop
  specializations of Core's abstract `Entity`, `Preset` and `Presettable`. Core
  resolves them by module namespace through `DynamicContentsService`
  (`fetchAvailableEntities(EntityType::Products)` returns the Shop `Entity`, and
  likewise for presets and presettables). `Entity` is scoped to the Shop entity
  types and `Preset` to presets of such entities, so the Shop and CMS entity
  layers do not see each other's rows. `Preset::getRelatedModelClass()` is the CMS
  `Content`: the dynamic content a Shop preset describes is a CMS content.
- `ShopDatabaseSeeder` (`database/seeders`) creates the `product` entity
  (`is_default`) and its `standard` preset, attaches the body field (`content`,
  type `Editor`, translatable, required on the preset) and freezes the preset
  snapshot with `PresetVersioningService`. It is idempotent on the entity **type**:
  an operator renaming or deactivating the entity does not get a second entity and
  preset on the next seed. The `content` field is shared with CMS by name: the
  seeder reuses an existing one (restoring it when soft-deleted) and creates it
  only when none exists, so the order in which Shop and CMS are seeded does not
  matter.
- Storefront classification will be a CMS `Category` and per-category presets
  (attribute sets) come later. Today the product has this single default preset.

To create a product content by hand, create a CMS `Content` with
`presettable_id` set to the Shop product presettable (this also syncs
`entity_id`) and let the product persist it (see «Creating a product»).

## Product

`app/Models/Product.php`, table `shop_products`. Extends Core's `Model` and
implements CMS `ExtendsContent` with `ExtendsContentTrait`. It is the first
production consumer of the content-extension seam (`Modules/CMS/docs/CONTENT_EXTENSION.md`).

Columns:

| Column | Notes |
|--------|-------|
| `company_id` | Tenant, through ERP `BelongsToCompany` (E23): company global scope, `creating` auto-fill from the current company, `company_id` added to `$fillable` |
| `content_id` | NOT NULL and UNIQUE (E16): one extender per content, a product always has a body. FK to CMS `contents`, `cascadeOnDelete` |
| `kind` | `ProductKind`. `physical` is the column default only: the model does not apply it in memory (`(new Product)->kind` is null) and `getRules()` requires `kind` on create |
| `is_published_in_shop` | boolean, default false |
| `featured` | boolean, default false |
| `release_date` | nullable date |
| `metadata` | nullable json, cast to array |

Index `shop_products_company_published_IDX` on `(company_id, is_published_in_shop)`.
Validation lives in `getRules()` (`kind` required on create and bounded to
`ProductKind`, `content_id` must exist in `contents` and not be soft-deleted).

### Seam registration

`ShopServiceProvider::register()` registers `Product::CONTENT_ALIAS`
(`shop.product`) in the CMS `ContentExtenderRegistry`, both when the registry
resolves and, if it already resolved earlier, immediately, so registration does
not depend on provider order. The trait stamps `contents.extended_type` with that
alias on the first save.

Consequences of the seam to keep in mind:

- An extended content is **hidden** from a default `Content` query
  (`HidesExtendedContent` scope) and from generic CMS search. Read products
  through `Product`, or use `Content::withExtended()` to reach the contents.
- `Product::query()` always eager-loads `content` (`$with`). Upcasting a page of
  extended contents with the CMS `ContentExtensionResolver` costs a constant
  number of queries per distinct alias (one for the page, one batched query for the
  extenders), not one per row. That holds for a lean page: the test strips the
  `presettable` and `translation` eager-loads `Content` carries in `$with`. The
  resolver queries `Product` through its company global scope while `Content` is
  not company-scoped, so once a company context is active, upcasting a product
  content of another company throws (it finds no extender row). This is a tracked
  follow-up.
- `$product->content` resolves despite the hide scope: `content()` removes only
  `HidesExtendedContent`, not the soft-delete scope. Once the product is
  soft-deleted its content is trashed with it (E17), so `content` is null and the
  merged reads (E2a) return null.

### Creating a product

The pair is created through the extender, in one transaction, via the seam's
`save()` bridge:

```php
$content = new Content();
$content->presettable_id = $presettable->id; // Shop product presettable
$content->valid_from = now();

$product = new Product(['kind' => ProductKind::Physical]);
$product->setTempContent($content);
$product->save(); // saves the content (stamps extended_type), links it, saves the product
```

`company_id` is NOT NULL: it is auto-filled from the current ERP company on
`creating`, so with no active company it has to be passed explicitly (it is
fillable).

`ProductFactory` does exactly this against the seeded Shop product entity (call
`ShopDatabaseSeeder` first) and adds a default-locale translation to the content.

### Transparent content merge (E2a)

Content-owned attributes read and write as if they were native on the product,
the way CMS `Contributor` surfaces its `User`.

- `getAttribute()` / `setAttribute()` route any key outside the `OWN_COLUMNS`
  whitelist (the product's own columns, timestamps, `deleted_at`, `is_deleted`) to
  the merged `Content`. A key also stays native when it is `pivot`, is already
  present in the product's attribute bag, or is an accessor, a mutator, a relation
  or a method of the product.
- The merged content is the loaded `content` relation, or the content staged by
  `setTempContent()` for a first save. The merge never triggers a lazy load, so
  attribute access on an unretrieved product stays query-free.
- A write through the product root raises the `contentDirtyViaMerge` flag. The
  `saving` hook persists the merged content when it is dirty **or** the flag is
  set. The flag is what carries **translatable** fields (`title`, `slug`,
  translatable dynamic fields): they buffer in the content's pending translations
  and never make the content dirty. A product save with no content write does not
  save the content, so it never spawns a spurious content version or approval.
- The first save is the seam trait's job (it stages and persists the temp
  content); the hook only clears the flag in that case.
- **The body field `content` is the one key the merge does not cover.** The
  product's single seeded dynamic field is named `content` (it reuses the CMS Editor
  `content` field), but on `Product` `content` is also the relation method from
  `ExtendsContentTrait`, so `isNativeKey('content')` is true. `$product->content`
  therefore always returns the `Content` model, never the body value. Read and write
  the body through the merged content: `$product->content->content`. The product
  write actions sidestep the key: `content` is not one of their content fields (see
  «Admin write surface»), and the dynamic fields, the body included, travel as the
  translatable `components` object.

### Lifecycle

Product and content are one unit with a symmetric lifecycle (E17):

- Product soft delete, force delete and restore cascade to the content
  (`ExtendsContentTrait`; restore goes through the content's `reviveInMemory()`
  and `save()`).
- Deleting the content cascades to the product. A shared re-entrancy guard
  (`ContentExtensionCascade`) stops the two directions from looping.
- A hard-deleted content cannot leave a bodiless product: the `content_id` FK
  cascades on delete.

The catalog subtree below the product follows the same lifecycle. The rules live in
the `booted()` hooks of `Product`, `ProductVariant` and `VariantItem`:

- **Soft delete cascades down.** Soft-deleting a `Product` soft-deletes its live
  variants and their live items; soft-deleting a `ProductVariant` soft-deletes its
  live items. No variant is left live under a trashed product, so `$variant->product`
  and the tenant derived from it (E23) resolve for every live variant. The cascade
  runs on the `deleted` event, after the parent row is written, and stamps every
  cascaded row with the parent's own `deleted_at`.
- **Restore is timestamp-matched.** Restoring a product revives only the variants
  and items whose `deleted_at` equals the product's `deleted_at`, that is the rows
  the cascade trashed. A child soft-deleted on its own earlier keeps its own
  timestamp and stays trashed. Restoring a variant does the same for its items.
- **Force delete is the foreign key's job.** `cascadeOnDelete` reaps the whole
  subtree (product to variants to items); the hooks skip a force delete.
- **A child is restored through its parent while the parent is trashed.**
  Restoring a `ProductVariant` whose product is trashed, or a `VariantItem` whose
  variant is trashed, throws a `ValidationException` (keys `product_id` and
  `variant_id`). The parent's restore revives the children in bulk and does not run
  that guard. Once the parent is live, a child that was removed on its own can be
  restored by itself.
- **A restore cannot recreate a duplicate pair.** Restoring a `VariantItem` throws a
  `ValidationException` (key `item_id`) when a different live row already composes
  the same variant with the same item. Together with the previous rule, a parent's
  restore cannot revive a duplicate either: a trashed sibling cannot be restored
  ahead of it.

Developer notes on the cascade:

- **Second precision.** `deleted_at` is stored with second precision. A child
  soft-deleted on its own in the same wall-clock second as its parent's delete
  carries the same timestamp, so the parent's restore revives it. The window is
  narrow (scripts and tests, not a person using the UI) and there is no sub-second
  column to close it.
- **Bulk updates, no child events.** The cascade stamps and clears `deleted_at`
  with bulk updates, so model events do not fire on the children: versioning,
  approvals and search indexing do not see the cascade. It also runs only through
  model `delete()` and `restore()` on the parent. A query-level
  `Product::query()->delete()` (or any bulk soft delete) bypasses it entirely.
- **Not transactional.** The cascade is not wrapped in a transaction with the
  parent write, the same as the cascade to the content in `ExtendsContentTrait`. A
  failure in the cascade leaves a trashed parent with live children.
- **Re-deleting a trashed parent re-stamps it.** Calling `delete()` again on a stale
  instance of an already trashed parent rewrites its `deleted_at`, so the children
  cascaded earlier no longer match and stay trashed when the parent is restored.
  Restore them one by one, parent first.
- **Per-table soft-delete setting.** Core can switch soft deletes off per table. The
  cascade writes `deleted_at` by bulk update and does not consult the child's
  setting. A row deleted on a table with soft deletes off is removed for good, with
  its subtree through the foreign keys, and the hooks skip it (no stamp to
  propagate).

### Search

The seam merges `Product::searchableExtension()` into the content's search
document under the nested `extension` object (`kind`, `is_published_in_shop`,
`featured`; CMS adds a `type` key holding the `shop.product` alias), and `searchableExtensionMapping()` contributes the typed mapping
(keyword and boolean filters). The trait re-sends the content to the index after
every product save, so the document never lags behind the extension.

## ProductVariant

`app/Models/ProductVariant.php`, table `shop_product_variants`. The sellable unit
under a product.

| Column | Notes |
|--------|-------|
| `product_id` | FK to `shop_products`, `cascadeOnDelete`, indexed |
| `is_default` | boolean, default false |
| `attributes` | nullable json, cast to array: the purchase-selection axis (size, colour, ...). Label and projection only, never a source of truth and not relational (E22) |

- **No `company_id`**: the tenant is derived from the product (E23).
- **One default per product** is enforced at the model level, because a partial
  unique index is not portable across the supported drivers. A `saved` hook demotes
  every other variant of the product (trashed ones included, so a restore cannot
  bring back a second default) when the saved variant is the default **and** its
  `is_default` or `product_id` is dirty. It runs on `saved`, not `saving`, because
  Core validates in `creating`/`updating`: a rejected write must not wipe the
  current default. A plain re-save of a stale in-memory default does not demote
  the real default. The demotion is a bulk update, so it fires no model events.
- A product can have **zero defaults** until one is set. A variant starts as
  non-default.
- A variant never changes parent: `product_id` is prohibited on update when it
  is dirty. A variant cannot be created under a trashed (or missing) product, nor
  restored on its own while its product is trashed (see «Lifecycle»).
- `Product::variants()` is the `hasMany`; `Product::defaultVariant()` is the
  `hasOne` filtered on `is_default`.

## VariantItem

`app/Models/VariantItem.php`, table `shop_variant_items`. Composes a variant from
ERP `Item`s (E7b).

| Column | Notes |
|--------|-------|
| `variant_id` | FK to `shop_product_variants`, `cascadeOnDelete`, indexed |
| `item_id` | FK to `erp_items`, `restrictOnDelete`, indexed |
| `quantity` | `decimal(15,4)`, cast `decimal:4`; validated as at most four decimals, minimum `0.0001`, maximum `99999999999.9999` |
| `role` | closed set `main` / `component` (E31): `VariantItem::ROLE_MAIN`, `ROLE_COMPONENT`. A validated string, not an enum |

- `main` is the sole sellable item of a simple product (or the anchor of a
  range), `component` is a constituent of a bundle. Storage accepts a bundle of
  `component` rows and a variant with no row at all. That a simple product has
  exactly one `main` row, and that the item and the product belong to the same
  company, are checkout concerns and are not enforced here.
- No `company_id`: the tenant is derived through the variant and its product.
- **Uniqueness** of `(variant_id, item_id)` among **live** rows is enforced by
  validation (a unique rule scoped to the variant and `deleted_at IS NULL`), not
  by a database index, so a soft-deleted row does not block re-adding the item.
  The rule runs on create and on an item swap (only when the item changes). Core
  skips update validation when only `deleted_at` changes, so a restore is guarded
  separately: a `restoring` hook throws a `ValidationException` when a different live
  row already composes the same variant with the same item. A row also cannot be
  restored on its own while its variant is trashed (see «Lifecycle»).
- A row never moves to another variant. `item_id` must exist and be live (not
  soft-deleted) when a row is created or its item is swapped. `variant_id` must
  exist and not be trashed.
- `restrictOnDelete` on `item_id` guards **hard** deletes only: force-deleting an
  ERP item that still composes a variant fails with a query exception, so a bundle
  component cannot silently vanish. A soft-deleted item stays referenced on
  purpose. A hard-deleted variant removes its composition rows by FK cascade.

### Soft-delete contract for the composition

- `ProductVariant::items()` returns the **full** composition (the
  `VariantItem` rows, soft-deleted rows excluded by the model's soft delete).
- `VariantItem::item()` uses `withTrashed()`: a row always resolves its ERP item,
  even a soft-deleted (discontinued) one, so reading the composition never hits a
  null. Check `$row->item->trashed()` for the item lifecycle. Use this relation
  for writes too.
- `ProductVariant::erpItems()` is the **live-only, purchasable** view: a
  `belongsToMany` through `shop_variant_items` (pivot `quantity`, `role`,
  timestamps) that leaves out soft-deleted items and soft-deleted composition
  rows.

## Admin write surface

A product is created and edited as one aggregate: the `Product`, its CMS `Content`
(through the content-extension seam), its `ProductVariant`s and their `VariantItem`
composition. Three domain actions write it, and the Filament resource is a thin form
over them. The actions are the single write path: the panel and any future caller
(an API, an importer) go through them, never through the models directly.

### Write actions

`app/Actions/Products/`, all `final`, injected through the container.

- **`CreateProductAction::handle(array $data): Product`** builds the aggregate in
  one transaction, derived from the owning model (`$product->getConnection()`).
  `Product::newBackingContent()` returns a new, unsaved `Content` attached to the
  seeded Shop product entity and its default preset (it needs `ShopDatabaseSeeder`
  to have run; it throws when the entity or preset is missing). The action fills it
  with the content fields of the payload, stages it with `setTempContent()` and
  saves the product, so the seam persists and links the content on the first save.
  It then hands the variants to `SyncProductVariantsAction`.
- **`UpdateProductAction::handle(Product $product, array $data): Product`** updates
  the product columns and merges the content fields onto the product root, so the
  product's `saving` hook flushes the content, translatable fields included. Only the
  keys present in the payload are touched. The variants are synced only when the
  payload carries a `variants` key; an absent key leaves them as they are. The tenant
  (`company_id`) never changes. It returns the refreshed product.
- **`SyncProductVariantsAction::handle(Product $product, array $variants): void`**
  brings the variants and their composition in line with the payload, which is the
  desired final state. A variant entry with an `id` updates that variant (an `id`
  that is not one of the product's variants raises a `ValidationException` on
  `variants.{n}.id`), one without creates a new variant, and a live variant that is
  not listed is soft-deleted. Inside a variant the composition rows are matched by
  `item_id`: a listed item is updated or added, an unlisted one is removed, and a
  variant entry without an `items` key leaves its composition alone.

Payload (create; update takes the same shape without `company_id`):

```php
[
    'company_id' => 1,                 // create only; defaults to the current company
    'kind' => 'physical',
    'is_published_in_shop' => true,
    'featured' => false,
    'release_date' => '2026-11-01',
    'metadata' => ['k' => 'v'],
    'content' => ['title' => 'T', 'slug' => 't', 'components' => [], 'valid_from' => '...', 'valid_to' => null, 'order_column' => 1],
    'variants' => [
        ['id' => 7, 'is_default' => true, 'attributes' => ['size' => 'M'],
            'items' => [['item_id' => 3, 'quantity' => '2', 'role' => 'main']]],
    ],
]
```

- **Allowlists.** `CreateProductAction::COLUMNS` (`kind`, `is_published_in_shop`,
  `featured`, `release_date`, `metadata`) bounds the product columns and
  `CreateProductAction::CONTENT_FIELDS` (`title`, `slug`, `components`, `valid_from`,
  `valid_to`, `order_column`) bounds the content fields. Any other key is dropped, so
  content keys are never mass-assigned onto the product. The translatable fields
  (`title`, `slug`, `components`) are written in the current locale.
- **Title.** A create needs a non-blank string `content.title`; an update refuses a
  blank one and leaves an absent one alone. Both raise a `ValidationException` on
  `content.title`. A create derives `slug` from the title when none is given, and so
  does an update that gives the first translation in the current locale.
- **Invariants stay in the models.** The actions do not judge the values: the single
  default variant, the closed `role` set, uniqueness of an item within a variant and
  the soft-delete cascade are enforced by `ProductVariant` and `VariantItem` (see the
  sections above). A rejected write aborts the action's transaction.

### Filament resource

`app/Filament/Resources/Products/`. `ProductResource` (slug `shop/products`, group
`shop.navigation.main`) is registered with the admin panel by `ShopPlugin`, whose
`ModuleFilamentPlugin` trait discovers the module's `Filament/Resources`. The pages
carry no write logic.

- **Create** (`CreateProduct`) is a 4-step wizard built by `Schemas/ProductWizard`:
  Product (columns), Content, Variants, Composition. The Content step reuses the
  fields of the CMS `ContentForm`, read off a schema of their own, so none of the
  entity and preset selects CMS adds applies: a product's content is always created
  on the Shop product entity and its default preset. The submit reshapes the wizard
  state with `ProductForm::createPayload()` and calls `CreateProductAction`.
- **Edit** (`EditProduct`) is tabbed sections over the same four concerns, laid out
  by `Schemas/ProductForm::configure()` from the component lists the wizard also
  uses. The form is filled from the whole aggregate (`ProductForm::stateOf()`: the
  product columns, the merged content fields and the live variants with their
  composition), not from the bare record, and the save reshapes the state with
  `ProductForm::updatePayload()` and calls `UpdateProductAction`. `afterSave()` fills
  the form again from the stored product, so a variant added in the form keeps its id
  and the next save updates it instead of creating it twice. A `ValidationException`
  from the action is shown in a notification and mapped onto the form's field keys.
  The tenant is not editable.
- **Variants and composition** are one piece of form state (`variants`) edited by two
  repeaters: the variants step (hidden `id`, default flag, attributes) and the
  composition step (the rows of each variant: item, quantity, role). The payload is
  refused unless exactly one variant is the default, and the item options are the
  items of the company chosen for the product. The variants can be reordered only
  while creating, the one time the order is stored.
- **Table** (`Tables/ProductsTable`) queries `Product`, not `Content`, so the rows
  are the products themselves: the company global scope (the active company) and the
  soft deletes apply to them. The title is the one of the merged content, with the
  company and the content translation eager-loaded.
  Core's view and edit actions are removed from it, because the edit one opens a modal
  that fills from the bare columns and saves with `$record->update()`, outside the
  action and its transaction. Its edit action is a plain URL to the edit page, visible
  only to who `canEdit`, and runs nothing.
- **Discontinued items.** A composition may reference a soft-deleted ERP `Item`. The
  edit form resolves the item `withTrashed()` and labels it «discontinued», so such a
  product still opens and saves.

### Permissions

`ProductPolicy` (registered in `ShopServiceProvider`) gates the resource pages by the
CRUD permissions of the products table, `{connection}.shop_products.{operation}`
(`default.shop_products.select`, `insert`, `update`, `delete`, `restore`,
`forceDelete`). `permission:refresh` generates them from the models, so the module
declares none. Opening the resource takes `select` (`canAccess()` requires
`canViewAny()`); the create page also takes `insert` and the edit page `update`. A
super admin passes; anybody else needs a grant on the request's guard, and a
permission that does not exist is not held.

The policy gates the pages by the products table only. `ProductVariant` and
`VariantItem` are parts of the product (`IsPartOfParent`), and the model layer checks
the permissions of their own tables (`shop_product_variants`, `shop_variant_items`)
on every write, once those permissions are registered. A user holding only the
`shop_products` permissions opens the pages, but a save that touches a variant is
refused there and rolled back. Failing closed is intended: managing a product takes
the part permissions as well.

No Shop role grants are seeded, so the surface is super-admin-only until an operator
grants the permissions.

### Scope

- **Default preset only.** The content is created on the Shop product entity and its
  default `standard` preset. Storefront categories, per-category presets, facets, the
  browse snapshot and the `/app` read endpoints are not built yet (plan #3).
- **Backoffice only.** Filament is the superadmin backoffice. The merchant-facing
  catalog management experience is left to `laraplate-ui`.

### Known limitation: no aggregate optimistic locking

The product edit does not guard the aggregate against a stale write. `Product` has no
`lock_version`, the edit page takes no lease on the content and does not use the
content stale-guard, and the variant sync writes the desired final state. A save from
a form opened before another admin added a variant therefore removes that variant
silently. This is a known gap, accepted for the superadmin-only backoffice of this
version; locking the aggregate is a feature of its own (see `EditProduct`).

## Not in this module yet

- Stock and availability. They will consume the ERP stock reservation, not
  duplicate it.
- Cart, checkout and payments.
- Digital fulfilment (download grants for `digital` products).
- Reviews, order and delivery read models, customer support through SAO.
- Storefront classification: CMS categories, facets and per-category presets.
- A read or API surface for the catalog: no API resources, `/app` or `/api/v1`
  endpoints (see «HTTP surface»). The write surface is the one described in «Admin
  write surface».
- Merchant-facing catalog management (left to `laraplate-ui`) and optimistic locking
  of the product aggregate.

## Developer caveats

- **Content keys are not fillable.** A product's content-owned fields route only
  through direct property assignment (`$product->title = '...'`) and `forceFill`
  (the one exception is the body field `content`, see «Transparent content merge»:
  write it as `$product->content->content`).
  They do not route through `create([...])` / `update([...])` arrays, because those
  keys are not in `$fillable` and `fill()` does not know about the merge. Core
  sets `Model::preventSilentlyDiscardingAttributes(! isProduction())`
  (`CoreServiceProvider`), so passing a content key in such an array **throws**
  `MassAssignmentException` outside production and is **silently discarded** in
  production. The product write actions do not use mass assignment for content: they
  filter the payload through `CONTENT_FIELDS` and set each key with `setAttribute()`
  (see «Admin write surface»).
- **Entity and preset are not resolved from the content.** For a product
  `Content`, `$content->entity` and `$content->preset` return **null**:
  `$content->presettable` is a CMS `Presettable`, and the CMS `Entity` and
  `Preset` scoping hides the Shop `products` rows. A product-specific accessor for
  the Shop entity and preset comes with the storefront plan. Until then reach them
  through `Shop\Models\Entity` / `Preset` or `DynamicContentsService`.
- **Preset-version migrations must see extended contents.** The seam's global
  scope hides extended contents, so any preset-version migration that walks
  product contents has to go through `Content::withExtended()`.
- **Create a product through the product.** Saving a bare `Content` does not make
  it a product, and `extended_type` is guarded: it is set only by the seam's
  create path.

## HTTP surface

The module has no `/app` or `/api/v1` surface: no controllers, Form Requests or API
resources. `routes/web.php` and `routes/api.php` are empty. The only routes are the
three pages of the Filament product resource under the admin panel
(`admin/shop/products`, `/create` and `/{record}/edit`, named
`filament.admin.resources.shop.products.*`; see «Admin write surface»). The catalog's
read surface comes with a later plan.

## Configuration

`config/config.php` holds only the module name. The module defines no environment
variables and no `shop.*` configuration keys yet.

## Locked decisions

See `docs/superpowers/specs/2026-09-17-shop-module-design.md` (E-series) for the
module design. The ones this foundation implements: E2a (transparent content
merge), E4 (content-extension seam), E7b (variant to item pivot), E16 and E17
(mandatory content, symmetric lifecycle), E20 (Shop-owned product entity), E22
(attributes are labels), E23 (`company_id` on the product, derived below it), E31
(`role` value set). The delivery record is
`docs/superpowers/plans/2026-10-06-shop-catalog-foundation.md`. The admin write
surface is specified in `docs/superpowers/specs/2026-10-20-shop-product-admin-design.md`
and delivered by `docs/superpowers/plans/2026-10-20-shop-product-admin.md`.
