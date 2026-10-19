<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

trait PaginatesApiResults
{
    protected function paginateApiQuery(Request $request, $query, array $columns = ['*'], int $defaultPerPage = 25, string $pageParameter = 'page'): ?array
    {
        if (!$request->hasAny([$pageParameter, 'per_page'])) return null;

        $perPage = min(100, max(1, $request->integer('per_page', $defaultPerPage)));
        /** @var LengthAwarePaginator $page */
        $page = $query->paginate($perPage, $columns, 'page', max(1, $request->integer($pageParameter, 1)))->appends($request->query());

        return [
            'data' => $page->items(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ];
    }
}
