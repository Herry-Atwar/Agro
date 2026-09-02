<?php
/**
 * export_report.php  — slim router
 * Reads filter params, builds shared state, then delegates to:
 *   export_pl.php      — Profit & Loss (incl. Detail)
 *   export_bs.php      — Balance Sheet (Detail & Grouped)
 *   export_generic.php — all other reports + PDF fallback
 */

require_once 'includes/auth.php';
require_once 'config/database.php';
require_once 'includes/lang.php';

require_login();
$pdo = getDB();

// ── 1. Read filter params ─────────────────────────────────────────────────────
$export_type      = $_GET['export']        ?? 'excel';
$report_type      = $_GET['report']        ?? 'profit_loss';
$company_id       = $_GET['company_id']    ?? ($_SESSION['company_id']       ?? '');
$business_unit_id = $_GET['estate_id']     ?? ($_SESSION['business_unit_id'] ?? '');
$division_id      = $_GET['division_id']   ?? ($_SESSION['division_id']      ?? '');
$block_id         = $_GET['block_id']      ?? '';
$activity_id      = $_GET['activity_id']   ?? '';
$date_from        = $_GET['date_from']     ?? date('Y-01-01');
$date_to          = $_GET['date_to']       ?? date('Y-m-t');
$cost_category    = $_GET['cost_category'] ?? '';
$status_filter    = $_GET['status']        ?? '';
$estate_id        = $business_unit_id;

// Print-info params (passed from exportToExcel() in financial_reports.php)
$printed_by   = trim($_GET['printed_by']   ?? '');
$print_dt     = trim($_GET['print_dt']     ?? '');
$print_by_lbl = trim($_GET['print_by_lbl'] ?? __('pl_xls_printed_by'));
$datetime_lbl = trim($_GET['datetime_lbl'] ?? __('pl_xls_datetime'));
// Ensure labels are never empty
if ($print_by_lbl === '') $print_by_lbl = __('pl_xls_printed_by');
if ($datetime_lbl === '') $datetime_lbl = __('pl_xls_datetime');

// ── 2. Company display name ───────────────────────────────────────────────────
$_company_display = 'AI based AGROBUSINESS SOLUTION';
if (!empty($_SESSION['company_id'])) {
    try {
        $__cs = $pdo->prepare("SELECT company_name FROM companies WHERE company_id = ? LIMIT 1");
        $__cs->execute([$_SESSION['company_id']]);
        $__cn = $__cs->fetchColumn();
        if ($__cn) { $_company_display = $__cn; }
    } catch (Exception $_e) {}
}

// ── 3. Generic WHERE clause (used by non-BS reports) ─────────────────────────
$where_conditions = ["je.status = 'posted'"];
$params = [];
if ($company_id)    { $where_conditions[] = "je.company_id = :company_id";        $params[':company_id']    = $company_id; }
if ($estate_id)     { $where_conditions[] = "je.business_unit_id = :estate_id";   $params[':estate_id']     = $estate_id; }
if ($division_id)   { $where_conditions[] = "je.division_id = :division_id";      $params[':division_id']   = $division_id; }
if ($block_id)      { $where_conditions[] = "je.block_id = :block_id";            $params[':block_id']      = $block_id; }
if ($activity_id)   { $where_conditions[] = "jel.activity_id = :activity_id";     $params[':activity_id']   = $activity_id; }
if ($cost_category) { $where_conditions[] = "jel.cost_category = :cost_category"; $params[':cost_category'] = $cost_category; }
if ($status_filter) { $where_conditions[] = "b.status = :status";                 $params[':status']        = $status_filter; }
if ($date_from)     { $where_conditions[] = "je.entry_date >= :date_from";         $params[':date_from']     = $date_from; }
if ($date_to)       { $where_conditions[] = "je.entry_date <= :date_to";           $params[':date_to']       = $date_to; }
$where_clause = implode(' AND ', $where_conditions);

// P&L-safe WHERE clause: strips "b.status" which requires a blocks JOIN not present in P&L queries
$pl_where_conditions = array_filter($where_conditions, fn($c) => strpos($c, 'b.status') === false);
$pl_where_clause = implode(' AND ', $pl_where_conditions);
$pl_params = $params;
unset($pl_params[':status']);

// ── 4. Shared helpers ─────────────────────────────────────────────────────────
function fmt_rp(float $v): string {
    return ($v < 0 ? '-' : '') . number_format(abs($v), 0, ',', '.');
}
function fmt_pct(float $v, int $dec = 1): string {
    return number_format($v, $dec) . '%';
}
function xe(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

$xv      = fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$fmtDate = fn(string $s): string => preg_replace('/(\d{4})-(\d{2})-(\d{2})/', '$3/$2/$1', $s);

$col_letter = function(int $n): string {
    $s = '';
    for ($i = $n; $i >= 0; $i = intdiv($i, 26) - 1) {
        $s = chr(65 + $i % 26) . $s;
    }
    return $s;
};

// ── 5. Route to specialist or generic handler ─────────────────────────────────
if ($export_type === 'excel' && in_array($report_type, ['profit_loss', 'profit_loss_detail'])) {
    if (file_exists(__DIR__ . '/export_pl.php')) { require __DIR__ . '/export_pl.php'; exit; }
    _export_pl($pdo, $where_clause, $params, $date_from, $date_to, $_company_display, $report_type, $xv, $fmtDate, $col_letter);
    exit;
}

if ($export_type === 'excel' && in_array($report_type, ['balance_sheet', 'balance_sheet_group'])) {
    if (file_exists(__DIR__ . '/export_bs.php')) { require __DIR__ . '/export_bs.php'; exit; }
    _export_bs($pdo, $date_from, $date_to, $company_id, $estate_id, $division_id, $_company_display, $report_type, $xv, $fmtDate, $col_letter);
    exit;
}

if ($export_type === 'excel' && $report_type === 'trial_balance') {
    _export_tb($pdo, $date_from, $date_to, $company_id, $estate_id, $division_id, $_company_display, $xv, $fmtDate, $col_letter);
    exit;
}

if ($export_type === 'excel' && $report_type === 'general_ledger') {
    _export_gl($pdo, $date_from, $date_to, $company_id, $estate_id, $division_id,
               $_GET['gl_acct_from'] ?? '', $_GET['gl_acct_to'] ?? '',
               $_company_display, $xv, $fmtDate, $col_letter);
    exit;
}

// ── Generic reports: fetch data then hand off ────────────────────────────────
$report = fetch_report_data($report_type, $pdo, $where_clause, $params,
                             $date_from, $date_to,
                             $estate_id, $company_id, $division_id, $block_id);
$title         = $report['title'];
$headers       = $report['headers'];
$rows          = $report['rows'];
$safe_filename = preg_replace('/[^a-z0-9_\-]/i', '_', $title);

if (file_exists(__DIR__ . '/export_generic.php')) { require __DIR__ . '/export_generic.php'; exit; }
_export_generic($export_type, $title, $headers, $rows, $safe_filename, $fmtDate, $date_from, $date_to, $_company_display, $xv);
exit;

// ── Data-fetch function (generic/cost reports only) ───────────────────────────
function fetch_report_data(string $report_type, PDO $pdo, string $where_clause,
                            array $params, string $date_from, string $date_to,
                            string $estate_id, string $company_id, string $division_id,
                            string $block_id): array
{
    switch ($report_type) {

        case 'cost_by_block': {
            $sql = "
                SELECT c.company_name, bu.unit_name, d.division_name,
                       b.block_code, b.block_name, b.status,
                       b.area, py.year,
                       COUNT(DISTINCT je.id) as entry_count,
                       SUM(CASE WHEN jel.cost_category='labor'            THEN jel.debit_amount ELSE 0 END) as labor_cost,
                       SUM(CASE WHEN jel.cost_category='material'         THEN jel.debit_amount ELSE 0 END) as material_cost,
                       SUM(CASE WHEN jel.cost_category='vehicle_equipment'THEN jel.debit_amount ELSE 0 END) as equipment_cost,
                       SUM(CASE WHEN jel.cost_category='overhead'         THEN jel.debit_amount ELSE 0 END) as overhead_cost,
                       SUM(CASE WHEN jel.cost_category='other'            THEN jel.debit_amount ELSE 0 END) as other_cost,
                       SUM(jel.debit_amount) as total_cost,
                       SUM(jel.debit_amount)/NULLIF(b.area,0) as cost_per_ha
                FROM journal_entries je
                JOIN journal_entry_lines jel ON jel.journal_entry_id=je.id
                LEFT JOIN blocks b ON jel.block_id=b.block_id
                LEFT JOIN companies c ON b.company_id=c.company_id
                LEFT JOIN business_units bu ON b.business_unit_id=bu.business_unit_id
                LEFT JOIN divisions d ON b.division_id=d.division_id
                LEFT JOIN planting_years py ON b.planting_year_id=py.planting_year_id
                WHERE $where_clause AND jel.debit_amount>0 AND b.block_id IS NOT NULL
                GROUP BY c.company_name,bu.unit_name,d.division_name,
                         b.block_id,b.block_code,b.block_name,b.status,b.area,py.year
                ORDER BY total_cost DESC";
            $stmt = $pdo->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll();
            $headers = ['Company','Estate','Division','Block Code','Block Name','Status',
                        'Area (Ha)','Year','Labor','Material','Equipment','Overhead','Other',
                        'Total Cost','Cost/Ha','Entries'];
            $data = [];
            foreach ($rows as $r) {
                $data[] = [$r['company_name'],$r['unit_name'],$r['division_name'],
                           $r['block_code'],$r['block_name'],$r['status'],
                           $r['area'],$r['year'],
                           $r['labor_cost'],$r['material_cost'],$r['equipment_cost'],
                           $r['overhead_cost'],$r['other_cost'],
                           $r['total_cost'],$r['cost_per_ha'],$r['entry_count']];
            }
            return ['title'=>'Cost by Block','headers'=>$headers,'rows'=>$data];
        }

        case 'cost_by_activity': {
            $sql = "
                SELECT a.activity_code, a.activity_name,
                       COUNT(DISTINCT je.id) as entry_count,
                       COUNT(DISTINCT jel.block_id) as block_count,
                       SUM(CASE WHEN jel.cost_category='labor'            THEN jel.debit_amount ELSE 0 END) as labor_cost,
                       SUM(CASE WHEN jel.cost_category='material'         THEN jel.debit_amount ELSE 0 END) as material_cost,
                       SUM(CASE WHEN jel.cost_category='vehicle_equipment'THEN jel.debit_amount ELSE 0 END) as equipment_cost,
                       SUM(CASE WHEN jel.cost_category='overhead'         THEN jel.debit_amount ELSE 0 END) as overhead_cost,
                       SUM(CASE WHEN jel.cost_category='other'            THEN jel.debit_amount ELSE 0 END) as other_cost,
                       SUM(jel.debit_amount) as total_cost,
                       SUM(CASE WHEN b.status='LC'  THEN jel.debit_amount ELSE 0 END) as lc_cost,
                       SUM(CASE WHEN b.status='TBM' THEN jel.debit_amount ELSE 0 END) as tbm_cost,
                       SUM(CASE WHEN b.status='TM'  THEN jel.debit_amount ELSE 0 END) as tm_cost
                FROM journal_entries je
                JOIN journal_entry_lines jel ON jel.journal_entry_id=je.id
                LEFT JOIN activities a ON jel.activity_id=a.id
                LEFT JOIN blocks b ON jel.block_id=b.block_id
                WHERE $where_clause AND jel.debit_amount>0 AND a.id IS NOT NULL
                GROUP BY a.id,a.activity_code,a.activity_name
                ORDER BY total_cost DESC";
            $stmt = $pdo->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll();
            $headers = ['Activity Code','Activity Name','Labor','Material','Equipment',
                        'Overhead','Other','Total Cost','LC Cost','TBM Cost','TM Cost',
                        'Entries','Blocks'];
            $data = [];
            foreach ($rows as $r) {
                $data[] = [$r['activity_code'],$r['activity_name'],
                           $r['labor_cost'],$r['material_cost'],$r['equipment_cost'],
                           $r['overhead_cost'],$r['other_cost'],$r['total_cost'],
                           $r['lc_cost'],$r['tbm_cost'],$r['tm_cost'],
                           $r['entry_count'],$r['block_count']];
            }
            return ['title'=>'Cost by Activity','headers'=>$headers,'rows'=>$data];
        }

        case 'cost_by_category': {
            $sql = "
                SELECT jel.cost_category,
                       COUNT(DISTINCT je.id) as entry_count,
                       COUNT(DISTINCT jel.block_id) as block_count,
                       COUNT(DISTINCT je.activity_id) as activity_count,
                       SUM(CASE WHEN b.status='LC'  THEN jel.debit_amount ELSE 0 END) as lc_cost,
                       SUM(CASE WHEN b.status='TBM' THEN jel.debit_amount ELSE 0 END) as tbm_cost,
                       SUM(CASE WHEN b.status='TM'  THEN jel.debit_amount ELSE 0 END) as tm_cost,
                       SUM(jel.debit_amount) as total_cost
                FROM journal_entries je
                JOIN journal_entry_lines jel ON jel.journal_entry_id=je.id
                LEFT JOIN blocks b ON jel.block_id=b.block_id
                WHERE $where_clause AND jel.debit_amount>0 AND jel.cost_category IS NOT NULL
                GROUP BY jel.cost_category ORDER BY total_cost DESC";
            $stmt = $pdo->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll();
            $grand = array_sum(array_column($rows,'total_cost')) ?: 1;
            $headers = ['Category','LC Cost','TBM Cost','TM Cost','Total Cost','% of Total',
                        'Entries','Blocks','Activities'];
            $data = [];
            foreach ($rows as $r) {
                $data[] = [ucfirst(str_replace('_',' ',$r['cost_category'])),
                           $r['lc_cost'],$r['tbm_cost'],$r['tm_cost'],$r['total_cost'],
                           round(($r['total_cost']/$grand)*100,1).'%',
                           $r['entry_count'],$r['block_count'],$r['activity_count']];
            }
            return ['title'=>'Cost by Category','headers'=>$headers,'rows'=>$data];
        }

        case 'block_profitability': {
            $sql_c = "
                SELECT b.block_id,b.block_code,b.block_name,b.area,
                       bu.unit_name,d.division_name,
                       SUM(jel.debit_amount) as total_cost,
                       SUM(jel.debit_amount)/NULLIF(b.area,0) as cost_per_ha
                FROM journal_entries je
                JOIN journal_entry_lines jel ON jel.journal_entry_id=je.id
                LEFT JOIN blocks b ON jel.block_id=b.block_id
                LEFT JOIN business_units bu ON b.business_unit_id=bu.business_unit_id
                LEFT JOIN divisions d ON b.division_id=d.division_id
                WHERE $where_clause AND jel.debit_amount>0
                  AND b.block_id IS NOT NULL AND b.status='TM'
                GROUP BY b.block_id,b.block_code,b.block_name,b.area,bu.unit_name,d.division_name";
            $stmt = $pdo->prepare($sql_c); $stmt->execute($params);
            $costs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $ffb = 2500;
            $sql_r = "SELECT hr.block_id, SUM(hr.actual_quantity_kg)*$ffb as total_revenue,
                             SUM(hr.actual_quantity_kg) as qty
                      FROM harvest_realizations hr
                      WHERE hr.harvest_date BETWEEN :df AND :dt
                      ".($block_id?"AND hr.block_id=:block_id":"")."
                      GROUP BY hr.block_id";
            $rp = [':df'=>$date_from,':dt'=>$date_to];
            if ($block_id) $rp[':block_id']=$block_id;
            $stmt2=$pdo->prepare($sql_r); $stmt2->execute($rp);
            $rev=[]; foreach($stmt2->fetchAll() as $rv) $rev[$rv['block_id']]=$rv;
            $headers = ['Estate','Division','Block Code','Block Name','Area (Ha)',
                        'FFB (Kg)','Revenue','Cost','Profit','Margin %',
                        'Revenue/Ha','Cost/Ha','Profit/Ha'];
            $data = [];
            foreach ($costs as $c) {
                $r = $rev[$c['block_id']]['total_revenue'] ?? 0;
                $q = $rev[$c['block_id']]['qty'] ?? 0;
                $pr = $r - $c['total_cost'];
                $mg = $r > 0 ? round(($pr/$r)*100,1) : 0;
                $rha = $c['area'] > 0 ? $r/$c['area'] : 0;
                $pha = $c['area'] > 0 ? $pr/$c['area'] : 0;
                $data[] = [$c['unit_name']??'N/A',$c['division_name'],
                           $c['block_code'],$c['block_name'],$c['area'],
                           $q,$r,$c['total_cost'],$pr,$mg.'%',
                           round($rha),$c['cost_per_ha'],round($pha)];
            }
            usort($data,fn($a,$b)=>$b[8]<=>$a[8]);
            return ['title'=>'Block Profitability (TM Blocks)','headers'=>$headers,'rows'=>$data];
        }

        case 'monthly_trends': {
            $tc=[]; $tc_cond=["je.status='posted'","jel.debit_amount>0",
                              "je.entry_date>=DATE_SUB(CURDATE(),INTERVAL 12 MONTH)"];
            if ($company_id)    { $tc_cond[]="je.company_id=:company_id";        $tc[':company_id']   =$company_id; }
            if ($estate_id)     { $tc_cond[]="je.business_unit_id=:estate_id";   $tc[':estate_id']    =$estate_id; }
            if ($division_id)   { $tc_cond[]="je.division_id=:division_id";      $tc[':division_id']  =$division_id; }
            if ($block_id)      { $tc_cond[]="je.block_id=:block_id";            $tc[':block_id']     =$block_id; }
            if (!empty($cost_category)) { $tc_cond[]="jel.cost_category=:cost_category"; $tc[':cost_category']=$cost_category; }
            $tw=implode(' AND ',$tc_cond);
            $sql="SELECT DATE_FORMAT(je.entry_date,'%Y-%m') as month,
                         DATE_FORMAT(je.entry_date,'%b %Y') as month_label,
                         COUNT(DISTINCT je.id) as entry_count,
                         SUM(CASE WHEN jel.cost_category='labor'            THEN jel.debit_amount ELSE 0 END) as labor_cost,
                         SUM(CASE WHEN jel.cost_category='material'         THEN jel.debit_amount ELSE 0 END) as material_cost,
                         SUM(CASE WHEN jel.cost_category='vehicle_equipment'THEN jel.debit_amount ELSE 0 END) as equipment_cost,
                         SUM(CASE WHEN jel.cost_category='overhead'         THEN jel.debit_amount ELSE 0 END) as overhead_cost,
                         SUM(CASE WHEN jel.cost_category='other'            THEN jel.debit_amount ELSE 0 END) as other_cost,
                         SUM(jel.debit_amount) as total_cost
                  FROM journal_entries je
                  JOIN journal_entry_lines jel ON je.id=jel.id
                  LEFT JOIN blocks b ON je.block_id=b.block_id
                  WHERE $tw GROUP BY month,month_label ORDER BY month";
            $stmt=$pdo->prepare($sql); $stmt->execute($tc); $rows=$stmt->fetchAll();
            $headers=['Month','Labor','Material','Equipment','Overhead','Other',
                      'Total Cost','MoM Growth %','Entries'];
            $data=[]; $prev=0;
            foreach($rows as $r){
                $g = $prev>0 ? round((($r['total_cost']-$prev)/$prev)*100,1) : 0;
                $data[]=[
                    $r['month_label'],$r['labor_cost'],$r['material_cost'],
                    $r['equipment_cost'],$r['overhead_cost'],$r['other_cost'],
                    $r['total_cost'],($prev>0?($g>=0?'+'.$g.'%':$g.'%'):'-'),
                    $r['entry_count']];
                $prev=$r['total_cost'];
            }
            return ['title'=>'Monthly Cost Trends','headers'=>$headers,'rows'=>$data];
        }

        case 'cost_variance': {
            $sql="SELECT b.block_id,b.block_code,b.block_name,b.area,b.status as block_status,
                         a.id as act_id,a.activity_code,a.activity_name,
                         SUM(jel.debit_amount) as actual_cost,
                         COUNT(DISTINCT je.id) as entry_count
                  FROM journal_entries je
                  JOIN journal_entry_lines jel ON jel.journal_entry_id=je.id
                  LEFT JOIN blocks b ON jel.block_id=b.block_id
                  LEFT JOIN activities a ON je.activity_id=a.id
                  WHERE $where_clause AND jel.debit_amount>0
                    AND b.block_id IS NOT NULL AND a.id IS NOT NULL
                  GROUP BY b.block_id,b.block_code,b.block_name,b.area,b.status,
                           a.id,a.activity_code,a.activity_name";
            $stmt=$pdo->prepare($sql); $stmt->execute($params); $rows=$stmt->fetchAll();
            $norms=[];
            try {
                if ($pdo->query("SHOW TABLES LIKE 'activity_norms'")->fetch()) {
                    $ns=$pdo->query("SELECT activity_id,terrain_type,man_days_per_unit*150000 as cpu,is_default
                                     FROM activity_norms WHERE is_active=1")->fetchAll();
                    foreach($ns as $n) $norms[$n['activity_id'].'_'.$n['terrain_type']]=$n;
                }
            } catch(Exception $e) {}
            $headers=['Block Code','Block Name','Block Status','Area (Ha)',
                      'Activity Code','Activity Name','Actual Cost','Standard Cost',
                      'Variance','Variance %','Status','Entries'];
            $data=[];
            foreach($rows as $r){
                $tt=['Flat'=>'flat','Undulating'=>'sloping','Hilly'=>'sloping','Steep'=>'steep'];
                $bt=$pdo->prepare("SELECT topography FROM blocks WHERE block_id=? LIMIT 1");
                $bt->execute([$r['block_id']]);
                $topo=$bt->fetchColumn()?:'Flat';
                $tn=$tt[$topo]??'flat';
                $norm=$norms[$r['act_id'].'_'.$tn]??null;
                if(!$norm) foreach($norms as $k=>$n) if(strpos($k,$r['act_id'].'_')===0&&$n['is_default']==1){$norm=$n;break;}
                $sc=$norm&&$r['area']>0?$r['area']*$norm['cpu']:0;
                $var=$r['actual_cost']-$sc;
                $vp=$sc>0?round(($var/$sc)*100,1):0;
                $st=!$norm?'No Norm':($var>0?'Unfavorable':'Favorable');
                $data[]=[$r['block_code'],$r['block_name'],$r['block_status'],$r['area'],
                          $r['activity_code'],$r['activity_name'],$r['actual_cost'],$sc,
                          $var,($sc>0?($vp>=0?'+'.$vp.'%':$vp.'%'):'-'),
                          $st,$r['entry_count']];
            }
            usort($data,fn($a,$b)=>abs($b[8])<=>abs($a[8]));
            return ['title'=>'Cost Variance (Actual vs Standard)','headers'=>$headers,'rows'=>$data];
        }

        case 'profit_loss':
        case 'profit_loss_detail': {
            // Fallback plain data (structured export handled above via export_pl.php)
            $detail = ($report_type === 'profit_loss_detail');
            $sql="SELECT gla.account_type,
                         ".($detail?"gla.account_code,":"")."
                         gla.account_name,
                         SUM(jel.debit_amount) as total_debit,
                         SUM(jel.credit_amount) as total_credit
                  FROM journal_entries je
                  JOIN journal_entry_lines jel ON jel.journal_entry_id=je.id
                  JOIN general_ledger_accounts gla ON gla.id=jel.gl_account_id
                  WHERE $where_clause
                    AND gla.account_type IN('revenue','cogs','operating_expense','expense',
                                            'other_income','other_expenses','tax')
                  GROUP BY gla.id,gla.account_type".($detail?",gla.account_code":"").",gla.account_name
                  HAVING (SUM(jel.debit_amount)+SUM(jel.credit_amount))>0
                  ORDER BY gla.account_type,gla.account_name";
            $stmt=$pdo->prepare($sql); $stmt->execute($params); $rows=$stmt->fetchAll();
            $section_map=['revenue'=>'Revenue','cogs'=>'COGS',
                          'operating_expense'=>'Operating Expenses','expense'=>'Operating Expenses',
                          'other_income'=>'Other Income','other_expenses'=>'Other Expenses','tax'=>'Tax Expense'];
            $title = $detail ? 'Detail Profit & Loss' : 'Profit & Loss';
            $headers = $detail
                ? ['Section','Account Code','Account','Debit','Credit','Net Amount']
                : ['Section','Account','Debit','Credit','Net Amount'];
            $data=[];
            foreach($rows as $r){
                $d=(float)$r['total_debit']; $c=(float)$r['total_credit'];
                $t=in_array($r['account_type'],['revenue','other_income'])?$c-$d:$d-$c;
                $sec=$section_map[$r['account_type']]??$r['account_type'];
                if($detail) $data[]=[$sec,$r['account_code'],$r['account_name'],$d,$c,$t];
                else        $data[]=[$sec,$r['account_name'],$d,$c,$t];
            }
            return ['title'=>$title,'headers'=>$headers,'rows'=>$data];
        }

        case 'balance_sheet':
        case 'balance_sheet_group': {
            // Fallback plain data (structured export handled above via export_bs.php)
            $detail = ($report_type === 'balance_sheet');
            $bs_cond=["je.status='posted'","je.entry_date<=:bs_date_to"];
            $bs_p=[':bs_date_to'=>$date_to];
            if($company_id)  {$bs_cond[]="je.company_id=:company_id";        $bs_p[':company_id'] =$company_id;}
            if($estate_id)   {$bs_cond[]="je.business_unit_id=:estate_id";   $bs_p[':estate_id']  =$estate_id;}
            if($division_id) {$bs_cond[]="je.division_id=:division_id";      $bs_p[':division_id']=$division_id;}
            $bw=implode(' AND ',$bs_cond);
            if($detail){
                $sql="SELECT gla.account_code,gla.account_name,gla.account_type,
                             SUM(jel.debit_amount) as td,SUM(jel.credit_amount) as tc
                      FROM journal_entries je
                      JOIN journal_entry_lines jel ON jel.journal_entry_id=je.id
                      JOIN general_ledger_accounts gla ON gla.id=jel.gl_account_id
                      WHERE $bw AND gla.account_type IN('asset','liability','equity')
                      GROUP BY gla.id,gla.account_code,gla.account_name,gla.account_type
                      HAVING (SUM(jel.debit_amount)+SUM(jel.credit_amount))>0
                      ORDER BY gla.account_type,gla.account_code";
                $stmt=$pdo->prepare($sql); $stmt->execute($bs_p); $rows=$stmt->fetchAll();
                $headers=['Section','Account Code','Account','Debit','Credit','Balance'];
                $data=[];
                foreach($rows as $r){
                    $d=(float)$r['td']; $c=(float)$r['tc'];
                    $b=$r['account_type']==='asset'?$d-$c:$c-$d;
                    $data[]=[ucfirst($r['account_type']),$r['account_code'],$r['account_name'],$d,$c,$b];
                }
                return ['title'=>'Detail Balance Sheet — As at '.$date_to,'headers'=>$headers,'rows'=>$data];
            } else {
                $sql="SELECT fag.group_name,fag.report_section,gla.account_type,
                             SUM(jel.debit_amount) as td,SUM(jel.credit_amount) as tc
                      FROM journal_entries je
                      JOIN journal_entry_lines jel ON jel.journal_entry_id=je.id
                      JOIN general_ledger_accounts gla ON gla.id=jel.gl_account_id
                      JOIN financial_account_groups fag ON fag.id=gla.financial_group_id
                      WHERE $bw AND gla.account_type IN('asset','liability','equity')
                        AND fag.report_type='balance_sheet' AND fag.is_active=1
                      GROUP BY fag.id,fag.group_name,fag.report_section,gla.account_type
                      HAVING (SUM(jel.debit_amount)+SUM(jel.credit_amount))>0
                      ORDER BY gla.account_type,fag.display_order";
                $stmt=$pdo->prepare($sql); $stmt->execute($bs_p); $rows=$stmt->fetchAll();
                $headers=['Section','Group','Report Section','Balance'];
                $data=[];
                foreach($rows as $r){
                    $d=(float)$r['td']; $c=(float)$r['tc'];
                    $b=$r['account_type']==='asset'?$d-$c:$c-$d;
                    $data[]=[ucfirst($r['account_type']),$r['group_name'],$r['report_section'],$b];
                }
                return ['title'=>'Balance Sheet — As at '.$date_to,'headers'=>$headers,'rows'=>$data];
            }
        }

        default:
            return ['title'=>'Report','headers'=>[],'rows'=>[]];
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// INLINE FALLBACK FUNCTIONS — used when split files are not present on server
// ═══════════════════════════════════════════════════════════════════════════════

// ── Shared ZIP builder ────────────────────────────────────────────────────────
function _build_zip(array $parts): string {
    if (extension_loaded('zip')) {
        $tmp = tempnam(sys_get_temp_dir(), 'xls_');
        @unlink($tmp);
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::CREATE);
        foreach ($parts as $_n => $_d) { $zip->addFromString($_n, $_d); }
        $zip->close();
        $bytes = file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }
    $zb = ''; $cd = ''; $off = [];
    $dt = (((2024-1980)&0x7f)<<25)|((1&0x0f)<<21)|((1&0x1f)<<16);
    foreach ($parts as $n => $d) {
        $off[$n]=strlen($zb); $cr=crc32($d); $sz=strlen($d); $nl=strlen($n);
        $zb.="\x50\x4b\x03\x04".pack('v',20).pack('v',0).pack('v',0).pack('V',$dt).pack('V',$cr).pack('V',$sz).pack('V',$sz).pack('v',$nl).pack('v',0).$n.$d;
        $cd.="\x50\x4b\x01\x02".pack('v',20).pack('v',20).pack('v',0).pack('v',0).pack('V',$dt).pack('V',$cr).pack('V',$sz).pack('V',$sz).pack('v',$nl).pack('v',0).pack('v',0).pack('v',0).pack('v',0).pack('V',0).pack('V',$off[$n]).$n;
    }
    $co=strlen($zb); $cs=strlen($cd); $ne=count($parts);
    return $zb.$cd."\x50\x4b\x05\x06".pack('v',0).pack('v',0).pack('v',$ne).pack('v',$ne).pack('V',$cs).pack('V',$co).pack('v',0);
}

// ── Shared XLSX package sender ────────────────────────────────────────────────
function _send_xlsx(array $parts, string $filename): void {
    $bytes = _build_zip($parts);
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Content-Length: '.strlen($bytes));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache'); header('Expires: 0');
    echo $bytes;
    exit;
}

// ── Shared OOXML package builder ──────────────────────────────────────────────
function _xlsx_package(string $ws_xml, string $sst_xml, string $styles_xml, string $sheet_name_raw): array {
    $xv = fn($s) => htmlspecialchars($s, ENT_XML1|ENT_QUOTES,'UTF-8');
    $sn = $xv(mb_substr(preg_replace('/[\\/\?*\[\]:]/','',$sheet_name_raw),0,31));
    $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<bookViews><workbookView xWindow="0" yWindow="0" windowWidth="14400" windowHeight="9000"/></bookViews>'
        . '<sheets><sheet name="'.$sn.'" sheetId="1" r:id="rId1"/></sheets></workbook>';
    $wr = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
        . '</Relationships>';
    $rr = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>';
    return ['[Content_Types].xml'=>$ct,'_rels/.rels'=>$rr,'xl/workbook.xml'=>$wb,'xl/_rels/workbook.xml.rels'=>$wr,'xl/styles.xml'=>$styles_xml,'xl/sharedStrings.xml'=>$sst_xml,'xl/worksheets/sheet1.xml'=>$ws_xml];
}

// ── P&L inline export (formula-free: all totals are pre-calculated PHP values) ─
function _export_pl(PDO $pdo, string $where_clause, array $params, string $date_from, string $date_to,
                    string $_company_display, string $report_type, callable $xv, callable $fmtDate, callable $col_letter): void
{
    $detail = ($report_type === 'profit_loss_detail');
    $sql = "SELECT gla.account_type,".($detail?"gla.account_code,":"")."gla.account_name,
                   SUM(jel.debit_amount) AS total_debit, SUM(jel.credit_amount) AS total_credit
            FROM journal_entries je
            JOIN journal_entry_lines jel ON jel.journal_entry_id=je.id
            JOIN general_ledger_accounts gla ON gla.id=jel.gl_account_id
            WHERE $where_clause AND gla.account_type IN('revenue','cogs','operating_expense','expense','other_income','other_expenses','tax')
            GROUP BY gla.id,gla.account_type".($detail?",gla.account_code":"").",gla.account_name
            HAVING (SUM(jel.debit_amount)+SUM(jel.credit_amount))>0 ORDER BY gla.account_type,gla.account_name";
    $stmt=$pdo->prepare($sql); $stmt->execute($params); $raw_rows=$stmt->fetchAll();

    $buckets=['revenue'=>[],'cogs'=>[],'opex'=>[],'oi'=>[],'oe'=>[],'tax'=>[]];
    foreach($raw_rows as $r){
        $dbt=(float)$r['total_debit']; $cdt=(float)$r['total_credit'];
        $base=['name'=>$r['account_name'],'debit'=>$dbt,'credit'=>$cdt];
        if($detail) $base['code']=$r['account_code']??'';
        switch($r['account_type']){
            case 'revenue':          $buckets['revenue'][]=$base+['net'=>$cdt-$dbt]; break;
            case 'cogs':             $buckets['cogs'][]   =$base+['net'=>$dbt-$cdt]; break;
            case 'operating_expense':
            case 'expense':          $buckets['opex'][]   =$base+['net'=>$dbt-$cdt]; break;
            case 'other_income':     $buckets['oi'][]     =$base+['net'=>$cdt-$dbt]; break;
            case 'other_expenses':   $buckets['oe'][]     =$base+['net'=>$dbt-$cdt]; break;
            case 'tax':              $buckets['tax'][]    =$base+['net'=>$dbt-$cdt]; break;
        }
    }

    // Pre-calculate all totals in PHP — no Excel formulas needed
    $sec_t=[];
    foreach($buckets as $k=>$bkt){
        $sec_t[$k]=['debit'=>array_sum(array_column($bkt,'debit')),'credit'=>array_sum(array_column($bkt,'credit')),'net'=>array_sum(array_column($bkt,'net'))];
    }
    $gp  = $sec_t['revenue']['net'] - $sec_t['cogs']['net'];
    $op  = $gp  - $sec_t['opex']['net'];
    $pbt = $op  + $sec_t['oi']['net'] - $sec_t['oe']['net'];
    $np  = $pbt - $sec_t['tax']['net'];
    $g_dbt = array_sum(array_column($sec_t,'debit'));
    $g_cdt = array_sum(array_column($sec_t,'credit'));

    $si_map=[]; $si_list=[];
    $si_add=function(string $s) use(&$si_map,&$si_list):int{
        if(!isset($si_map[$s])){$si_map[$s]=count($si_list);$si_list[]=$s;}
        return $si_map[$s];
    };

    $pl_title    = $detail?__('pl_xls_detail_title'):__('pl_xls_title');
    $prd_str     = __('pl_xls_period_prefix').': '.$fmtDate($date_from).' '.__('pl_xls_to').' '.$fmtDate($date_to);
    $pby_str     = $print_by_lbl.': '.$printed_by;
    $pdt_str     = $datetime_lbl.': '.$print_dt;
    foreach([$_company_display,$pl_title,$prd_str,$pby_str,$pdt_str] as $s) $si_add($s);

    $COL_DEBIT=$detail?2:1; $COL_CREDIT=$detail?3:2; $COL_NET=$detail?4:3;

    $hdr_labels=$detail?[__('pl_xls_col_account_code'),__('pl_xls_col_account_name'),__('pl_xls_col_debit'),__('pl_xls_col_credit'),__('pl_xls_col_net')]:[__('pl_xls_col_account_name'),__('pl_xls_col_debit'),__('pl_xls_col_credit'),__('pl_xls_col_net')];
    foreach($hdr_labels as $h) $si_add($h);

    $sec_defs=[['key'=>'revenue','label'=>__('pl_xls_sec_revenue')],['key'=>'cogs','label'=>__('pl_xls_sec_cogs')],
               ['key'=>'opex','label'=>__('pl_xls_sec_opex')],['key'=>'oi','label'=>__('pl_xls_sec_oi')],
               ['key'=>'oe','label'=>__('pl_xls_sec_oe')],['key'=>'tax','label'=>__('pl_xls_sec_tax')]];
    $sub_lbl=['revenue'=>__('pl_xls_total_revenue'),'cogs'=>__('pl_xls_total_cogs'),'opex'=>__('pl_xls_total_opex'),
              'oi'=>__('pl_xls_total_oi'),'oe'=>__('pl_xls_total_oe'),'tax'=>__('pl_xls_total_tax')];
    $calc_lbl=['gross'=>__('pl_xls_gross_profit'),'opinc'=>__('pl_xls_op_profit'),'pbt'=>__('pl_xls_pbt'),'npat'=>__('pl_xls_npat')];
    foreach($sec_defs as $s) $si_add($s['label']);
    foreach($sub_lbl as $l) $si_add($l);
    foreach($calc_lbl as $l) $si_add($l);
    $si_add(__('pl_xls_no_transactions'));

    $ws_rows=''; $rn=1;
    $emit=function(array $cells,int $ht=0)use(&$ws_rows,&$rn):void{
        $a=$ht>0?' ht="'.$ht.'" customHeight="1"':'';
        $ws_rows.='<row r="'.$rn.'"'.$a.'>';
        foreach($cells as $c) $ws_rows.=$c;
        $ws_rows.='</row>'; $rn++;
    };
    $sc=function(int $col,string $val,int $st=0)use(&$rn,$col_letter,&$si_map,$si_add):string{
        $si_add($val); return '<c r="'.$col_letter($col).$rn.'" t="s" s="'.$st.'"><v>'.$si_map[$val].'</v></c>';
    };
    $nc=function(int $col,float $val,int $st=4)use(&$rn,$col_letter):string{
        return '<c r="'.$col_letter($col).$rn.'" s="'.$st.'"><v>'.$val.'</v></c>';
    };
    $bc=function(int $col,int $st=0)use(&$rn,$col_letter):string{
        return '<c r="'.$col_letter($col).$rn.'" s="'.$st.'"/>';
    };

    // Row 1-2: print-info in last column; Row 3: blank; Row 4-6: company/title/period; Row 7: blank; Row 8: col headers
    $emit([$sc($COL_NET,$pby_str,14)]); $emit([$sc($COL_NET,$pdt_str,14)]);
    $emit([]);
    $emit([$sc(0,$_company_display,1)],18); $emit([$sc(0,$pl_title,1)],16);
    $emit([$sc(0,$prd_str,2)]);
    $emit([]);
    $hcells=[];foreach($hdr_labels as $ci=>$h) $hcells[]=$sc($ci,$h,3);
    $emit($hcells,16);

    foreach($sec_defs as $sec){
        $bkt=$buckets[$sec['key']]; $lbl=$sec['label']; $tot=$sec_t[$sec['key']];
        $emit([$sc(0,$lbl,7),$bc(1,7),$bc(2,7),$bc(3,7),...($detail?[$bc(4,7)]:[])],14);
        if(empty($bkt)){
            $emit([$sc(0,__('pl_xls_no_transactions'),8)]);
            // Subtotal: blank debit/credit, only Net
            if($detail) $emit([$sc(0,$sub_lbl[$sec['key']],10),$bc(1,10),$bc(2,11),$bc(3,11),$nc(4,0.0,11)],14);
            else        $emit([$sc(0,$sub_lbl[$sec['key']],10),$bc(1,11),$bc(2,11),$nc(3,0.0,11)],14);
        } else {
            $alt=false;
            foreach($bkt as $item){
                $ts=$alt?5:8; $ns=$alt?6:9;
                if($detail) $emit([$sc(0,$item['code']??'',$ts),$sc(1,$item['name'],$ts),$nc(2,$item['debit'],$ns),$nc(3,$item['credit'],$ns),$nc(4,$item['net'],$ns)]);
                else        $emit([$sc(0,$item['name'],$ts),$nc(1,$item['debit'],$ns),$nc(2,$item['credit'],$ns),$nc(3,$item['net'],$ns)]);
                $alt=!$alt;
            }
            // Subtotal: blank debit/credit (matches HTML "–"), only Net shown
            if($detail) $emit([$sc(0,$sub_lbl[$sec['key']],10),$bc(1,10),$bc(2,11),$bc(3,11),$nc(4,$tot['net'],11)],14);
            else        $emit([$sc(0,$sub_lbl[$sec['key']],10),$bc(1,11),$bc(2,11),$nc(3,$tot['net'],11)],14);
        }
    }

    // Calculated rows: debit/credit blank except Net Profit which shows grand totals
    $e_calc=function(string $lbl,float $n,bool $show_dc,float $d,float $c,int $ht=16)use(&$emit,$sc,$bc,$nc,$detail,$COL_NET,$COL_DEBIT,$COL_CREDIT):void{
        if($detail) $emit([$sc(0,$lbl,12),$bc(1,12),$show_dc?$nc($COL_DEBIT,$d,13):$bc($COL_DEBIT,13),$show_dc?$nc($COL_CREDIT,$c,13):$bc($COL_CREDIT,13),$nc($COL_NET,$n,13)],$ht);
        else        $emit([$sc(0,$lbl,12),$show_dc?$nc($COL_DEBIT,$d,13):$bc($COL_DEBIT,13),$show_dc?$nc($COL_CREDIT,$c,13):$bc($COL_CREDIT,13),$nc($COL_NET,$n,13)],$ht);
    };
    $e_calc($calc_lbl['gross'], $gp,  false, 0.0,    0.0);
    $e_calc($calc_lbl['opinc'], $op,  false, 0.0,    0.0);
    $e_calc($calc_lbl['pbt'],   $pbt, false, 0.0,    0.0);
    $e_calc($calc_lbl['npat'],  $np,  true,  $g_dbt, $g_cdt, 18);

    $cw=$detail?[16,40,18,18,18]:[46,18,18,18];
    $ws='<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
      . '<sheetViews><sheetView tabSelected="1" workbookViewId="0"><pane ySplit="7" topLeftCell="A8" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
      . '<sheetFormatPr defaultRowHeight="15" customHeight="1"/><cols>';
    foreach($cw as $ci=>$w) $ws.='<col min="'.($ci+1).'" max="'.($ci+1).'" width="'.$w.'" customWidth="1"/>';
    $ws.='</cols><sheetData>'.$ws_rows.'</sheetData>'
       . '<pageSetup orientation="portrait" paperSize="9" fitToPage="1" fitToWidth="1" fitToHeight="0"/></worksheet>';

    $n_si=count($si_list);
    $sst='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.$n_si.'" uniqueCount="'.$n_si.'">';
    foreach($si_list as $s) $sst.='<si><t xml:space="preserve">'.$xv($s).'</t></si>';
    $sst.='</sst>';

    $xl='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
      . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0"/></numFmts>'
      . '<fonts count="7"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="14"/><color rgb="FF166C82"/><name val="Calibri"/></font><font><sz val="10"/><color rgb="FF555555"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="12"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><i/><sz val="10"/><color rgb="FFAAAAAA"/><name val="Calibri"/></font></fonts>'
      . '<fills count="8"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF166C82"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF0F7FF"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFCFE2FF"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1E3A5F"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0D4F60"/></patternFill></fill><fill><patternFill patternType="none"/></fill></fills>'
      . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFB0C4DE"/></left><right style="thin"><color rgb="FFB0C4DE"/></right><top style="thin"><color rgb="FFB0C4DE"/></top><bottom style="thin"><color rgb="FFB0C4DE"/></bottom><diagonal/></border></borders>'
      . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
      . '<cellXfs count="15">'
      . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'
      . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
      . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
      . '<xf numFmtId="0" fontId="3" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
      . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"><alignment horizontal="right"/></xf>'
      . '<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1"/>'
      . '<xf numFmtId="164" fontId="0" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1"><alignment horizontal="right"/></xf>'
      . '<xf numFmtId="0" fontId="3" fillId="6" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
      . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"><alignment indent="2"/></xf>'
      . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"><alignment horizontal="right"/></xf>'
      . '<xf numFmtId="0" fontId="4" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
      . '<xf numFmtId="164" fontId="4" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="right"/></xf>'
      . '<xf numFmtId="0" fontId="5" fillId="5" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
      . '<xf numFmtId="164" fontId="5" fillId="5" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1"><alignment horizontal="right"/></xf>'
      . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"><alignment horizontal="right"/></xf>'
      . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';

    _send_xlsx(_xlsx_package($ws,$sst,$xl,$detail?__('pl_xls_detail_sheet_name'):__('pl_xls_sheet_name')), ($detail?'Detail_PL':'Profit_Loss').'_'.date('Ymd').'.xlsx');
}

// ── Balance Sheet inline export ───────────────────────────────────────────────
function _export_bs(PDO $pdo, string $date_from, string $date_to, string $company_id, string $estate_id,
                    string $division_id, string $_company_display, string $report_type,
                    callable $xv, callable $fmtDate, callable $col_letter): void
{
    $detail=($report_type==='balance_sheet');
    $bs_cond=["je.status='posted'","je.entry_date<=:bs_date_to"]; $bs_p=[':bs_date_to'=>$date_to];
    if($company_id){$bs_cond[]="je.company_id=:company_id";$bs_p[':company_id']=$company_id;}
    if($estate_id){$bs_cond[]="je.business_unit_id=:estate_id";$bs_p[':estate_id']=$estate_id;}
    if($division_id){$bs_cond[]="je.division_id=:division_id";$bs_p[':division_id']=$division_id;}
    $bw=implode(' AND ',$bs_cond);

    if($detail){
        $sql="SELECT gla.id AS gl_id,gla.account_code,gla.account_name,gla.account_type,SUM(jel.debit_amount) AS td,SUM(jel.credit_amount) AS tc
              FROM journal_entries je JOIN journal_entry_lines jel ON jel.journal_entry_id=je.id
              JOIN general_ledger_accounts gla ON gla.id=jel.gl_account_id
              WHERE $bw AND gla.account_type IN('asset','liability','equity')
              GROUP BY gla.id,gla.account_code,gla.account_name,gla.account_type
              HAVING (SUM(jel.debit_amount)+SUM(jel.credit_amount))>0 ORDER BY gla.account_type,gla.account_code";
        $stmt=$pdo->prepare($sql);$stmt->execute($bs_p);$raw=$stmt->fetchAll();
        $pc=["je.status='posted'","je.entry_date>=:p_from","je.entry_date<=:p_to"]; $pp=[':p_from'=>$date_from,':p_to'=>$date_to];
        if($company_id){$pc[]="je.company_id=:company_id";$pp[':company_id']=$company_id;}
        if($estate_id){$pc[]="je.business_unit_id=:estate_id";$pp[':estate_id']=$estate_id;}
        if($division_id){$pc[]="je.division_id=:division_id";$pp[':division_id']=$division_id;}
        $pw=implode(' AND ',$pc);
        $sp=$pdo->prepare("SELECT jel.gl_account_id,gla.account_type,SUM(jel.debit_amount) AS td,SUM(jel.credit_amount) AS tc FROM journal_entries je JOIN journal_entry_lines jel ON jel.journal_entry_id=je.id JOIN general_ledger_accounts gla ON gla.id=jel.gl_account_id WHERE $pw AND gla.account_type IN('asset','liability','equity') GROUP BY jel.gl_account_id,gla.account_type");
        $sp->execute($pp); $pm=[];
        foreach($sp->fetchAll() as $pr){$d=(float)$pr['td'];$c=(float)$pr['tc'];$pm[$pr['gl_account_id']]=($pr['account_type']==='asset')?$d-$c:$c-$d;}
        $buckets=['asset'=>[],'liability'=>[],'equity'=>[]];
        foreach($raw as $r){$d=(float)$r['td'];$c=(float)$r['tc'];$b=($r['account_type']==='asset')?$d-$c:$c-$d;$m=$pm[$r['gl_id']]??0;$buckets[$r['account_type']][]=[ 'code'=>$r['account_code'],'name'=>$r['account_name'],'prev_bal'=>$b-$m,'debit'=>$d,'credit'=>$c,'balance'=>$b];}
    } else {
        $ags=$pdo->query("SELECT id,group_name,report_section,display_order FROM financial_account_groups WHERE report_type='balance_sheet' AND is_active=1 AND (is_total_line IS NULL OR is_total_line=0) ORDER BY display_order,group_code");
        $all_g=$ags?$ags->fetchAll():[];
        $se=$pdo->prepare("SELECT fag.id AS group_id,gla.account_type,SUM(jel.debit_amount) AS td,SUM(jel.credit_amount) AS tc FROM journal_entries je JOIN journal_entry_lines jel ON jel.journal_entry_id=je.id JOIN general_ledger_accounts gla ON gla.id=jel.gl_account_id JOIN financial_account_groups fag ON fag.id=gla.financial_group_id WHERE $bw AND gla.account_type IN('asset','liability','equity') AND fag.report_type='balance_sheet' AND fag.is_active=1 GROUP BY fag.id,gla.account_type HAVING (SUM(jel.debit_amount)+SUM(jel.credit_amount))>0");
        $se->execute($bs_p); $er=$se->fetchAll();
        $pc2=["je.status='posted'","je.entry_date>=:p_from","je.entry_date<=:p_to"]; $pp2=[':p_from'=>$date_from,':p_to'=>$date_to];
        if($company_id){$pc2[]="je.company_id=:company_id";$pp2[':company_id']=$company_id;}
        if($estate_id){$pc2[]="je.business_unit_id=:estate_id";$pp2[':estate_id']=$estate_id;}
        if($division_id){$pc2[]="je.division_id=:division_id";$pp2[':division_id']=$division_id;}
        $pw2=implode(' AND ',$pc2);
        $sp2=$pdo->prepare("SELECT fag.id AS group_id,gla.account_type,SUM(jel.debit_amount) AS td,SUM(jel.credit_amount) AS tc FROM journal_entries je JOIN journal_entry_lines jel ON jel.journal_entry_id=je.id JOIN general_ledger_accounts gla ON gla.id=jel.gl_account_id JOIN financial_account_groups fag ON fag.id=gla.financial_group_id WHERE $pw2 AND gla.account_type IN('asset','liability','equity') AND fag.report_type='balance_sheet' AND fag.is_active=1 GROUP BY fag.id,gla.account_type");
        $sp2->execute($pp2); $perm=[];
        foreach($sp2->fetchAll() as $pr){$d=(float)$pr['td'];$c=(float)$pr['tc'];$m=($pr['account_type']==='asset')?$d-$c:$c-$d;$perm[$pr['group_id']]=($perm[$pr['group_id']]??0)+$m;}
        $gb=[];
        foreach($er as $r){$d=(float)$r['td'];$c=(float)$r['tc'];$b=($r['account_type']==='asset')?$d-$c:$c-$d;if(!isset($gb[$r['group_id']])){$gb[$r['group_id']]=['balance'=>0,'debit'=>0,'credit'=>0,'account_type'=>$r['account_type']];}$gb[$r['group_id']]['balance']+=$b;$gb[$r['group_id']]['debit']+=$d;$gb[$r['group_id']]['credit']+=$c;}
        $buckets=['asset'=>[],'liability'=>[],'equity'=>[]];
        foreach($all_g as $g){$gid=$g['id'];if(!isset($gb[$gid]))continue;$at=$gb[$gid]['account_type'];if(!array_key_exists($at,$buckets))continue;$en=$gb[$gid]['balance'];$mv=$perm[$gid]??0;$buckets[$at][]=['name'=>$g['group_name'],'section'=>$g['report_section']??'','prev_bal'=>$en-$mv,'debit'=>$gb[$gid]['debit'],'credit'=>$gb[$gid]['credit'],'balance'=>$en];}
    }

    $ta=array_sum(array_column($buckets['asset'],'balance'));
    $tl=array_sum(array_column($buckets['liability'],'balance'));
    $te_accts=array_sum(array_column($buckets['equity'],'balance'));
    $cpl=$ta-($tl+$te_accts); $te=$te_accts+$cpl;

    // Pre-calculate all subtotal debit/credit so we never need Excel formulas
    $ta_d=array_sum(array_column($buckets['asset'],'debit'));
    $ta_c=array_sum(array_column($buckets['asset'],'credit'));
    $tl_d=array_sum(array_column($buckets['liability'],'debit'));
    $tl_c=array_sum(array_column($buckets['liability'],'credit'));
    $te_d=array_sum(array_column($buckets['equity'],'debit'));
    $te_c=array_sum(array_column($buckets['equity'],'credit'));
    $grand_d=$ta_d+$tl_d+$te_d; $grand_c=$ta_c+$tl_c+$te_c;

    $ta_p=array_sum(array_column($buckets['asset'],'prev_bal'));
    $tl_p=array_sum(array_column($buckets['liability'],'prev_bal'));
    $te_p_accts=array_sum(array_column($buckets['equity'],'prev_bal'));
    $cpl_p=$ta_p-($tl_p+$te_p_accts); $te_p=$te_p_accts+$cpl_p;
    $tle_p=$tl_p+$te_p; $tle=$tl+$te;

    $si_map=[]; $si_list=[];
    $si_add=function(string $s)use(&$si_map,&$si_list):int{if(!isset($si_map[$s])){$si_map[$s]=count($si_list);$si_list[]=$s;}return $si_map[$s];};

    $bs_title=$detail?'Balance Sheet (Detail)':'Balance Sheet (Grouped)';
    $as_at='As at: '.$fmtDate($date_to);
    $prd='Period: '.$fmtDate($date_from).' to '.$fmtDate($date_to).'  |  Previous Balance = balance before '.$fmtDate($date_from);
    foreach([$_company_display,$bs_title,$as_at,$prd] as $s) $si_add($s);

    $COL_BAL=$detail?5:3; $COL_PREV=2; $COL_D=$detail?3:-1; $COL_C=$detail?4:-1;
    $HB=$col_letter($COL_BAL); $HP=$col_letter($COL_PREV);
    $HDEBIT=$detail?$col_letter($COL_D):''; $HCRED=$detail?$col_letter($COL_C):'';

    $hdr_labels=$detail?['Account Code','Account Name','Previous Balance','Debit','Credit','Ending Balance']:['Report Section','Group','Previous Balance','Ending Balance'];
    foreach($hdr_labels as $h) $si_add($h);
    $sec_lbl=['asset'=>'ASSETS','liability'=>'LIABILITIES','equity'=>'EQUITY'];
    $sub_lbl=['asset'=>'TOTAL ASSETS','liability'=>'TOTAL LIABILITIES','equity'=>'TOTAL EQUITY (excl. current period P/L)'];
    foreach($sec_lbl as $l) $si_add($l); foreach($sub_lbl as $l) $si_add($l);
    foreach(['Current Period Profit / (Loss)','TOTAL EQUITY','TOTAL LIABILITIES + EQUITY','(no data)','—'] as $s) $si_add($s);

    $ws_rows=''; $rn=1;
    $emit=function(array $cells,int $ht=0)use(&$ws_rows,&$rn):void{$a=$ht>0?' ht="'.$ht.'" customHeight="1"':'';$ws_rows.='<row r="'.$rn.'"'.$a.'>';foreach($cells as $c)$ws_rows.=$c;$ws_rows.='</row>';$rn++;};
    $sc=function(int $col,string $val,int $st=0)use(&$rn,$col_letter,&$si_map,$si_add):string{$si_add($val);return '<c r="'.$col_letter($col).$rn.'" t="s" s="'.$st.'"><v>'.$si_map[$val].'</v></c>';};
    $nc=function(int $col,float $val,int $st=4)use(&$rn,$col_letter):string{return '<c r="'.$col_letter($col).$rn.'" s="'.$st.'"><v>'.$val.'</v></c>';};
    $fc=function(int $col,string $f,int $st=13)use(&$rn,$col_letter):string{return '<c r="'.$col_letter($col).$rn.'" s="'.$st.'"><f>'.htmlspecialchars($f,ENT_XML1).'</f></c>';};
    $bc=function(int $col,int $st=0)use(&$rn,$col_letter):string{return '<c r="'.$col_letter($col).$rn.'" s="'.$st.'"/>';};

    $emit([$sc(0,$_company_display,1)],18); $emit([$sc(0,$bs_title,1)],16);
    $emit([$sc(0,$as_at,2)]); $emit([$sc(0,$prd,2)]); $emit([]);
    $hcells=[];foreach($hdr_labels as $ci=>$h) $hcells[]=$sc($ci,$h,3); $emit($hcells,16);

    // Section total lookup by key
    $sec_totals_d=['asset'=>$ta_d,'liability'=>$tl_d,'equity'=>$te_d];
    $sec_totals_c=['asset'=>$ta_c,'liability'=>$tl_c,'equity'=>$te_c];
    $sec_totals_b=['asset'=>$ta, 'liability'=>$tl, 'equity'=>$te_accts];
    $sec_totals_p=['asset'=>$ta_p,'liability'=>$tl_p,'equity'=>$te_p_accts];

    foreach(['asset','liability','equity'] as $sec){
        $bkt=$buckets[$sec];
        $sc_cells=[$sc(0,$sec_lbl[$sec],7)]; for($ci=1;$ci<=$COL_BAL;$ci++) $sc_cells[]=$bc($ci,7); $emit($sc_cells,14);
        if(empty($bkt)){
            $emit([$sc(0,'(no data)',8)]);
            if($detail) $emit([$sc(0,$sub_lbl[$sec],10),$bc(1,10),$nc(2,0.0,11),$nc(3,0.0,11),$nc(4,0.0,11),$nc(5,0.0,11)],14);
            else        $emit([$sc(0,$sub_lbl[$sec],10),$bc(1,10),$nc(2,0.0,11),$nc(3,0.0,11)],14);
        } else {
            $alt=false; $cur=null;
            foreach($bkt as $item){
                if(!$detail&&isset($item['section'])&&$item['section']!==$cur){$cur=$item['section'];$si_add($cur);$emit([$sc(0,$cur,14),$bc(1,14),$bc(2,14),$bc(3,14)]);}
                $ts=$alt?5:8; $ns=$alt?6:9;
                if($detail) $emit([$sc(0,$item['code'],$ts),$sc(1,$item['name'],$ts),$nc(2,$item['prev_bal'],$ns),$nc(3,$item['debit'],$ns),$nc(4,$item['credit'],$ns),$nc(5,$item['balance'],$ns)]);
                else        $emit([$bc(0,$ts),$sc(1,$item['name'],$ts),$nc(2,$item['prev_bal'],$ns),$nc(3,$item['balance'],$ns)]);
                $alt=!$alt;
            }
            // Subtotal row — static PHP values, no Excel formula
            if($detail) $emit([$sc(0,$sub_lbl[$sec],10),$bc(1,10),$nc(2,$sec_totals_p[$sec],11),$nc(3,$sec_totals_d[$sec],11),$nc(4,$sec_totals_c[$sec],11),$nc(5,$sec_totals_b[$sec],11)],14);
            else        $emit([$sc(0,$sub_lbl[$sec],10),$bc(1,10),$nc(2,$sec_totals_p[$sec],11),$nc(3,$sec_totals_b[$sec],11)],14);
        }
    }

    // Current Period P/L row — static values
    if($detail) $emit([$sc(0,'—',8),$sc(1,'Current Period Profit / (Loss)',8),$nc(2,$cpl_p,9),$nc(3,0.0,9),$nc(4,0.0,9),$nc(5,$cpl,9)]);
    else        $emit([$bc(0,8),$sc(1,'Current Period Profit / (Loss)',8),$nc(2,$cpl_p,9),$nc(3,$cpl,9)]);

    // Total Equity row — static values
    if($detail) $emit([$sc(0,'TOTAL EQUITY',10),$bc(1,10),$nc(2,$te_p,11),$nc(3,$te_d,11),$nc(4,$te_c,11),$nc(5,$te,11)],14);
    else        $emit([$sc(0,'TOTAL EQUITY',10),$bc(1,10),$nc(2,$te_p,11),$nc(3,$te,11)],14);

    // Total Liabilities + Equity row — static values
    if($detail) $emit([$sc(0,'TOTAL LIABILITIES + EQUITY',12),$bc(1,12),$nc(2,$tle_p,13),$nc(3,$grand_d,13),$nc(4,$grand_c,13),$nc(5,$tle,13)],18);
    else        $emit([$sc(0,'TOTAL LIABILITIES + EQUITY',12),$bc(1,12),$nc(2,$tle_p,13),$nc(3,$tle,13)],18);

    $cw=$detail?[14,40,18,16,16,18]:[28,30,20,20];
    $ws='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheetViews><sheetView tabSelected="1" workbookViewId="0"><pane ySplit="6" topLeftCell="A7" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="15" customHeight="1"/><cols>';
    foreach($cw as $ci=>$w) $ws.='<col min="'.($ci+1).'" max="'.($ci+1).'" width="'.$w.'" customWidth="1"/>';
    $ws.='</cols><sheetData>'.$ws_rows.'</sheetData><pageSetup orientation="portrait" paperSize="9" fitToPage="1" fitToWidth="1" fitToHeight="0"/></worksheet>';

    $n_si=count($si_list);
    $sst='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.$n_si.'" uniqueCount="'.$n_si.'">';
    foreach($si_list as $s) $sst.='<si><t xml:space="preserve">'.$xv($s).'</t></si>'; $sst.='</sst>';

    $xl='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0"/></numFmts><fonts count="7"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="14"/><color rgb="FF166C82"/><name val="Calibri"/></font><font><sz val="10"/><color rgb="FF555555"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="12"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><i/><sz val="10"/><color rgb="FFAAAAAA"/><name val="Calibri"/></font></fonts><fills count="9"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF166C82"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF0F7FF"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFCFE2FF"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1E3A5F"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0D4F60"/></patternFill></fill><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF8F9FA"/></patternFill></fill></fills><borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFB0C4DE"/></left><right style="thin"><color rgb="FFB0C4DE"/></right><top style="thin"><color rgb="FFB0C4DE"/></top><bottom style="thin"><color rgb="FFB0C4DE"/></bottom><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="15"><xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="0" fontId="3" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"><alignment horizontal="right"/></xf><xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1"/><xf numFmtId="164" fontId="0" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1"><alignment horizontal="right"/></xf><xf numFmtId="0" fontId="3" fillId="6" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"><alignment indent="2"/></xf><xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"><alignment horizontal="right"/></xf><xf numFmtId="0" fontId="4" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/><xf numFmtId="164" fontId="4" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="right"/></xf><xf numFmtId="0" fontId="5" fillId="5" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="164" fontId="5" fillId="5" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1"><alignment horizontal="right"/></xf><xf numFmtId="0" fontId="6" fillId="8" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';

    _send_xlsx(_xlsx_package($ws,$sst,$xl,$detail?'Balance Sheet':'BS Grouped'), ($detail?'Balance_Sheet_Detail':'Balance_Sheet_Grouped').'_'.date('Ymd').'.xlsx');
}

// ── Trial Balance inline export ───────────────────────────────────────────────
function _export_tb(PDO $pdo, string $date_from, string $date_to,
                    string $company_id, string $estate_id, string $division_id,
                    string $_company_display, callable $xv, callable $fmtDate, callable $col_letter): void
{
    // ── org filter ──────────────────────────────────────────────────────────
    $org = ''; $orgp = [];
    if ($company_id)  { $org .= " AND je.company_id = :company_id";        $orgp[':company_id']  = $company_id; }
    if ($estate_id)   { $org .= " AND je.business_unit_id = :estate_id";   $orgp[':estate_id']   = $estate_id; }
    if ($division_id) { $org .= " AND je.division_id = :division_id";      $orgp[':division_id'] = $division_id; }

    $day_before  = date('Y-m-d', strtotime($date_from . ' -1 day'));
    $year_start  = substr($date_from, 0, 4) . '-01-01';

    $bs_types = ['asset','liability','equity'];
    $pl_types = ['revenue','cogs','operating_expense','expense','other_income','other_expenses','tax'];
    $bs_tl    = "'" . implode("','", $bs_types) . "'";
    $pl_tl    = "'" . implode("','", $pl_types) . "'";

    // ── A1 BS ending (≤ date_to) ────────────────────────────────────────────
    $s = $pdo->prepare("SELECT gla.id AS gl_id,gla.account_code,gla.account_name,gla.account_type,
                                SUM(jel.debit_amount) AS td,SUM(jel.credit_amount) AS tc
                         FROM journal_entries je
                         JOIN journal_entry_lines jel ON jel.journal_entry_id=je.id
                         JOIN general_ledger_accounts gla ON gla.id=jel.gl_account_id
                         WHERE je.status='posted' AND je.entry_date<=:bs_to
                           AND gla.account_type IN($bs_tl) $org
                         GROUP BY gla.id,gla.account_code,gla.account_name,gla.account_type
                         HAVING (SUM(jel.debit_amount)+SUM(jel.credit_amount))>0");
    $s->execute(array_merge([':bs_to'=>$date_to],$orgp));
    $bs_end = $s->fetchAll(PDO::FETCH_ASSOC);

    // ── A2 BS period movement ───────────────────────────────────────────────
    $s = $pdo->prepare("SELECT gla.id AS gl_id,SUM(jel.debit_amount) AS pd,SUM(jel.credit_amount) AS pc
                         FROM journal_entries je
                         JOIN journal_entry_lines jel ON jel.journal_entry_id=je.id
                         JOIN general_ledger_accounts gla ON gla.id=jel.gl_account_id
                         WHERE je.status='posted' AND je.entry_date>=:f AND je.entry_date<=:t
                           AND gla.account_type IN($bs_tl) $org
                         GROUP BY gla.id");
    $s->execute(array_merge([':f'=>$date_from,':t'=>$date_to],$orgp));
    $bs_mov = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r)
        $bs_mov[$r['gl_id']] = (float)$r['pd'] - (float)$r['pc'];

    // ── B1 P&L current period ───────────────────────────────────────────────
    $s = $pdo->prepare("SELECT gla.id AS gl_id,gla.account_code,gla.account_name,gla.account_type,
                                SUM(jel.debit_amount) AS td,SUM(jel.credit_amount) AS tc
                         FROM journal_entries je
                         JOIN journal_entry_lines jel ON jel.journal_entry_id=je.id
                         JOIN general_ledger_accounts gla ON gla.id=jel.gl_account_id
                         WHERE je.status='posted' AND je.entry_date>=:f AND je.entry_date<=:t
                           AND gla.account_type IN($pl_tl) $org
                         GROUP BY gla.id,gla.account_code,gla.account_name,gla.account_type
                         HAVING (SUM(jel.debit_amount)+SUM(jel.credit_amount))>0");
    $s->execute(array_merge([':f'=>$date_from,':t'=>$date_to],$orgp));
    $pl_cur = $s->fetchAll(PDO::FETCH_ASSOC);

    // ── B2 P&L previous balance ─────────────────────────────────────────────
    $pl_prev = []; $pl_prev_meta = [];
    if ($year_start <= $day_before) {
        $s = $pdo->prepare("SELECT gla.id AS gl_id,gla.account_code,gla.account_name,gla.account_type,
                                    SUM(jel.debit_amount) AS pd,SUM(jel.credit_amount) AS pc
                             FROM journal_entries je
                             JOIN journal_entry_lines jel ON jel.journal_entry_id=je.id
                             JOIN general_ledger_accounts gla ON gla.id=jel.gl_account_id
                             WHERE je.status='posted' AND je.entry_date>=:yr AND je.entry_date<=:db
                               AND gla.account_type IN($pl_tl) $org
                             GROUP BY gla.id,gla.account_code,gla.account_name,gla.account_type");
        $s->execute(array_merge([':yr'=>$year_start,':db'=>$day_before],$orgp));
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $pl_prev[$r['gl_id']]      = (float)$r['pd'] - (float)$r['pc'];
            $pl_prev_meta[$r['gl_id']] = ['code'=>$r['account_code'],'name'=>$r['account_name'],'type'=>$r['account_type']];
        }
    }

    // ── Build account rows ──────────────────────────────────────────────────
    $accounts = [];
    $type_labels = ['asset'=>'Asset','liability'=>'Liability','equity'=>'Equity',
                    'revenue'=>'Revenue','cogs'=>'COGS','operating_expense'=>'Opex',
                    'expense'=>'Expense','other_income'=>'Other Income',
                    'other_expenses'=>'Other Expenses','tax'=>'Tax'];

    // BS rows
    foreach ($bs_end as $row) {
        $gid    = $row['gl_id'];
        $ending = (float)$row['td'] - (float)$row['tc'];
        $prev   = $ending - ($bs_mov[$gid] ?? 0);
        $accounts[$gid] = ['code'=>$row['account_code'],'name'=>$row['account_name'],'type'=>$row['account_type'],
                           'prev'=>$prev,'debit'=>0,'credit'=>0,'ending'=>$ending];
    }
    // BS period gross debit/credit
    $s = $pdo->prepare("SELECT gla.id AS gl_id,SUM(jel.debit_amount) AS pd,SUM(jel.credit_amount) AS pc
                         FROM journal_entries je
                         JOIN journal_entry_lines jel ON jel.journal_entry_id=je.id
                         JOIN general_ledger_accounts gla ON gla.id=jel.gl_account_id
                         WHERE je.status='posted' AND je.entry_date>=:f AND je.entry_date<=:t
                           AND gla.account_type IN($bs_tl) $org
                         GROUP BY gla.id");
    $s->execute(array_merge([':f'=>$date_from,':t'=>$date_to],$orgp));
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (isset($accounts[$r['gl_id']])) {
            $accounts[$r['gl_id']]['debit']  = (float)$r['pd'];
            $accounts[$r['gl_id']]['credit'] = (float)$r['pc'];
        }
    }
    // P&L current period rows
    foreach ($pl_cur as $row) {
        $gid    = $row['gl_id'];
        $dbt    = (float)$row['td'];
        $cdt    = (float)$row['tc'];
        $prev   = $pl_prev[$gid] ?? 0;
        $accounts[$gid] = ['code'=>$row['account_code'],'name'=>$row['account_name'],'type'=>$row['account_type'],
                           'prev'=>$prev,'debit'=>$dbt,'credit'=>$cdt,'ending'=>$prev+$dbt-$cdt];
    }
    // P&L prior-period-only rows
    foreach ($pl_prev as $gid => $prev) {
        if (isset($accounts[$gid])) continue;
        $accounts[$gid] = ['code'=>$pl_prev_meta[$gid]['code'],'name'=>$pl_prev_meta[$gid]['name'],
                           'type'=>$pl_prev_meta[$gid]['type'],'prev'=>$prev,'debit'=>0,'credit'=>0,'ending'=>$prev];
    }

    usort($accounts, fn($a,$b) => strcmp($a['code'],$b['code']));

    // Totals
    $g_prev=0; $g_dbt=0; $g_cdt=0; $g_end=0;
    foreach ($accounts as $a) { $g_prev+=$a['prev']; $g_dbt+=$a['debit']; $g_cdt+=$a['credit']; $g_end+=$a['ending']; }

    $n = fn(float $v):float => round($v,0);

    // ── Build XLSX ──────────────────────────────────────────────────────────
    $si_map=[]; $si_list=[];
    $si_add=function(string $s)use(&$si_map,&$si_list):int{
        if(!isset($si_map[$s])){$si_map[$s]=count($si_list);$si_list[]=$s;}
        return $si_map[$s];
    };

    $title_str  = 'Trial Balance';
    $period_str = 'Period: '.$fmtDate($date_from).' to '.$fmtDate($date_to);
    foreach([$_company_display,$title_str,$period_str] as $s) $si_add($s);

    $hdr = ['Code','Account','Type','Previous Balance','Debit','Credit','Ending Balance'];
    foreach ($hdr as $h) $si_add($h);
    foreach ($type_labels as $l) $si_add($l);
    $si_add('GRAND TOTAL');

    $ws_rows=''; $rn=1;
    $emit=function(array $cells,int $ht=0)use(&$ws_rows,&$rn):void{
        $a=$ht>0?' ht="'.$ht.'" customHeight="1"':'';
        $ws_rows.='<row r="'.$rn.'"'.$a.'>'.implode('',$cells).'</row>'; $rn++;
    };
    $sc=function(int $col,string $val,int $st=0)use(&$rn,$col_letter,&$si_map,$si_add):string{
        $si_add($val); return '<c r="'.$col_letter($col).$rn.'" t="s" s="'.$st.'"><v>'.$si_map[$val].'</v></c>';
    };
    $nc=function(int $col,float $val,int $st=4)use(&$rn,$col_letter):string{
        return '<c r="'.$col_letter($col).$rn.'" s="'.$st.'"><v>'.$val.'</v></c>';
    };
    $bc=function(int $col,int $st=0)use(&$rn,$col_letter):string{
        return '<c r="'.$col_letter($col).$rn.'" s="'.$st.'"/>';
    };

    // Header rows
    $emit([$sc(0,$_company_display,1)],18);
    $emit([$sc(0,$title_str,1)],16);
    $emit([$sc(0,$period_str,2)]);
    $emit([]);
    $hcells=[]; foreach($hdr as $ci=>$h) $hcells[]=$sc($ci,$h,3);
    $emit($hcells,16);

    // Data rows
    $alt=false;
    foreach ($accounts as $acc) {
        $ts=$alt?5:8; $ns=$alt?6:9;
        $tl=$type_labels[$acc['type']] ?? ucfirst(str_replace('_',' ',$acc['type']));
        $si_add($tl);
        $emit([
            $sc(0,$acc['code'],$ts),
            $sc(1,$acc['name'],$ts),
            $sc(2,$tl,$ts),
            $acc['prev']  != 0 ? $nc(3,$n($acc['prev']),  $ns) : $bc(3,$ns),
            $acc['debit'] > 0  ? $nc(4,$n($acc['debit']),  $ns) : $bc(4,$ns),
            $acc['credit']> 0  ? $nc(5,$n($acc['credit']), $ns) : $bc(5,$ns),
            $acc['ending']!= 0 ? $nc(6,$n($acc['ending']), $ns) : $bc(6,$ns),
        ]);
        $alt=!$alt;
    }

    // Grand total row
    $emit([
        $sc(0,'GRAND TOTAL',10),
        $bc(1,10),$bc(2,10),
        $nc(3,$n($g_prev),11),
        $nc(4,$n($g_dbt),11),
        $nc(5,$n($g_cdt),11),
        $nc(6,$n($g_end),11),
    ],14);

    // ── worksheet XML ────────────────────────────────────────────────────────
    $cw=[12,38,12,18,16,16,18];
    $ws='<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
      . '<sheetViews><sheetView tabSelected="1" workbookViewId="0"><pane ySplit="5" topLeftCell="A6" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
      . '<sheetFormatPr defaultRowHeight="15" customHeight="1"/><cols>';
    foreach($cw as $ci=>$w) $ws.='<col min="'.($ci+1).'" max="'.($ci+1).'" width="'.$w.'" customWidth="1"/>';
    $ws.='</cols><sheetData>'.$ws_rows.'</sheetData>'
       . '<pageSetup orientation="landscape" paperSize="9" fitToPage="1" fitToWidth="1" fitToHeight="0"/></worksheet>';

    // ── shared strings ───────────────────────────────────────────────────────
    $n_si=count($si_list);
    $sst='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.$n_si.'" uniqueCount="'.$n_si.'">';
    foreach($si_list as $s_) $sst.='<si><t xml:space="preserve">'.$xv($s_).'</t></si>';
    $sst.='</sst>';

    // ── styles (reuse BS style palette) ─────────────────────────────────────
    $xl='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
      . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0"/></numFmts>'
      . '<fonts count="7"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="14"/><color rgb="FF166C82"/><name val="Calibri"/></font><font><sz val="10"/><color rgb="FF555555"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="12"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><i/><sz val="10"/><color rgb="FFAAAAAA"/><name val="Calibri"/></font></fonts>'
      . '<fills count="8"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF166C82"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF0F7FF"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFCFE2FF"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1E3A5F"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0D4F60"/></patternFill></fill><fill><patternFill patternType="none"/></fill></fills>'
      . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFB0C4DE"/></left><right style="thin"><color rgb="FFB0C4DE"/></right><top style="thin"><color rgb="FFB0C4DE"/></top><bottom style="thin"><color rgb="FFB0C4DE"/></bottom><diagonal/></border></borders>'
      . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
      . '<cellXfs count="12">'
      . '<xf numFmtId="0"   fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'                                                                          // 0 plain bordered
      . '<xf numFmtId="0"   fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                                                                            // 1 title teal bold
      . '<xf numFmtId="0"   fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                                                                            // 2 subtitle muted
      . '<xf numFmtId="0"   fontId="3" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'                                                              // 3 header white-on-teal
      . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"><alignment horizontal="right"/></xf>'                  // 4 num plain
      . '<xf numFmtId="0"   fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1"/>'                                                            // 5 str alt-row
      . '<xf numFmtId="164" fontId="0" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1"><alignment horizontal="right"/></xf>'   // 6 num alt-row
      . '<xf numFmtId="0"   fontId="5" fillId="6" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'                                                              // 7 section header
      . '<xf numFmtId="0"   fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'                                                                          // 8 str plain row
      . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"><alignment horizontal="right"/></xf>'                  // 9 num plain row
      . '<xf numFmtId="0"   fontId="4" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'                                              // 10 total str
      . '<xf numFmtId="164" fontId="4" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="right"/></xf>'  // 11 total num
      . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';

    _send_xlsx(_xlsx_package($ws,$sst,$xl,'Trial Balance'), 'Trial_Balance_'.$fmtDate($date_from).'_to_'.$fmtDate($date_to).'.xlsx');
}



// ── Generic Excel + PDF inline export ────────────────────────────────────────
function _export_generic(string $export_type, string $title, array $headers, array $rows,
                          string $safe_filename, callable $fmtDate, string $date_from, string $date_to,
                          string $_company_display, callable $xv): void
{
    if ($export_type === 'excel') {
        if (ob_get_level()) ob_end_clean();
        $col_letter=function(int $n):string{$s='';for($i=$n;$i>=0;$i=intdiv($i,26)-1){$s=chr(65+$i%26).$s;}return $s;};
        $col_count=count($headers); $is_num=array_fill(0,$col_count,true);
        foreach($rows as $row) foreach($row as $ci=>$cell) if(!is_numeric($cell)||preg_match('/%/',(string)$cell)) $is_num[$ci]=false;
        $totals=[];foreach($headers as $ci=>$h){$vals=array_column($rows,$ci);$totals[$ci]=$is_num[$ci]?array_sum($vals):null;}
        $sm=[]; $sl=[];
        $sa=function(string $s)use(&$sm,&$sl):int{if(!isset($sm[$s])){$sm[$s]=count($sl);$sl[]=$s;}return $sm[$s];};
        $ps='Period: '.$fmtDate($date_from).' to '.$fmtDate($date_to);
        $sa($_company_display);$sa($title);$sa($ps);$sa('GRAND TOTAL');
        foreach($headers as $h) $sa($h);
        foreach($rows as $row) foreach($row as $ci=>$cell) if(!$is_num[$ci]) $sa((string)$cell);
        $ws='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheetViews><sheetView tabSelected="1" workbookViewId="0"><pane ySplit="5" topLeftCell="A6" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="15" customHeight="1"/><sheetData>';
        $rn=1;
        $ws.='<row r="'.$rn.'"><c r="A'.$rn.'" t="s" s="1"><v>'.$sm[$_company_display].'</v></c></row>';$rn++;
        $ws.='<row r="'.$rn.'"><c r="A'.$rn.'" t="s" s="1"><v>'.$sm[$title].'</v></c></row>';$rn++;
        $ws.='<row r="'.$rn.'"><c r="A'.$rn.'" t="s" s="2"><v>'.$sm[$ps].'</v></c></row>';$rn++;
        $ws.='<row r="'.$rn.'"></row>';$rn++;
        $ws.='<row r="'.$rn.'">';foreach($headers as $ci=>$h) $ws.='<c r="'.$col_letter($ci).$rn.'" t="s" s="3"><v>'.$sm[$h].'</v></c>';$ws.='</row>';$rn++;
        foreach($rows as $di=>$row){$alt=($di%2===1);$ws.='<row r="'.$rn.'">';foreach($row as $ci=>$cell){$ref=$col_letter($ci).$rn;if($is_num[$ci]){$s=$alt?6:4;$ws.='<c r="'.$ref.'" s="'.$s.'"><v>'.(float)$cell.'</v></c>';}else{$s=$alt?5:0;$ws.='<c r="'.$ref.'" t="s" s="'.$s.'"><v>'.$sm[(string)$cell].'</v></c>';}}$ws.='</row>';$rn++;}
        if(!empty($rows)){$ws.='<row r="'.$rn.'">';foreach($headers as $ci=>$h){$ref=$col_letter($ci).$rn;if($ci===0){$ws.='<c r="'.$ref.'" t="s" s="7"><v>'.$sm['GRAND TOTAL'].'</v></c>';}elseif($totals[$ci]!==null){$ws.='<c r="'.$ref.'" s="8"><v>'.(float)$totals[$ci].'</v></c>';}else{$ws.='<c r="'.$ref.'" s="7"></c>';}}$ws.='</row>';$rn++;}
        $ws.='</sheetData><pageSetup orientation="landscape" fitToPage="1" fitToWidth="1" fitToHeight="0"/></worksheet>';
        $n_si=count($sl);$sst='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.$n_si.'" uniqueCount="'.$n_si.'">';foreach($sl as $s) $sst.='<si><t xml:space="preserve">'.$xv($s).'</t></si>';$sst.='</sst>';
        $xl='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0"/></numFmts><fonts count="5"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="14"/><color rgb="FF166C82"/><name val="Calibri"/></font><font><sz val="10"/><color rgb="FF555555"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF166C82"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF0F7FF"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFCFE2FF"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="9"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="0" fontId="3" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="0" fontId="0" fillId="3" borderId="0" xfId="0" applyFill="1"/><xf numFmtId="164" fontId="0" fillId="3" borderId="0" xfId="0" applyNumberFormat="1" applyFill="1"/><xf numFmtId="0" fontId="4" fillId="4" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="164" fontId="4" fillId="4" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
        _send_xlsx(_xlsx_package($ws,$sst,$xl,$title), $safe_filename.'_'.date('Ymd').'.xlsx');
    } else {
        header('Content-Type: text/html; charset=UTF-8');
        $col_count=count($headers); $is_num=array_fill(0,$col_count,true);
        foreach($rows as $row) foreach($row as $ci=>$cell) if(!is_numeric($cell)||preg_match('/%/',(string)$cell)) $is_num[$ci]=false;
        $totals=[];foreach($headers as $ci=>$h){$vals=array_column($rows,$ci);$totals[$ci]=$is_num[$ci]?array_sum($vals):null;}
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>'.htmlspecialchars($title).'</title><style>*{box-sizing:border-box}body{font-family:"Segoe UI",Arial,sans-serif;font-size:10pt;color:#1a1a1a;margin:20px;background:#fff}.rh{text-align:center;border-bottom:2px solid #166c82;padding-bottom:10px;margin-bottom:18px}.rc{font-size:13pt;font-weight:700;color:#166c82}.rt{font-size:15pt;font-weight:700;margin:4px 0 2px}.rp{font-size:10pt;color:#555}table{width:100%;border-collapse:collapse;font-size:8.5pt}th{background:#166c82!important;color:#fff!important;font-weight:700;padding:4px 6px;border:1px solid #0e5060;text-align:left;-webkit-print-color-adjust:exact;print-color-adjust:exact}td{padding:3px 6px;border:1px solid #ccc}tr:nth-child(even) td{background:#f0f7ff}.num{text-align:right}.tr td{background:#cfe2ff!important;font-weight:700;border-top:2px solid #3065b0;-webkit-print-color-adjust:exact;print-color-adjust:exact}.np{margin-bottom:16px}@page{size:A4 landscape;margin:12mm 10mm}@media print{.np{display:none!important}body{margin:0}}</style></head><body>';
        echo '<div class="np"><button onclick="window.print()" style="padding:6px 18px;background:#166c82;color:#fff;border:none;border-radius:4px;font-size:10pt;cursor:pointer;">Print / Save as PDF</button><button onclick="window.close()" style="margin-left:8px;padding:6px 14px;background:#6c757d;color:#fff;border:none;border-radius:4px;font-size:10pt;cursor:pointer;">Close</button></div>';
        echo '<div class="rh"><div class="rc">'.htmlspecialchars($_company_display).'</div><div class="rt">'.htmlspecialchars($title).'</div><div class="rp">Period: <strong>'.htmlspecialchars($fmtDate($date_from)).'</strong> &rarr; <strong>'.htmlspecialchars($fmtDate($date_to)).'</strong></div></div>';
        echo '<table><thead><tr>';foreach($headers as $ci=>$h) echo '<th'.($is_num[$ci]?' class="num"':'').'>'.htmlspecialchars($h).'</th>';echo '</tr></thead><tbody>';
        foreach($rows as $row){echo '<tr>';foreach($row as $ci=>$cell){echo '<td'.($is_num[$ci]?' class="num"':'').'>';echo $is_num[$ci]?number_format((float)$cell,0,',','.'):htmlspecialchars((string)$cell);echo '</td>';}echo '</tr>';}
        if(!empty($rows)){echo '<tr class="tr">';foreach($headers as $ci=>$h){echo '<td'.($is_num[$ci]?' class="num"':'').'>';if($ci===0) echo 'GRAND TOTAL';elseif($totals[$ci]!==null) echo number_format($totals[$ci],0,',','.');echo '</td>';}echo '</tr>';}
        echo '</tbody></table></body></html>';
        exit;
    }
}


// ── General Ledger Excel export ───────────────────────────────────────────────
function _export_gl(PDO $pdo, string $date_from, string $date_to,
                    string $company_id, string $estate_id, string $division_id,
                    string $gl_acct_from, string $gl_acct_to,
                    string $company_display, callable $xv, callable $fmtDate, callable $col_letter): void
{
    if (ob_get_level()) ob_end_clean();

    $bs_types = ['asset','liability','equity'];
    $pl_types = ['revenue','cogs','operating_expense','expense','other_income','other_expenses','tax'];
    $bs_tl    = "'" . implode("','", $bs_types) . "'";
    $pl_tl    = "'" . implode("','", $pl_types) . "'";

    $year_start = substr($date_from, 0, 4) . '-01-01';
    $day_before = date('Y-m-d', strtotime($date_from . ' -1 day'));

    // ── Org + account-range filter ────────────────────────────────────────────
    $org = ''; $orgp = [];
    if ($company_id)  { $org .= " AND je.company_id = :company_id";       $orgp[':company_id']  = $company_id; }
    if ($estate_id)   { $org .= " AND je.business_unit_id = :estate_id";  $orgp[':estate_id']   = $estate_id; }
    if ($division_id) { $org .= " AND je.division_id = :division_id";     $orgp[':division_id'] = $division_id; }
    if ($gl_acct_from !== '' && $gl_acct_to !== '') {
        $org .= " AND gla.account_code BETWEEN :gl_acct_from AND :gl_acct_to";
        $orgp[':gl_acct_from'] = $gl_acct_from;
        $orgp[':gl_acct_to']   = $gl_acct_to;
    } elseif ($gl_acct_from !== '') {
        $org .= " AND gla.account_code >= :gl_acct_from";
        $orgp[':gl_acct_from'] = $gl_acct_from;
    } elseif ($gl_acct_to !== '') {
        $org .= " AND gla.account_code <= :gl_acct_to";
        $orgp[':gl_acct_to'] = $gl_acct_to;
    }

    // ── Opening balances ──────────────────────────────────────────────────────
    $ob_bs = [];
    $st = $pdo->prepare("SELECT gla.id AS gl_id, SUM(jel.debit_amount) AS d, SUM(jel.credit_amount) AS c
        FROM journal_entries je
        JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
        JOIN general_ledger_accounts gla ON gla.id = jel.gl_account_id
        WHERE je.status='posted' AND je.entry_date <= :ob_before
          AND gla.account_type IN ($bs_tl) $org GROUP BY gla.id");
    $st->execute(array_merge([':ob_before' => $day_before], $orgp));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ob_bs[$r['gl_id']] = (float)$r['d'] - (float)$r['c'];
    }

    $ob_pl = [];
    if ($year_start <= $day_before) {
        $st = $pdo->prepare("SELECT gla.id AS gl_id, SUM(jel.debit_amount) AS d, SUM(jel.credit_amount) AS c
            FROM journal_entries je
            JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
            JOIN general_ledger_accounts gla ON gla.id = jel.gl_account_id
            WHERE je.status='posted' AND je.entry_date >= :yr_start AND je.entry_date <= :ob_before
              AND gla.account_type IN ($pl_tl) $org GROUP BY gla.id");
        $st->execute(array_merge([':yr_start' => $year_start, ':ob_before' => $day_before], $orgp));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ob_pl[$r['gl_id']] = (float)$r['d'] - (float)$r['c'];
        }
    }

    // ── Period lines ──────────────────────────────────────────────────────────
    $st = $pdo->prepare("
        SELECT gla.id AS gl_id, gla.account_code, gla.account_name, gla.account_type,
               je.entry_date, je.reference_number,
               COALESCE(NULLIF(jel.description,''), je.description) AS line_desc,
               je.entry_type,
               d.division_name, b.block_code, a.activity_code,
               jel.debit_amount, jel.credit_amount
        FROM journal_entries je
        JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
        JOIN general_ledger_accounts gla ON gla.id = jel.gl_account_id
        LEFT JOIN divisions d ON je.division_id = d.division_id
        LEFT JOIN blocks    b ON jel.block_id    = b.block_id
        LEFT JOIN activities a ON jel.activity_id = a.id
        WHERE je.status='posted' AND je.entry_date >= :df AND je.entry_date <= :dt $org
        ORDER BY gla.account_code, je.entry_date, je.reference_number, jel.line_number");
    $st->execute(array_merge([':df' => $date_from, ':dt' => $date_to], $orgp));
    $raw = $st->fetchAll(PDO::FETCH_ASSOC);

    // ── Group by account ──────────────────────────────────────────────────────
    $accounts = [];
    $all_accts_q = $pdo->query("SELECT id, account_code, account_name, account_type FROM general_ledger_accounts ORDER BY account_code");
    $acct_meta = [];
    foreach ($all_accts_q->fetchAll(PDO::FETCH_ASSOC) as $a) { $acct_meta[$a['id']] = $a; }

    foreach ($raw as $row) {
        $gid = $row['gl_id'];
        if (!isset($accounts[$gid])) {
            $accounts[$gid] = ['code'=>$row['account_code'],'name'=>$row['account_name'],
                               'type'=>$row['account_type'],'lines'=>[]];
        }
        $accounts[$gid]['lines'][] = $row;
    }
    // Include accounts with opening balance only
    foreach (array_keys($ob_bs + $ob_pl) as $gid) {
        if (!isset($accounts[$gid]) && isset($acct_meta[$gid])) {
            $m = $acct_meta[$gid];
            $accounts[$gid] = ['code'=>$m['account_code'],'name'=>$m['account_name'],
                               'type'=>$m['account_type'],'lines'=>[]];
        }
    }
    uasort($accounts, fn($a,$b) => strcmp($a['code'],$b['code']));

    // ── Build XLSX ────────────────────────────────────────────────────────────
    $si_map=[]; $si_list=[];
    $si_add=function(string $s)use(&$si_map,&$si_list):int{
        if(!isset($si_map[$s])){$si_map[$s]=count($si_list);$si_list[]=$s;}
        return $si_map[$s];
    };

    $title_str  = 'General Ledger';
    $period_str = 'Period: '.$fmtDate($date_from).' to '.$fmtDate($date_to);
    foreach([$company_display,$title_str,$period_str,'Opening Balance','Subtotal','Grand Total'] as $s) $si_add($s);

    $hdr = ['Date','Journal No.','Description','Type','Division','Block','Activity','Debit','Credit','Balance'];
    foreach ($hdr as $h) $si_add($h);

    $ws_rows=''; $rn=1;
    $emit=function(array $cells,int $ht=0)use(&$ws_rows,&$rn):void{
        $a=$ht>0?' ht="'.$ht.'" customHeight="1"':'';
        $ws_rows.='<row r="'.$rn.'"'.$a.'>'.implode('',$cells).'</row>'; $rn++;
    };
    // sc = string cell, nc = number cell, bc = blank cell
    $sc=function(int $col,string $val,int $st=0)use(&$rn,$col_letter,&$si_map,$si_add):string{
        $si_add($val); return '<c r="'.$col_letter($col).$rn.'" t="s" s="'.$st.'"><v>'.$si_map[$val].'</v></c>';
    };
    $nc=function(int $col,float $val,int $st=4)use(&$rn,$col_letter):string{
        return '<c r="'.$col_letter($col).$rn.'" s="'.$st.'"><v>'.$val.'</v></c>';
    };
    $bc=function(int $col,int $st=0)use(&$rn,$col_letter):string{
        return '<c r="'.$col_letter($col).$rn.'" s="'.$st.'"/>';
    };

    // Header rows
    $emit([$sc(0,$company_display,1)],18);
    $emit([$sc(0,$title_str,1)],16);
    $emit([$sc(0,$period_str,2)]);
    $emit([]);
    // Grand totals accumulators
    $g_dr=0; $g_cr=0;

    foreach ($accounts as $gid => $acc) {
        $ob  = $ob_bs[$gid] ?? ($ob_pl[$gid] ?? 0.0);
        $dr  = array_sum(array_column($acc['lines'],'debit_amount'));
        $cr  = array_sum(array_column($acc['lines'],'credit_amount'));
        $cl  = $ob + $dr - $cr;
        $g_dr += $dr; $g_cr += $cr;

        // Account header row
        $acct_label = $acc['code'].' — '.$acc['name'].' ('.$acc['type'].')';
        $si_add($acct_label);
        $emit([$sc(0,$acct_label,7)],14);

        // Column headers
        $hcells=[]; foreach($hdr as $ci=>$h) $hcells[]=$sc($ci,$h,3); $emit($hcells,13);

        // Opening balance row
        $emit([
            $sc(0,'Opening Balance',12),
            $bc(1,0),$bc(2,0),$bc(3,0),$bc(4,0),$bc(5,0),$bc(6,0),
            $bc(7,0),$bc(8,0),
            $ob != 0 ? $nc(9,$ob,5) : $bc(9,0),
        ]);

        // Transaction lines
        $running = $ob;
        $alt = false;
        foreach ($acc['lines'] as $ln) {
            $running += (float)$ln['debit_amount'] - (float)$ln['credit_amount'];
            $ts=$alt?8:0; $ns=$alt?6:4;
            $si_add($fmtDate($ln['entry_date']));
            $si_add($ln['reference_number'] ?? '');
            $si_add($ln['line_desc'] ?? '');
            $si_add(ucfirst(str_replace('_',' ',$ln['entry_type'] ?? '')));
            $si_add($ln['division_name'] ?? '');
            $si_add($ln['block_code'] ?? '');
            $si_add($ln['activity_code'] ?? '');
            $emit([
                $sc(0, $fmtDate($ln['entry_date']), $ts),
                $sc(1, $ln['reference_number'] ?? '', $ts),
                $sc(2, $ln['line_desc'] ?? '', $ts),
                $sc(3, ucfirst(str_replace('_',' ',$ln['entry_type'] ?? '')), $ts),
                $sc(4, $ln['division_name'] ?? '', $ts),
                $sc(5, $ln['block_code'] ?? '', $ts),
                $sc(6, $ln['activity_code'] ?? '', $ts),
                (float)$ln['debit_amount']  > 0 ? $nc(7,(float)$ln['debit_amount'],  $ns) : $bc(7,$ts),
                (float)$ln['credit_amount'] > 0 ? $nc(8,(float)$ln['credit_amount'], $ns) : $bc(8,$ts),
                $nc(9, $running, $ns),
            ]);
            $alt=!$alt;
        }

        // Subtotal row
        $emit([
            $sc(0,'Subtotal',10),$bc(1,10),$bc(2,10),$bc(3,10),$bc(4,10),$bc(5,10),$bc(6,10),
            $dr > 0 ? $nc(7,$dr,11) : $bc(7,10),
            $cr > 0 ? $nc(8,$cr,11) : $bc(8,10),
            $nc(9,$cl,11),
        ],13);
        $emit([]);  // blank spacer between accounts
    }

    // Grand total row
    $emit([
        $sc(0,'Grand Total',10),$bc(1,10),$bc(2,10),$bc(3,10),$bc(4,10),$bc(5,10),$bc(6,10),
        $nc(7,$g_dr,11),$nc(8,$g_cr,11),$bc(9,10),
    ],14);

    // ── Worksheet XML ─────────────────────────────────────────────────────────
    $cw=[11,14,34,10,14,9,10,16,16,18];
    $ws='<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
      . '<sheetFormatPr defaultRowHeight="15" customHeight="1"/><cols>';
    foreach($cw as $ci=>$w) $ws.='<col min="'.($ci+1).'" max="'.($ci+1).'" width="'.$w.'" customWidth="1"/>';
    $ws.='</cols><sheetData>'.$ws_rows.'</sheetData>'
       . '<pageSetup orientation="landscape" paperSize="9" fitToPage="1" fitToWidth="1" fitToHeight="0"/></worksheet>';

    // ── Shared strings ────────────────────────────────────────────────────────
    $n_si=count($si_list);
    $sst='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.$n_si.'" uniqueCount="'.$n_si.'">';
    foreach($si_list as $s_) $sst.='<si><t xml:space="preserve">'.$xv($s_).'</t></si>';
    $sst.='</sst>';

    // ── Styles ────────────────────────────────────────────────────────────────
    // numFmt 164=#,##0  165=#,##0;(#,##0)  (credit/balance shown as parentheses for negative)
    $xl='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
      . '<numFmts count="2"><numFmt numFmtId="164" formatCode="#,##0"/><numFmt numFmtId="165" formatCode="#,##0;(#,##0)"/></numFmts>'
      . '<fonts count="7">'
      .   '<font><sz val="11"/><name val="Calibri"/></font>'                                     // 0 normal
      .   '<font><b/><sz val="14"/><color rgb="FF166C82"/><name val="Calibri"/></font>'          // 1 title
      .   '<font><sz val="10"/><color rgb="FF555555"/><name val="Calibri"/></font>'              // 2 muted
      .   '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'          // 3 white bold (header)
      .   '<font><b/><sz val="11"/><name val="Calibri"/></font>'                                 // 4 bold black (total)
      .   '<font><b/><sz val="12"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'          // 5 section hdr
      .   '<font><i/><sz val="10"/><color rgb="FF555555"/><name val="Calibri"/></font>'          // 6 italic muted (OB)
      . '</fonts>'
      . '<fills count="7">'
      .   '<fill><patternFill patternType="none"/></fill>'
      .   '<fill><patternFill patternType="gray125"/></fill>'
      .   '<fill><patternFill patternType="solid"><fgColor rgb="FF166C82"/></patternFill></fill>' // 2 teal
      .   '<fill><patternFill patternType="solid"><fgColor rgb="FFF0F7FF"/></patternFill></fill>' // 3 alt row
      .   '<fill><patternFill patternType="solid"><fgColor rgb="FFCFE2FF"/></patternFill></fill>' // 4 total light
      .   '<fill><patternFill patternType="solid"><fgColor rgb="FF0D4F60"/></patternFill></fill>' // 5 section dark
      .   '<fill><patternFill patternType="solid"><fgColor rgb="FFE9F4F7"/></patternFill></fill>' // 6 col header
      . '</fills>'
      . '<borders count="2">'
      .   '<border><left/><right/><top/><bottom/><diagonal/></border>'
      .   '<border><left style="thin"><color rgb="FFB0C4DE"/></left><right style="thin"><color rgb="FFB0C4DE"/></right><top style="thin"><color rgb="FFB0C4DE"/></top><bottom style="thin"><color rgb="FFB0C4DE"/></bottom><diagonal/></border>'
      . '</borders>'
      . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
      . '<cellXfs count="13">'
      . '<xf numFmtId="0"   fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'                                                                               // 0 plain str
      . '<xf numFmtId="0"   fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                                                                                 // 1 title
      . '<xf numFmtId="0"   fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                                                                                 // 2 period muted
      . '<xf numFmtId="0"   fontId="3" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'                                                   // 3 col header
      . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"><alignment horizontal="right"/></xf>'                      // 4 num plain
      . '<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"><alignment horizontal="right"/></xf>'                      // 5 num balance (neg=parens)
      . '<xf numFmtId="164" fontId="0" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1"><alignment horizontal="right"/></xf>'        // 6 num alt row
      . '<xf numFmtId="0"   fontId="5" fillId="5" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'                                                                   // 7 section header
      . '<xf numFmtId="0"   fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1"/>'                                                                 // 8 str alt row
      . '<xf numFmtId="0"   fontId="4" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'                                                   // 9  total str (unused)
      . '<xf numFmtId="0"   fontId="4" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'                                                   // 10 total str
      . '<xf numFmtId="164" fontId="4" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="right"/></xf>'  // 11 total num
      . '<xf numFmtId="0"   fontId="6" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1"/>'                                                                 // 12 opening bal italic
      . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';

    $fname = 'General_Ledger_'.$fmtDate($date_from).'_to_'.$fmtDate($date_to).'.xlsx';
    _send_xlsx(_xlsx_package($ws,$sst,$xl,'General Ledger'), $fname);
}
