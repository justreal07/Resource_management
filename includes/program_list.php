<?php
/** Database paging for all program lists. Search applies before LIMIT, across the whole list. */
function program_list_filters(array $get, string $page): array
{
    $text = function (string $key) use ($get): string {
        $value = $get[$key] ?? '';
        return is_string($value) ? mb_substr(trim((string)preg_replace('/\s+/u', ' ', $value)), 0, 100) : '';
    };
    $per = filter_var($get['per'] ?? 25, FILTER_VALIDATE_INT);
    $number = filter_var($get['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return [
        'name' => $text('name'), 'q' => $text('q'),
        'source' => $page === 'pending' && in_array($get['source'] ?? '', ['online', 'walkin'], true) ? $get['source'] : '',
        'per' => in_array($per, [10, 25, 50], true) ? $per : 25,
        'page' => $number === false ? 1 : $number,
    ];
}

/** The same conditions drive the count, approved-amount total and displayed rows. */
function program_list_where(int $typeId, string $page, array $filters): array
{
    $statuses = ['pending' => 'Pending', 'approved' => 'Approved', 'disapproved' => 'Disapproved', 'withdrawn' => 'Withdrawn'];
    if (!in_array($typeId, [1, 2, 3], true) || ($page !== 'all' && !isset($statuses[$page]))) {
        throw new InvalidArgumentException('Unknown program list.');
    }
    $where = 'a.assistance_type_id = ?';
    $params = [$typeId];
    if ($page === 'all') {
        $where .= " AND a.status NOT IN ('Disapproved', 'Withdrawn')";
    } else {
        $where .= ' AND a.status = ?';
        $params[] = $statuses[$page];
    }
    if ($page === 'pending' && $filters['source'] !== '') {
        $where .= ' AND a.account_id IS ' . ($filters['source'] === 'online' ? 'NOT ' : '') . 'NULL';
    }
    $name = "LOWER(CONCAT_WS(' ', a.first_name, a.middle_name, a.last_name))";
    $search = "LOWER(CONCAT_WS(' ', CONCAT('#', a.id), a.first_name, a.middle_name, a.last_name, a.barangay, a.contact_number"
        . ($typeId === 2 ? ', a.petitioner_name' : '')
        . ($page === 'disapproved' ? ', a.disapproval_remarks' : '')
        . ($page === 'withdrawn' ? ', a.withdrawal_reason' : '') . '))';
    foreach (['name' => $name, 'q' => $search] as $key => $expression) {
        $words = preg_split($key === 'name' ? '/[\s,.]+/u' : '/\s+/u', mb_strtolower($filters[$key]), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($words as $word) {
            // LOCATE treats percent signs, underscores and quotes as literal text, not SQL wildcards.
            $where .= " AND LOCATE(?, $expression) > 0";
            $params[] = $word;
        }
    }
    return [$where, $params];
}

/** Keep large lists bounded, including Declined and Withdrawn, with a stable order between pages. */
function program_list_load(PDO $pdo, int $typeId, string $page, array $get): array
{
    $filters = program_list_filters($get, $page);
    [$where, $params] = program_list_where($typeId, $page, $filters);
    $sum = $page === 'approved' && $typeId !== 3 ? ', COALESCE(SUM(a.amount), 0) AS amount_total' : '';
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total$sum FROM applicant a WHERE $where");
    $stmt->execute($params);
    $totals = $stmt->fetch(PDO::FETCH_ASSOC);
    $total = (int)$totals['total'];
    $pages = max(1, (int)ceil($total / $filters['per']));
    $filters['page'] = min($filters['page'], $pages);
    $offset = ($filters['page'] - 1) * $filters['per'];

    $select = 'a.*';
    $join = '';
    if ($page === 'pending') {
        $select .= ", acc.email AS account_email, COALESCE(NULLIF(TRIM(u.full_name), ''), u.username) AS encoded_by_name";
        $join = ' LEFT JOIN applicant_accounts acc ON acc.id = a.account_id LEFT JOIN users u ON u.id = a.encoded_by';
    } elseif ($page === 'disapproved') {
        $select .= ", COALESCE(NULLIF(TRIM(u.full_name), ''), u.username) AS disapproved_by_name";
        $join = ' LEFT JOIN users u ON u.id = a.disapproved_by';
    }
    $order = 'a.created_at DESC, a.id DESC';
    if ($typeId === 3 && $page === 'all') $order = 'a.checkup_date IS NULL, a.checkup_date ASC, a.created_at DESC, a.id DESC';
    if ($page === 'disapproved') $order = 'a.disapproved_at DESC, a.id DESC';
    if ($page === 'withdrawn') $order = 'a.withdrawn_at DESC, a.id DESC';
    $stmt = $pdo->prepare("SELECT $select FROM applicant a$join WHERE $where ORDER BY $order LIMIT "
        . $filters['per'] . ' OFFSET ' . $offset);
    $stmt->execute($params);
    return [
        'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total,
        'amount_total' => (float)($totals['amount_total'] ?? 0), 'filters' => $filters, 'pages' => $pages,
        'first' => $total > 0 ? $offset + 1 : 0, 'last' => min($offset + $filters['per'], $total),
    ];
}

/** Preserve only recognized list filters when following a page or returning from an edit. */
function program_list_url(string $path, array $filters, array $changes = []): string
{
    $params = array_intersect_key(array_replace($filters, $changes), array_flip(['name', 'q', 'source', 'per', 'page']));
    $params = array_filter($params, function ($value) { return $value !== '' && $value !== null; });
    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    return $path . ($query !== '' ? '?' . $query : '');
}
