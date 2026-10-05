<?php

namespace App\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

/**
 * One page-size rule and one response shape for every paginated admin list (users, roles, permissions,
 * Workspaces, Organizations). Each list reads its own query keys (see keys()), so several lists can live on
 * one page without sharing state.
 */
class ListPagination
{
    public const OPTIONS = [10, 20, 30, 50, 100];

    /** Query keys of a list: the users list keeps the plain `page` / `per_page`, the others are prefixed. */
    public static function keys(string $section): array
    {
        return $section === 'users'
            ? ['page' => 'page', 'size' => 'per_page']
            : ['page' => "{$section}_page", 'size' => "{$section}_per_page"];
    }

    /** Requested page size when it is one of the offered options, otherwise the first option. */
    public static function size(Request $request, string $section): int
    {
        $requested = $request->integer(self::keys($section)['size']);

        return in_array($requested, self::OPTIONS, true) ? $requested : self::OPTIONS[0];
    }

    /** Paginates a query for the section with the requested size and page. */
    public static function paginate($query, Request $request, string $section): LengthAwarePaginator
    {
        $keys = self::keys($section);

        return $query->paginate(self::size($request, $section), ['*'], $keys['page'])->withQueryString();
    }

    /** @return array{current_page: int, last_page: int, per_page: int, total: int, from: int, to: int} */
    public static function meta(LengthAwarePaginator $page): array
    {
        return [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'from' => $page->firstItem() ?? 0,
            'to' => $page->lastItem() ?? 0,
        ];
    }
}
