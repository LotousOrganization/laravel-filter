<?php

namespace LotousOrganization\LaravelFilter\Traits;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;

trait Filterable
{
    protected string $filterRequestKey = 'filter';
    protected string $filterAnyRequestKey = 'filter_any';

    public function scopeFilter(Builder $query, Request $request): Builder
    {
        $this->applyFilters(
            $query,
            $request->input($this->getFilterRequestKey(), []),
            'and'
        );

        $this->applyFilters(
            $query,
            $request->input($this->getFilterAnyRequestKey(), []),
            'or'
        );

        return $query;
    }

    protected function applyFilters(Builder $query, array $rawFilters, string $boolean): void
    {
        if (empty($rawFilters) || !is_array($rawFilters)) {
            return;
        }

        $allowedAttributes = $this->getFilterableAttributes();
        $allowedRelationBaseNames = $this->getFilterableRelations();

        $query->where(function (Builder $q) use (
            $rawFilters,
            $allowedAttributes,
            $allowedRelationBaseNames,
            $boolean
        ) {
            foreach ($rawFilters as $filterKey => $value) {
                if (!isset($value) || ($value === '' && $value !== '0' && $value !== 0)) {
                    continue;
                }

                $method = $boolean === 'or' ? 'orWhere' : 'where';

                // relation filter
                if (str_contains($filterKey, '.')) {
                    $baseRelation = explode('.', $filterKey, 2)[0];

                    if (in_array($baseRelation, $allowedRelationBaseNames)) {
                        $q->$method(function (Builder $sub) use ($filterKey, $value) {
                            $this->applyRelationFilter($sub, $filterKey, $value);
                        });
                    }

                    continue;
                }

                // direct filter
                if (in_array($filterKey, $allowedAttributes)) {
                    $q->$method(function (Builder $sub) use ($filterKey, $value) {
                        $this->applyDirectFilter($sub, $filterKey, $value);
                    });
                }
            }
        });
    }

    protected function getFilterRequestKey(): string
    {
        return property_exists($this, 'filterRequestKeyOverride')
            ? $this->filterRequestKeyOverride
            : $this->filterRequestKey;
    }

    protected function getFilterAnyRequestKey(): string
    {
        return property_exists($this, 'filterAnyRequestKeyOverride')
            ? $this->filterAnyRequestKeyOverride
            : $this->filterAnyRequestKey;
    }

    protected function getFilterableAttributes(): array
    {
        return property_exists($this, 'filterable') && is_array($this->filterable)
            ? $this->filterable
            : [];
    }

    protected function getFilterableRelations(): array
    {
        return property_exists($this, 'filterableRelations') && is_array($this->filterableRelations)
            ? $this->filterableRelations
            : [];
    }

    protected function applyDirectFilter(Builder $query, string $filterAttribute, $value): void
    {
        $this->applyWhereConditions($query, $filterAttribute, $value);
    }

    protected function applyRelationFilter(Builder $query, string $relationFilterKey, $value): void
    {
        $parts = explode('.', $relationFilterKey);
        $attributeName = array_pop($parts);
        $relationPath = implode('.', $parts);

        $query->whereHas($relationPath, function (Builder $relationQuery) use ($attributeName, $value) {
            $this->applyWhereConditions($relationQuery, $attributeName, $value);
        });
    }

    protected function applyWhereConditions(Builder $query, string $field, $value): void
    {
        if (is_array($value)) {
            $query->where(function (Builder $subQuery) use ($field, $value) {
                foreach ($value as $operator => $operand) {
                    $operand = $this->decodeValue($operand);

                    match (strtolower($operator)) {
                        'equal', '='        => $subQuery->where($field, '=', $operand),
                        'notequal', '!=', '<>' => $subQuery->where($field, '!=', $operand),
                        'gt', '>'           => $subQuery->where($field, '>', $operand),
                        'gte', '>='         => $subQuery->where($field, '>=', $operand),
                        'lt', '<'           => $subQuery->where($field, '<', $operand),
                        'lte', '<='         => $subQuery->where($field, '<=', $operand),
                        'like'              => $subQuery->where($field, 'like', "%$operand%"),
                        'notlike'           => $subQuery->where($field, 'not like', "%$operand%"),
                        'startswith'        => $subQuery->where($field, 'like', "$operand%"),
                        'endswith'          => $subQuery->where($field, 'like', "%$operand"),
                        'in'                => $subQuery->whereIn($field, (array) $operand),
                        'notin'             => $subQuery->whereNotIn($field, (array) $operand),
                        'between'           => is_array($operand) && count($operand) === 2
                                                ? $subQuery->whereBetween($field, $operand)
                                                : null,
                        'notbetween'        => is_array($operand) && count($operand) === 2
                                                ? $subQuery->whereNotBetween($field, $operand)
                                                : null,
                        'null'              => $subQuery->whereNull($field),
                        'notnull'           => $subQuery->whereNotNull($field),
                        default             => null,
                    };
                }
            });
        } else {
            $query->where($field, 'like', '%' . $this->decodeValue($value) . '%');
        }
    }

    protected function decodeValue($value)
    {
        if (is_array($value)) {
            return array_map(fn ($v) => $this->decodeValue($v), $value);
        }

        return is_string($value) ? urldecode($value) : $value;
    }
}
