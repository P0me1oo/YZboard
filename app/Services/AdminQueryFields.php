<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AdminQueryFields
{
    public static function validate(Request $request, array $fields, array $filterOnlyFields = []): void
    {
        $request->validate([
            'filter' => 'sometimes|array|list|max:50', 'filter.*.id' => ['required', 'string', Rule::in([...$fields, ...$filterOnlyFields])],
            'filter.*.value' => 'present', 'filter.*.logic' => 'sometimes|in:and,or',
            'sort' => 'sometimes|array|list|max:10', 'sort.*.id' => ['required', 'string', Rule::in($fields)],
            'sort.*.desc' => 'required|boolean',
        ]);
        foreach ($request->input('filter', []) as $index => $filter) {
            $value = $filter['value'];
            if (is_array($value) && (count($value) > 1000 || array_filter($value, fn ($item) => !is_scalar($item) && $item !== null))) {
                throw ValidationException::withMessages(["filter.{$index}.value" => '筛选值不正确']);
            }
        }
    }
}
