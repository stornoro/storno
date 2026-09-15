<?php

namespace App\Service\Import\Mapper;

/**
 * A mapper that ships its own downloadable CSV template (headers = the keys of
 * getDefaultMapping(), rows = synthetic examples in the platform's layout).
 */
interface TemplateAwareMapperInterface
{
    /**
     * Example rows keyed by source column name (the keys of getDefaultMapping()).
     *
     * @return array<int, array<string, string>>
     */
    public function getTemplateRows(): array;
}
