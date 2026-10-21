<?php

declare(strict_types=1);

namespace Psc\Support;

use Psc\Db\Database;

/**
 * Offset pagination for portal lists: page from ?page=, a fixed page size, the rows of this page and the total.
 * links() keeps the other query parameters.
 */
final class Paginator
{
    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, string> $query the current query parameters (page is replaced)
     */
    private function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
        private readonly string $path,
        private readonly array $query,
    ) {
    }

    /**
     * Runs "$sql LIMIT/OFFSET" and "SELECT COUNT(*) FROM ($sql)" with the same parameters.
     *
     * @param array<int|string, mixed> $params
     * @param array<string, mixed> $query
     */
    public static function query(Database $db, string $sql, array $params, array $query, string $path, int $perPage = 25): self
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $total = (int) $db->value('SELECT COUNT(*) FROM (' . $sql . ') AS counted', $params);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $items = $db->all($sql . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $params);
        $clean = [];
        foreach ($query as $k => $v) {
            if (is_string($k) && is_string($v) && $k !== 'page') {
                $clean[$k] = $v;
            }
        }
        return new self($items, $total, $page, $perPage, $path, $clean);
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function url(int $page): string
    {
        $q = $this->query;
        if ($page > 1) {
            $q['page'] = (string) $page;
        }
        return $this->path . ($q !== [] ? '?' . http_build_query($q) : '');
    }

    /** The pager markup (docs/PORTAL-UI.md: pt-pager), empty when everything fits on one page. */
    public function links(): string
    {
        if ($this->pages() <= 1) {
            return '';
        }
        $out = '<nav class="pt-pager" aria-label="Pages">';
        if ($this->page > 1) {
            $out .= '<a rel="prev" href="' . e($this->url($this->page - 1)) . '">Previous</a>';
        }
        $out .= '<span class="pt-pager-info">Page ' . $this->page . ' of ' . $this->pages() . '</span>';
        if ($this->page < $this->pages()) {
            $out .= '<a rel="next" href="' . e($this->url($this->page + 1)) . '">Next</a>';
        }
        return $out . '</nav>';
    }
}
