
<?php

// declare(strict_types=1);

/**
 * Decision Tree sederhana (CART/Gini)
 * untuk klasifikasi potensi tunggakan SPP.
 *
 * Fitur berasal dari status pembayaran
 * tiga bulan sebelumnya.
 */

/**
 * Menghitung nilai Gini.
 */
function spp_gini(array $rows): float
{
    $n = count($rows);

    if ($n === 0) {
        return 0.0;
    }

    $positive = 0;

    foreach ($rows as $row) {
        $positive += (int)($row['target'] ?? 0);
    }

    $p = $positive / $n;

    return 1.0 - ($p * $p) - ((1.0 - $p) * (1.0 - $p));
}

/**
 * Membuat node leaf.
 */
function spp_tree_leaf(array $rows): array
{
    $positive = 0;

    foreach ($rows as $row) {
        $positive += (int)($row['target'] ?? 0);
    }

    $total = count($rows);

    return [
        'leaf' => true,
        'class' => ($positive / max(1, $total)) >= 0.5 ? 1 : 0,
        'positive' => $positive,
        'total' => $total,
    ];
}

/**
 * Melatih Decision Tree menggunakan CART.
 */
function spp_train_tree(
    array $rows,
    array $features,
    int $depth = 0,
    int $maxDepth = 3
): array {

    if (empty($rows)) {
        return spp_tree_leaf([]);
    }

    $leaf = spp_tree_leaf($rows);

    if (
        $depth >= $maxDepth ||
        count($rows) < 6 ||
        spp_gini($rows) === 0.0
    ) {
        return $leaf;
    }

    $best = null;
    $parentImpurity = spp_gini($rows);

    foreach ($features as $feature) {

        // Mengambil nilai fitur secara aman.
        $values = array_values(array_unique(array_map(
            static fn($row) => (float)($row['features'][$feature] ?? 0),
            $rows
        )));

        sort($values, SORT_NUMERIC);

        if (count($values) < 2) {
            continue;
        }

        for ($i = 0; $i < count($values) - 1; $i++) {

            $threshold = ($values[$i] + $values[$i + 1]) / 2.0;

            $left = [];
            $right = [];

            foreach ($rows as $row) {

                $value = (float)($row['features'][$feature] ?? 0);

                if ($value <= $threshold) {
                    $left[] = $row;
                } else {
                    $right[] = $row;
                }
            }

            if (count($left) < 2 || count($right) < 2) {
                continue;
            }

            $weighted =
                (count($left) / count($rows)) * spp_gini($left)
                +
                (count($right) / count($rows)) * spp_gini($right);

            $gain = $parentImpurity - $weighted;

            if ($best === null || $gain > $best['gain']) {

                $best = [
                    'feature' => $feature,
                    'threshold' => $threshold,
                    'left' => $left,
                    'right' => $right,
                    'gain' => $gain,
                ];
            }
        }
    }

    if ($best === null || $best['gain'] <= 0.000001) {
        return $leaf;
    }

    return [
        'leaf' => false,
        'feature' => $best['feature'],
        'threshold' => $best['threshold'],

        'left' => spp_train_tree(
            $best['left'],
            $features,
            $depth + 1,
            $maxDepth
        ),

        'right' => spp_train_tree(
            $best['right'],
            $features,
            $depth + 1,
            $maxDepth
        ),
    ];
}

/**
 * Melakukan prediksi menggunakan Decision Tree.
 */
function spp_tree_predict(array $tree, array $features): array
{
    if (($tree['leaf'] ?? false) === true) {

        return [
            'class' => (int)($tree['class'] ?? 0),

            'positive_ratio' =>
            (int)($tree['positive'] ?? 0) /
                max(1, (int)($tree['total'] ?? 0)),

            'leaf_total' => (int)($tree['total'] ?? 0),
        ];
    }

    $key = $tree['feature'] ?? '';

    // Mencegah Undefined array key.
    $featureValue = (float)($features[$key] ?? 0);

    $threshold = (float)($tree['threshold'] ?? 0);

    $branch = $featureValue <= $threshold
        ? 'left'
        : 'right';

    if (
        !isset($tree[$branch]) ||
        !is_array($tree[$branch])
    ) {
        return [
            'class' => 0,
            'positive_ratio' => 0.0,
            'leaf_total' => 0,
        ];
    }

    return spp_tree_predict($tree[$branch], $features);
}

/**
 * Membentuk fitur dari riwayat pembayaran.
 *
 * History:
 * 1 = belum lunas
 * 0 = sudah lunas
 *
 * Urutan history:
 * bulan terlama ke terbaru.
 */
function spp_make_features(array $history): array
{
    $last = array_slice(
        array_map('intval', $history),
        -3
    );

    // Memastikan tersedia tiga bulan.
    $last = array_pad($last, -3, 0);

    $prev1 = (int)($last[2] ?? 0);
    $prev2 = (int)($last[1] ?? 0);
    $prev3 = (int)($last[0] ?? 0);

    return [
        'unpaid_prev1' => $prev1,
        'unpaid_prev2' => $prev2,
        'unpaid_prev3' => $prev3,

        'unpaid_count_3' =>
        $prev1 + $prev2 + $prev3,

        'unpaid_streak' => (
            $prev1 === 1
            ? (
                $prev2 === 1
                ? ($prev3 === 1 ? 3 : 2)
                : 1
            )
            : 0
        ),
    ];
}

/**
 * Memuat dataset pembayaran bulanan.
 *
 * Target:
 * 1 = belum lunas
 * 0 = sudah lunas
 */
function spp_load_prediction_data(
    PDO $pdo,
    int $historyMonths = 18
): array {

    $historyMonths = max(3, $historyMonths);

    $lastClosed = new DateTimeImmutable('first day of this month');
    $lastClosed = $lastClosed->modify('-1 month');

    $dataStart = $lastClosed->modify(
        '-' . ($historyMonths + 2) . ' months'
    );

    $dataEnd = $lastClosed->format('Y-m-d');
    $start = $dataStart->format('Y-m-d');

    // Mengambil data santri aktif.
    $students = $pdo->query(
        "SELECT
            s.id,
            s.student_no,
            s.class_level,
            s.created_at,
            u.full_name
         FROM students s
         JOIN users u ON u.id = s.user_id
         WHERE s.active = 1
         ORDER BY u.full_name"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Mengambil pembayaran SPP.
    $stmt = $pdo->prepare(
        "SELECT
            student_id,
            DATE_FORMAT(billing_month, '%Y-%m-01') AS ym,
            MIN(created_at) AS first_payment
         FROM payments
         WHERE status = 'paid'
           AND payment_type IN ('spp', 'combined')
           AND billing_month >= ?
           AND billing_month <= ?
         GROUP BY
            student_id,
            DATE_FORMAT(billing_month, '%Y-%m-01')"
    );

    $stmt->execute([$start, $dataEnd]);

    $paidRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $paidMap = [];

    foreach ($paidRows as $row) {

        $monthEnd = (
            new DateTimeImmutable($row['ym'])
        )->modify('last day of this month')
            ->format('Y-m-d 23:59:59');

        $paidMap[(int)$row['student_id']][$row['ym']] =
            (
                $row['first_payment'] !== null &&
                $row['first_payment'] <= $monthEnd
            ) ? 1 : 0;
    }

    // Membuat daftar bulan.
    $months = [];

    $cursor = $dataStart;

    while ($cursor <= $lastClosed) {

        $months[] = $cursor->format('Y-m-01');

        $cursor = $cursor->modify('+1 month');
    }

    $samples = [];
    $studentHistory = [];

    foreach ($students as $student) {

        $sid = (int)$student['id'];

        $createdMonth = (
            new DateTimeImmutable($student['created_at'])
        )->modify('first day of this month')
            ->format('Y-m-01');

        $outcomes = [];

        foreach ($months as $month) {

            $outcomes[$month] =
                $paidMap[$sid][$month] ?? 0;
        }

        $studentHistory[$sid] = $outcomes;

        for ($i = 3; $i < count($months); $i++) {

            $targetMonth = $months[$i];

            if ($months[$i - 3] < $createdMonth) {
                continue;
            }

            $history = [
                $outcomes[$months[$i - 3]],
                $outcomes[$months[$i - 2]],
                $outcomes[$months[$i - 1]],
            ];

            // Mengubah status pembayaran menjadi status tunggakan.
            $features = spp_make_features(
                array_map(
                    static fn($v) => 1 - (int)$v,
                    $history
                )
            );

            $samples[] = [
                'student_id' => $sid,
                'month' => $targetMonth,
                'features' => $features,
                'target' => 1 - (int)$outcomes[$targetMonth],
            ];
        }
    }

    // Mengurutkan dataset berdasarkan bulan.
    usort(
        $samples,
        static fn($a, $b) =>
        strcmp($a['month'], $b['month'])
    );

    return [
        'students' => $students,
        'samples' => $samples,
        'history' => $studentHistory,
        'months' => $months,

        'current_month' => (new DateTimeImmutable('first day of this month'))
            ->format('Y-m-01'),

        'last_closed_month' =>
        $lastClosed->format('Y-m-01'),
    ];
}

/**
 * Evaluasi performa Decision Tree.
 */
function spp_evaluate_tree(
    array $tree,
    array $rows
): array {

    $tp = 0;
    $tn = 0;
    $fp = 0;
    $fn = 0;

    foreach ($rows as $row) {

        $predicted = spp_tree_predict(
            $tree,
            $row['features'] ?? []
        )['class'];

        $actual = (int)($row['target'] ?? 0);

        if ($predicted === 1 && $actual === 1) {
            $tp++;
        } elseif ($predicted === 0 && $actual === 0) {
            $tn++;
        } elseif ($predicted === 1) {
            $fp++;
        } else {
            $fn++;
        }
    }

    $total = $tp + $tn + $fp + $fn;

    $precision = ($tp + $fp) > 0
        ? $tp / ($tp + $fp)
        : 0.0;

    $recall = ($tp + $fn) > 0
        ? $tp / ($tp + $fn)
        : 0.0;

    $f1 = ($precision + $recall) > 0
        ? 2 * $precision * $recall / ($precision + $recall)
        : 0.0;

    return [
        'total' => $total,

        'tp' => $tp,
        'tn' => $tn,
        'fp' => $fp,
        'fn' => $fn,

        'accuracy' => $total > 0
            ? ($tp + $tn) / $total
            : 0.0,

        'precision' => $precision,
        'recall' => $recall,
        'f1' => $f1,
    ];
}
