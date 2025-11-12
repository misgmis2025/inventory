<?php
declare(strict_types=1);

require_once __DIR__ . '/mongo.php';

class ItemRepository {
    private ?\MongoDB\Database $db;

    public function __construct() {
        $this->db = mongo_db();
    }

    public function available(): bool {
        return $this->db !== null;
    }

    public function findItems(array $filters): array {
        if (!$this->db) return [];
        $col = $this->db->selectCollection('inventory_items');
        $query = [];
        $options = [
            'projection' => [
                'item_name' => 1,
                'category' => 1,
                'model' => 1,
                'quantity' => 1,
                'location' => 1,
                'condition' => 1,
                'status' => 1,
                'date_acquired' => 1,
            ]
        ];
        if (($filters['search_q'] ?? '') !== '') {
            $sq = (string)$filters['search_q'];
            if (!empty($filters['is_admin'])) {
                if (ctype_digit($sq)) {
                    $query['_id'] = (int)$sq;
                }
            } else {
                $query['item_name'] = ['$regex' => $this->regex($sq), '$options' => 'i'];
            }
        }
        if (($filters['status'] ?? '') !== '') { $query['status'] = $filters['status']; }
        if (($filters['category'] ?? '') !== '') { $query['category'] = $filters['category']; }
        if (($filters['condition'] ?? '') !== '') { $query['condition'] = $filters['condition']; }
        if (($filters['supply'] ?? '') !== '') {
            $sup = $filters['supply'];
            if ($sup === 'low') { $query['quantity'] = ['$lt' => 10]; }
            elseif ($sup === 'average') { $query['quantity'] = ['$gt' => 10, '$lt' => 50]; }
            elseif ($sup === 'high') { $query['quantity'] = ['$gt' => 50]; }
        }
        $dateFrom = $filters['date_from'] ?? '';
        $dateTo = $filters['date_to'] ?? '';
        if ($dateFrom !== '' || $dateTo !== '') {
            $range = [];
            if ($dateFrom !== '') { $range['$gte'] = $dateFrom; }
            if ($dateTo !== '') { $range['$lte'] = $dateTo; }
            $query['date_acquired'] = $range;
        }
        $options['sort'] = ['category' => 1, 'item_name' => 1, '_id' => 1];
        $cursor = $col->find($query, $options);
        $items = [];
        foreach ($cursor as $doc) {
            $it = $doc;
            $it['id'] = $doc['_id'];
            unset($it['_id']);
            $items[] = $it;
        }
        return $this->postFilterItems($items, $filters);
    }

    public function getCategoryNames(): array {
        if (!$this->db) return [];
        $col = $this->db->selectCollection('categories');
        $names = [];
        foreach ($col->find([], ['projection' => ['name' => 1], 'sort' => ['name' => 1]]) as $doc) {
            $nm = trim((string)($doc['name'] ?? ''));
            if ($nm !== '') { $names[] = $nm; }
        }
        return $names;
    }

    public function getBorrowHistory(int $limit = 500): array {
        if (!$this->db) return [];
        $borrows = $this->db->selectCollection('user_borrows');
        $items = $this->db->selectCollection('inventory_items');
        $users = $this->db->selectCollection('users');
        $pipeline = [
            ['$sort' => ['borrowed_at' => -1, '_id' => -1]],
            ['$limit' => $limit],
            ['$lookup' => ['from' => 'inventory_items', 'localField' => 'model_id', 'foreignField' => '_id', 'as' => 'item']],
            ['$unwind' => ['path' => '$item', 'preserveNullAndEmptyArrays' => true]],
            ['$lookup' => ['from' => 'users', 'localField' => 'username', 'foreignField' => 'username', 'as' => 'user']],
            ['$unwind' => ['path' => '$user', 'preserveNullAndEmptyArrays' => true]],
            ['$project' => [
                'username' => 1,
                'borrowed_at' => 1,
                'returned_at' => 1,
                'status' => 1,
                'model_id' => '$model_id',
                'model_name' => ['$ifNull' => ['$item.model', '$item.item_name']],
                'category' => ['$ifNull' => ['$item.category', 'Uncategorized']],
                'full_name' => ['$ifNull' => ['$user.full_name', '$username']],
                'user_id' => '$user.id',
            ]],
        ];
        try {
            $rows = iterator_to_array($borrows->aggregate($pipeline));
            return $rows;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function postFilterItems(array $items, array $filters): array {
        $cat_id_raw = trim((string)($filters['cat_id'] ?? ''));
        $model_id_search_raw = trim((string)($filters['mid'] ?? ''));
        $location_search_raw = trim((string)($filters['loc'] ?? ''));
        $catIdFilters = [];
        $catNameGroups = [];
        if ($cat_id_raw !== '') {
            $groups = preg_split('/\s*,\s*/', strtolower($cat_id_raw));
            foreach ($groups as $g) {
                $g = trim($g);
                if ($g === '') continue;
                $tokens = preg_split('/[\/\\\s]+/', $g);
                $needles = [];
                foreach ($tokens as $p) {
                    $p = trim($p);
                    if ($p === '') continue;
                    if (preg_match('/^(?:cat-)?(\d{1,})$/', $p, $m)) {
                        $num = (int)$m[1];
                        if ($num > 0) { $catIdFilters[] = sprintf('CAT-%03d', $num); }
                        continue;
                    }
                    $needles[] = $p;
                }
                if (!empty($needles)) { $catNameGroups[] = $needles; }
            }
        }
        $catNamesTmp = [];
        foreach ($items as $giTmp) {
            $catTmp = trim((string)($giTmp['category'] ?? '')) !== '' ? $giTmp['category'] : 'Uncategorized';
            $catNamesTmp[$catTmp] = true;
        }
        $catNamesArr = array_keys($catNamesTmp);
        natcasesort($catNamesArr);
        $catNamesArr = array_values($catNamesArr);
        $catIdByName = [];
        for ($i = 0; $i < count($catNamesArr); $i++) {
            $catIdByName[$catNamesArr[$i]] = sprintf('CAT-%03d', $i + 1);
        }
        if (!empty($catIdFilters) || !empty($catNameGroups)) {
            $items = array_values(array_filter($items, function($row) use ($catIdByName, $catIdFilters, $catNameGroups) {
                $cat = trim((string)($row['category'] ?? '')) !== '' ? $row['category'] : 'Uncategorized';
                $cid = $catIdByName[$cat] ?? '';
                if (!empty($catIdFilters) && in_array($cid, $catIdFilters, true)) { return true; }
                if (!empty($catNameGroups)) {
                    foreach ($catNameGroups as $grp) {
                        $all = true;
                        foreach ($grp as $needle) {
                            $needle = trim($needle);
                            if ($needle === '') { continue; }
                            $pat = '/(?<![A-Za-z0-9])' . preg_quote($needle, '/') . '(?![A-Za-z0-9])/i';
                            if (!preg_match($pat, (string)$cat)) { $all = false; break; }
                        }
                        if ($all) { return true; }
                    }
                }
                return false;
            }));
        }
        if ($model_id_search_raw !== '') {
            $idSet = [];
            $nameGroups = [];
            $groups = preg_split('/\s*,\s*/', $model_id_search_raw);
            foreach ($groups as $g) {
                $g = trim($g);
                if ($g === '') continue;
                $tokens = preg_split('/\s+/', $g);
                $groupNeedles = [];
                foreach ($tokens as $t) {
                    $t = trim($t);
                    if ($t === '') continue;
                    if (preg_match('/^\d+$/', $t)) { $idSet[(int)$t] = true; }
                    else { $groupNeedles[] = strtolower($t); }
                }
                if (!empty($groupNeedles)) { $nameGroups[] = $groupNeedles; }
            }
            $items = array_values(array_filter($items, function($row) use ($idSet, $nameGroups) {
                if (!empty($idSet)) {
                    $rid = (int)$row['id'];
                    if (isset($idSet[$rid])) { return true; }
                }
                if (!empty($nameGroups)) {
                    $nm = strtolower((string)($row['item_name'] ?? ''));
                    foreach ($nameGroups as $grp) {
                        $all = true;
                        foreach ($grp as $n) { if ($n !== '' && strpos($nm, $n) === false) { $all = false; break; } }
                        if ($all) { return true; }
                    }
                }
                return false;
            }));
        }
        $location_search_raw = $location_search_raw;
        if (trim($location_search_raw) !== '') {
            $locGroups = [];
            $groups = preg_split('/\s*,\s*/', strtolower($location_search_raw));
            foreach ($groups as $g) {
                $g = trim($g); if ($g === '') continue;
                $tokens = preg_split('/\s+/', $g);
                $needles = [];
                foreach ($tokens as $t) { $t = trim($t); if ($t !== '') { $needles[] = $t; } }
                if (!empty($needles)) { $locGroups[] = $needles; }
            }
            if (!empty($locGroups)) {
                $items = array_values(array_filter($items, function($row) use ($locGroups) {
                    $loc = (string)($row['location'] ?? '');
                    foreach ($locGroups as $grp) {
                        $all = true;
                        foreach ($grp as $n) {
                            if ($n === '') { continue; }
                            $pat = '/(?<![A-Za-z0-9])' . preg_quote($n, '/') . '(?![A-Za-z0-9])/i';
                            if (!preg_match($pat, $loc)) { $all = false; break; }
                        }
                        if ($all) { return true; }
                    }
                    return false;
                }));
            }
        }
        usort($items, function($a, $b) use ($catIdByName) {
            $ca = trim((string)($a['category'] ?? '')) !== '' ? $a['category'] : 'Uncategorized';
            $cb = trim((string)($b['category'] ?? '')) !== '' ? $b['category'] : 'Uncategorized';
            $ida = $catIdByName[$ca] ?? 'CAT-000';
            $idb = $catIdByName[$cb] ?? 'CAT-000';
            return strcmp($idb, $ida);
        });
        return $items;
    }

    private function regex(string $s): string { return preg_quote($s, '/'); }
}
