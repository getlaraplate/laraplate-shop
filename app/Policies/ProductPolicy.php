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
 * request, and a permission that does not exist is not held. The policy and the data layer therefore never disagree.
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
