<?php

namespace App\Doctrine\Filter;

use App\Entity\Traits\SoftDeletableTrait;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

class SoftDeleteFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, $targetTableAlias): string
    {
        if (!$this->usesSoftDeleteTrait($targetEntity->getName())) {
            return '';
        }

        return sprintf('%s.deleted_at IS NULL', $targetTableAlias);
    }

    private function usesSoftDeleteTrait(string $class): bool
    {
        do {
            if (in_array(SoftDeletableTrait::class, class_uses($class) ?: [], true)) {
                return true;
            }
        } while ($class = get_parent_class($class));

        return false;
    }
}
