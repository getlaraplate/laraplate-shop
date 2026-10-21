<?php

declare(strict_types=1);

namespace Modules\Shop\Policies;

use Modules\Core\Casts\ActionEnum;
use Modules\Core\Models\User;
use Modules\Core\Services\Authorization\AuthorizationService;
use Modules\Core\Support\PermissionName;
use Modules\Shop\Models\Product;

/**
 * Authorizes the abilities Filament asks of the product resource (view, create, update, delete, restore and
 * their bulk forms) with the CRUD permissions of the products table: `{connection}.shop_products.{operation}`.
 * `permission:refresh` generates them from the model, so the module declares none of its own; this policy only
 * maps each ability to the operation that guards it, the way {@see \Modules\ERP\Policies\ERPModelPolicy} does.
 *
 * The permission is asked the way the model layer asks it when it reads or writes a product
 * ({@see AuthorizationService::userHolds()}): a super admin passes, anybody else needs a grant on the guard of the
 * request, and a permission that does not exist is not held.
 *
 * The policy gates the pages, and only by the products table. The variants and their composition rows are parts of
 * the product ({@see \Modules\Core\Contracts\IsPartOfParent}), and the model layer checks the permissions of their
 * own tables (`shop_product_variants`, `shop_variant_items`) on every write of them, once those permissions are
 * registered. Managing a product therefore takes the permissions of the variant tables as well: a user holding only
 * those of `shop_products` opens the pages, and a save that touches a variant is refused there and rolled back.
 * Failing closed is intended, so the policy does not repeat the checks of the parts.
 */
final class ProductPolicy
{
    public function __construct(private readonly AuthorizationService $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->allows($user, ActionEnum::Select);
    }

    public function view(User $user, Product $product): bool
    {
        return $this->allows($user, ActionEnum::Select);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, ActionEnum::Insert);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->allows($user, ActionEnum::Update);
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->allows($user, ActionEnum::Delete);
    }

    public function deleteAny(User $user): bool
    {
        return $this->allows($user, ActionEnum::Delete);
    }

    public function restore(User $user, Product $product): bool
    {
        return $this->allows($user, ActionEnum::Restore);
    }

    public function restoreAny(User $user): bool
    {
        return $this->allows($user, ActionEnum::Restore);
    }

    public function forceDelete(User $user, Product $product): bool
    {
        return $this->allows($user, ActionEnum::ForceDelete);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->allows($user, ActionEnum::ForceDelete);
    }

    private function allows(User $user, ActionEnum $operation): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $this->authorization->userHolds($user, PermissionName::forClass(Product::class, $operation->value));
    }
}
