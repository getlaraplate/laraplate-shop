<?php

declare(strict_types=1);

namespace Modules\Shop\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\Rule;
use Modules\CMS\Contracts\ExtendsContent;
use Modules\CMS\Enums\CMSTables;
use Modules\CMS\Models\Concerns\ExtendsContentTrait;
use Modules\CMS\Models\Content;
use Modules\Core\Contracts\IMountsRelatedAttributes;
use Modules\Core\Overrides\Model;
use Modules\Core\Search\Schema\FieldType;
use Modules\ERP\Concerns\BelongsToCompany;
use Modules\Shop\Casts\EntityType;
use Modules\Shop\Database\Factories\ProductFactory;
use Modules\Shop\Enums\ProductKind;
use Modules\Shop\Enums\ShopTables;
use Modules\Shop\Models\Pivot\Presettable;
use Override;

/**
 * Catalog anchor: a commercial object in its own table that extends a CMS {@see Content} through the
 * content-extension seam (E2, E4) and carries its tenant via ERP (E23). It is the first production
 * consumer of the seam.
 *
 * The backing {@see Content} is transparently merged onto the product (E2a): content-owned attributes
 * read and write as if native on the product, mirroring how CMS `Contributor` surfaces its `User`. The
 * seam trait supplies the `content()` relation, the always-loaded `$with`, the temp holder and the
 * first-save bridge; the magic getter/setter and the dirty-content save override below complete the
 * merge. The ERP `Item` link is deliberately NOT merged — it is a later relation via the variant pivot.
 *
 * @property int|string|null $content_id
 * @property ProductKind|null $kind
 * @property bool $is_published_in_shop
 * @property bool $featured
 */
final class Product extends Model implements ExtendsContent, IMountsRelatedAttributes
{
    use BelongsToCompany;
    use ExtendsContentTrait;

    /**
     * The stable morph alias CMS stores on `contents.extended_type` and resolves through the
     * {@see \Modules\CMS\Services\ContentExtenderRegistry}.
     */
    public const string CONTENT_ALIAS = 'shop.product';

    /**
     * The product's own columns. Any other attribute key is content-owned and routed to the merged
     * {@see Content} by {@see self::getAttribute()}/{@see self::setAttribute()} (E2a).
     *
     * @var list<string>
     */
    private const array OWN_COLUMNS = [
        'id',
        'company_id',
        'content_id',
        'kind',
        'is_published_in_shop',
        'featured',
        'release_date',
        'metadata',
        'created_at',
        'updated_at',
        'deleted_at',
        'is_deleted',
    ];

    /**
     * @var string
     */
    #[Override]
    protected $table = ShopTables::Products->value;

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'content_id',
        'kind',
        'is_published_in_shop',
        'featured',
        'release_date',
        'metadata',
    ];

    /**
     * Raised by {@see self::setAttribute()} whenever a content-owned key is written through the product
     * root, so the saving hook flushes the merged {@see Content} even when the write left no dirty
     * column — translatable fields (`title`, `slug`, translatable dynamic fields) buffer in the
     * content's pending translations and never make it dirty.
     */
    private bool $contentDirtyViaMerge = false;

    /**
     * A new, unsaved {@see Content} for a product: attached to the Shop product {@see Entity} and its
     * seeded default preset, never to a new entity. It is staged with {@see self::setTempContent()} and
     * persisted by the seam's first `save()`. Requires the product entity and its default preset to be
     * seeded (`ShopDatabaseSeeder`).
     */
    public static function newBackingContent(): Content
    {
        $entity = Entity::query()->where('type', EntityType::Products)->sole();
        $presettable = Presettable::query()->where('entity_id', $entity->id)->sole();

        $content = new Content();
        // Setting presettable_id syncs entity_id from the presettable (HasDynamicContents), so the
        // product content lands on the seeded Shop product entity without a separate assignment.
        $content->presettable_id = $presettable->id;
        $content->valid_from = now();

        return $content;
    }

    #[Override]
    public function contentAlias(): string
    {
        return self::CONTENT_ALIAS;
    }

    /**
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /**
     * The product's default variant: the single variant flagged `is_default`, kept unique per product
     * by {@see ProductVariant}.
     *
     * @return HasOne<ProductVariant, $this>
     */
    public function defaultVariant(): HasOne
    {
        return $this->hasOne(ProductVariant::class)->where('is_default', true);
    }

    /**
     * The extender's own data merged into the content's search document under the nested `extension`.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function searchableExtension(): array
    {
        return [
            'kind' => $this->kind?->value,
            'is_published_in_shop' => $this->is_published_in_shop,
            'featured' => $this->featured,
        ];
    }

    /**
     * The mapping fragment CMS composes into the `contents` index under the nested `extension` object.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function searchableExtensionMapping(): array
    {
        return [
            'kind' => ['type' => FieldType::Keyword, 'filterable' => true],
            'is_published_in_shop' => ['type' => FieldType::Boolean, 'filterable' => true],
            'featured' => ['type' => FieldType::Boolean, 'filterable' => true],
        ];
    }

    /**
     * Read a content-owned attribute as if it were native on the product (E2a).
     *
     * @param  string  $key
     */
    #[Override]
    public function getAttribute($key): mixed
    {
        if ($this->isNativeKey($key)) {
            return parent::getAttribute($key);
        }

        $content = $this->mergedContent();

        if ($content instanceof Content) {
            return $content->getAttribute($key);
        }

        return parent::getAttribute($key);
    }

    #[Override]
    public function mountedRelation(): string
    {
        return 'content';
    }

    /**
     * Whether `$key` is read from the merged content: the product does not own it (E2a).
     */
    #[Override]
    public function isMountedAttribute(string $key): bool
    {
        return ! $this->isNativeKey($key);
    }

    /**
     * Serialize the merged content at the root, so a client reads `title` or a dynamic field from the
     * product as {@see self::getAttribute()} does, without knowing they live on a separate record. Only
     * keys the product does not own are added, so `id`, `created_at` and the relations stay the product's,
     * and the content serializes through its own rules (hidden keys, translation, expanded dynamic fields).
     * The nested `content` stays for readers that already use it. An unloaded content adds nothing and
     * never triggers a lazy load.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $array = parent::toArray();
        $content = $this->mergedContent();

        if (! $content instanceof Content) {
            return $array;
        }

        foreach ($content->toArray() as $key => $value) {
            if (! array_key_exists($key, $array) && ! $this->isNativeKey((string) $key)) {
                $array[$key] = $value;
            }
        }

        return $array;
    }

    /**
     * Write a content-owned attribute through to the merged content (E2a).
     *
     * @param  string  $key
     * @return $this
     */
    #[Override]
    public function setAttribute($key, $value)
    {
        if ($this->isNativeKey($key)) {
            parent::setAttribute($key, $value);

            return $this;
        }

        $content = $this->mergedContent();

        if ($content instanceof Content) {
            $content->setAttribute($key, $value);
            $this->contentDirtyViaMerge = true;

            return $this;
        }

        parent::setAttribute($key, $value);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function getRules(): array
    {
        $rules = parent::getRules();
        $rules['create'] = array_merge($rules['create'], [
            'content_id' => ['required', 'integer', Rule::exists(CMSTables::Contents->value, 'id')->whereNull('deleted_at')],
            'kind' => ['required', 'string', ProductKind::validationRule()],
            'is_published_in_shop' => ['sometimes', 'boolean'],
            'featured' => ['sometimes', 'boolean'],
            'release_date' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ]);
        $rules['update'] = array_merge($rules['update'], [
            'content_id' => ['sometimes', 'integer', Rule::exists(CMSTables::Contents->value, 'id')->whereNull('deleted_at')],
            'kind' => ['sometimes', 'string', ProductKind::validationRule()],
            'is_published_in_shop' => ['sometimes', 'boolean'],
            'featured' => ['sometimes', 'boolean'],
            'release_date' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ]);

        return $rules;
    }

    /**
     * Persist a merged content whose fields changed through the product root before the product is
     * written (E2a). The seam trait's own `save()` already stages and persists a not-yet-linked
     * content on the first save (flushing its pending translations through the content's own `saved`
     * event), so this only covers the later write-through path — mirroring how `Contributor` persists
     * its dirty `User`. A plain-column write makes the content dirty; a translatable write does not,
     * so {@see self::$contentDirtyViaMerge} carries that intent. The content is saved only when it
     * actually changed, never unconditionally, so a product save never spawns a spurious content
     * version or approval record.
     *
     * A soft delete also cascades down the catalog subtree, variants and their composition rows, so no
     * variant is left live under a trashed product (E23). Both levels are stamped here with the product's
     * own `deleted_at`: bulk updates fire no model events, so the variant hook would not run. A restore
     * revives only the rows carrying that exact stamp, so a variant or row removed on its own earlier
     * stays removed. A force delete is left to the foreign key cascade.
     */
    #[Override]
    protected static function booted(): void
    {
        self::deleted(static function (self $product): void {
            $deleted_at = $product->getRawOriginal('deleted_at');

            // Nothing to stamp on a force delete, or when soft deletes are off and the row was removed.
            if ($product->isForceDeleting() || $deleted_at === null) {
                return;
            }

            // The rows go first: they are matched through their variants, which are still live here.
            VariantItem::query()
                ->whereIn('variant_id', $product->variants()->select('id'))
                ->update(['deleted_at' => $deleted_at]);
            $product->variants()->update(['deleted_at' => $deleted_at]);
        });

        self::restoring(static function (self $product): void {
            // `restoring` fires before the column is cleared, so the stamp of the delete is still there.
            $deleted_at = $product->getRawOriginal('deleted_at');

            if ($deleted_at === null) {
                return;
            }

            $variants = $product->variants()->onlyTrashed()->where('deleted_at', $deleted_at);

            // The rows go first: they are matched through their variants, which are still trashed here.
            VariantItem::query()
                ->onlyTrashed()
                ->where('deleted_at', $deleted_at)
                ->whereIn('variant_id', (clone $variants)->select('id'))
                ->update(['deleted_at' => null]);
            $variants->update(['deleted_at' => null]);
        });

        self::saving(static function (self $product): void {
            // The first save is the trait's job (the relation is not loaded yet and tempContent was
            // already cleared); drop the staging flag here without re-saving the content.
            if ($product->tempContent !== null || ! $product->relationLoaded('content')) {
                $product->contentDirtyViaMerge = false;

                return;
            }

            $content = $product->getRelation('content');

            if ($content instanceof Content && ($content->isDirty() || $product->contentDirtyViaMerge)) {
                $content->save();
            }

            $product->contentDirtyViaMerge = false;
        });
    }

    #[Override]
    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'kind' => ProductKind::class,
            'is_published_in_shop' => 'boolean',
            'featured' => 'boolean',
            'release_date' => 'date',
            'metadata' => 'array',
        ];
    }

    /**
     * Whether the key is one of the product's own attributes (column, accessor/mutator, relation or
     * pivot) rather than a content-owned key routed to the merged content.
     */
    private function isNativeKey(string $key): bool
    {
        return $key === 'pivot'
            || in_array($key, self::OWN_COLUMNS, true)
            || array_key_exists($key, $this->attributes)
            || $this->hasGetMutator($key)
            || $this->hasAttributeMutator($key)
            || method_exists($this, $key);
    }

    /**
     * The merged content: the loaded relation when present, else the content staged for the first
     * save. Never triggers a lazy load, so attribute access on an unretrieved product stays query-free.
     */
    private function mergedContent(): ?Content
    {
        if ($this->relationLoaded('content')) {
            $loaded = $this->getRelation('content');

            if ($loaded instanceof Content) {
                return $loaded;
            }
        }

        return $this->tempContent instanceof Content ? $this->tempContent : null;
    }
}
