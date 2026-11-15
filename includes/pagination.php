<?php
/** Shared server-side pagination helpers for list pages. */
function pagination_state(int $total, array $allowed = [10, 25, 50, 100]): array {
    $perPage = (int)($_GET['per_page'] ?? 10);
    if (!in_array($perPage, $allowed, true)) $perPage = 10;
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($pages, max(1, (int)($_GET['page'] ?? 1)));
    return ['total'=>$total, 'per_page'=>$perPage, 'pages'=>$pages, 'page'=>$page, 'offset'=>($page-1)*$perPage,
        'from'=>$total ? (($page-1)*$perPage+1) : 0, 'to'=>min($page*$perPage,$total)];
}
function render_pagination(array $state, array $query = []): void {
    $base = $query; unset($base['page']);
    $url = static function(int $page) use ($base): string { $q=$base; $q['page']=$page; return '?' . http_build_query($q); };
    echo '<div class="pagination-bar"><div class="pagination-meta">Menampilkan '.number_format($state['from'],0,',','.').'–'.number_format($state['to'],0,',','.').' dari '.number_format($state['total'],0,',','.').' data</div>';
    echo '<div class="pagination-controls"><label>Per halaman <select onchange="const u=new URL(location.href);u.searchParams.set(\'per_page\',this.value);u.searchParams.set(\'page\',1);location.href=u.toString()">';
    foreach ([10,25,50,100] as $n) echo '<option value="'.$n.'"'.($state['per_page']===$n?' selected':'').'>'.$n.'</option>';
    echo '</select></label><nav aria-label="Navigasi halaman"><a class="page-btn '.($state['page']<=1?'disabled':'').'" href="'.e($url(max(1,$state['page']-1))).'"'.($state['page']<=1?' aria-disabled="true" tabindex="-1"':'').'>Previous</a>';
    $start=max(1,$state['page']-2); $end=min($state['pages'],$state['page']+2);
    for($p=$start;$p<=$end;$p++) echo '<a class="page-btn '.($p===$state['page']?'active':'').'" href="'.e($url($p)).'"'.($p===$state['page']?' aria-current="page"':'').'>'.$p.'</a>';
    echo '<a class="page-btn '.($state['page']>=$state['pages']?'disabled':'').'" href="'.e($url(min($state['pages'],$state['page']+1))).'"'.($state['page']>=$state['pages']?' aria-disabled="true" tabindex="-1"':'').'>Next</a></nav></div></div>';
}
