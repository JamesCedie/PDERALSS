<?php
/**
 * includes/dromic.php
 * Screens, print layout and Excel export for the DROMIC forms:
 *   Form 3    Masterlist of Casualties (Dead / Injured / Missing)
 *   Form 4.1  Profile of Evacuees OUTSIDE Evacuation Center  (families from outside_evacuation_families)
 *   Form 4.2  Profile of Evacuees INSIDE Evacuation Center   (families from evacuation_evacuees)
 *   Form 4.3  Summary of Profile of Evacuees Inside and Outside Evacuation Centers
 *
 * The page wrappers (social-worker/*.php and mdrrmo-officer/*.php) must have already done:
 *   require_page_access(); require includes/layout.php;   (layout.php has to be loaded at top level)
 * and then call dromic_report_page().
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/md.php';
require_once __DIR__ . '/reports.php';

function dromic_css(): string
{
    return <<<'CSS'
.dromic-toolbar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:12px}
.dromic-toolbar select{min-width:240px}
.dromic-tabs{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px}
.dromic-tabs a{padding:6px 14px;border:1px solid #888;border-radius:6px;text-decoration:none;color:inherit;font-size:13px}
.dromic-tabs a.on{background:#2563eb;color:#fff;border-color:#2563eb}
.dromic-scroll{overflow-x:auto}
/* ---- paper form ---- */
.dromic-sheet{background:#fff;color:#000;font-family:Arial,Helvetica,sans-serif;font-size:11px;padding:18px 22px 26px;min-width:1040px;margin-bottom:18px}
.dromic-sheet .formno{font-size:11px;line-height:1.5}
.dromic-sheet .center{text-align:center;line-height:1.55;margin-top:2px}
.dromic-sheet .title{background:#ff0;font-weight:bold;font-size:14px;text-align:center;padding:2px 0;margin:14px 0 3px}
.dromic-sheet .title.plain{background:none;font-size:14px;letter-spacing:1.4px;padding:0;margin:26px 0 22px}
.dromic-sheet .asof{text-align:center;line-height:1.6;margin-bottom:2px}
.dromic-sheet .asof u{display:inline-block;text-align:center;text-decoration:none;border-bottom:1px solid #000;min-width:120px;margin:0 3px}
.dromic-sheet .asof u.l{min-width:270px}
.dromic-meta{display:flex;justify-content:space-between;align-items:flex-start;gap:24px;margin:16px 0 4px}
.dromic-meta dl{margin:0;display:grid;grid-template-columns:auto 1fr;gap:3px 6px;align-items:end}
.dromic-meta dt{margin:0}
.dromic-meta dd{margin:0;border-bottom:1px solid #000;min-width:185px;font-weight:bold;padding-left:4px;min-height:14px;line-height:1.35}
.dromic-meta .l{flex:0 0 38%}
.dromic-meta .l dd{min-width:200px}
.dromic-meta .r{flex:0 0 42%}
.dromic-meta .r dl.rp dd{min-width:150px;max-width:150px}
.dromic-meta .r dl.tot{margin-top:8px}
.dromic-meta .r dl.tot dd{min-width:120px;font-weight:normal;border-bottom:1px dotted #000}
.dromic-meta dl.rt dt{text-align:right}
.dromic-meta.m3 .r{flex:0 0 auto;margin-right:11%}
.dromic-meta.m3 .l{flex:0 0 auto}
.dromic-table{width:100%;border-collapse:collapse;border:2px solid #000;table-layout:fixed}
.dromic-table th,.dromic-table td{border:1px solid #000;padding:2px 3px;text-align:center;font-size:10px;color:#000;background:#fff;word-wrap:break-word}
.dromic-table th{font-weight:bold;font-size:10.5px}
.dromic-table td.area,.dromic-table td.nm,.dromic-table th.nm{text-align:left;padding-left:5px}
.dromic-table th.nm{text-align:center}
.dromic-table td.area{font-weight:bold;width:11%}
.dromic-table td.nm{width:19%}
.dromic-table td.l{text-align:left;padding-left:5px}
.dromic-table td.dep{padding-left:14px}
.dromic-table td.n{font-variant-numeric:tabular-nums}
.dromic-table tr{height:19px}
.dromic-table tr.fh td{border-top:2px solid #000}
.dromic-table tr.total td,.dromic-table tr.sub td{font-weight:bold}
.dromic-table tr.sub td{border-top:2px solid #000}
.dromic-table tr.sub td.nm{text-align:center}
.dromic-table th.gt{text-align:left;font-weight:normal;vertical-align:middle;padding-left:5px}
.dromic-table.f3 th{height:20px;vertical-align:middle}
.dromic-table.f3 thead tr.h2 th{text-decoration:underline}
.dromic-table.f3 tbody tr{height:19px}
.dromic-table.f3 td{border-left:1px solid #000}
.dromic-sign{display:flex;justify-content:space-between;margin-top:26px;font-size:11px}
.dromic-sign .s1{width:52%}
.dromic-sign .s2{width:36%}
.dromic-sign .line{border-top:1px solid #000;text-align:center;padding-top:3px;margin-top:38px;width:280px;margin-left:180px}
.dromic-sign .s2 .line{width:230px;margin-left:58px}
.dromic-note{font-size:10px;margin-top:8px}
@page{size:landscape;margin:8mm}
@media print{
  body *{visibility:hidden}
  .dromic-area,.dromic-area *{visibility:visible}
  .dromic-scroll{overflow:visible}
  .dromic-area{position:absolute;left:0;top:0;width:100%}
  .dromic-sheet{min-width:0;padding:0;margin:0;page-break-after:always}
  .dromic-sheet:last-child{page-break-after:auto}
}
CSS;
}

function dromic_css_excel(): string
{
    return 'table{border-collapse:collapse}td,th{border:1px solid #000;padding:3px;text-align:center}td.area,td.nm,td.l{text-align:left}.title{background:#ff0;font-weight:bold}.title.plain{background:none}';
}

/** <dl> of label/value pairs; an empty value leaves a blank underline (the colon always prints, like the paper form). */
function dromic_dl(array $pairs, string $cls = ''): string
{
    $o = '<dl' . ($cls !== '' ? ' class="' . $cls . '"' : '') . '>';
    foreach ($pairs as [$k, $v]) $o .= '<dt>' . md_h($k) . '</dt><dd>:' . ((string) $v === '' ? '&nbsp;' : ' ' . md_h($v)) . '</dd>';
    return $o . '</dl>';
}

function dromic_head_html(string $formNo, string $title, bool $republic, bool $yellow, string $province = DROMIC_PROVINCE): string
{
    return '<div class="formno">' . md_h($formNo) . '</div><div class="center">'
        . ($republic ? 'Republic of the Philippines<br>Province of ' . md_h($province) . '<br>' : '')
        . '<b>Barangay Disaster Risk Reduction and Management</b><br>'
        . '<b>DISASTER RESPONSE, OPERATIONS, MONITORING AND INFORMATION CENTER</b></div>'
        . '<div class="title' . ($yellow ? '' : ' plain') . '">' . md_h($title) . '</div>';
}

function dromic_sign_html(array $ctx): string
{
    $s = dromic_signatories($ctx['brgyId'] ?? null);
    return '<div class="dromic-sign">'
        . '<div class="s1">Prepared and Submitted by:<div class="line">' . ($s['secretary'] !== '' ? '<b>' . md_h($s['secretary']) . '</b><br>' : '') . 'Barangay Secretary</div></div>'
        . '<div class="s2">Noted by:<div class="line">' . ($s['chairman'] !== '' ? '<b>' . md_h($s['chairman']) . '</b><br>' : '') . 'BDRRM Chairman</div></div>'
        . '</div>';
}

/** 12 age/sex cells + male total + female total + grand total. $blankZero hides zeros (used on person rows). */
function dromic_cells(array $t, bool $blankZero): string
{
    $v = static fn(int $n): string => ($blankZero && $n === 0) ? '' : (string) $n;
    $o = '';
    for ($i = 0; $i < 6; $i++) $o .= '<td class="n">' . $v($t['m'][$i]) . '</td><td class="n">' . $v($t['f'][$i]) . '</td>';
    return $o . '<td class="n">' . $v(array_sum($t['m'])) . '</td><td class="n">' . $v(array_sum($t['f'])) . '</td><td class="n"><b>' . $v($t['persons']) . '</b></td>';
}

function dromic_add(array &$sum, array $t): void
{
    foreach (['heads', 'dependents', 'persons', 'unspecified'] as $k) $sum[$k] += $t[$k];
    for ($i = 0; $i < 6; $i++) { $sum['m'][$i] += $t['m'][$i]; $sum['f'][$i] += $t['f'][$i]; }
}

/** Age-bracket header shared by 4.1, 4.2 and 4.3. $lead = leading cells of row 1; $row4Lead = leading cells of the Male/Female row. */
function dromic_age_header(string $lead, string $row4Lead = ''): string
{
    $mf = str_repeat('<th>Male</th><th>Female</th>', 7);   // six age brackets + TOTAL
    return '<tr>' . $lead . '<th colspan="14">NUMBER OF PERSONS</th><th rowspan="4" style="width:5%">GRAND<br>TOTAL</th></tr>'
        . '<tr><th colspan="4">PRE-SCHOOLER (0-6)</th><th colspan="2">OLDER CHILDREN</th><th colspan="2">YOUTH</th><th colspan="2">ADULT</th><th colspan="2">OLD</th><th colspan="2" rowspan="2">TOTAL</th></tr>'
        . '<tr><th colspan="2">(0-3)</th><th colspan="2">(4-6)</th><th colspan="2">(7-12)</th><th colspan="2">(13-17)</th><th colspan="2">(18-59)</th><th colspan="2">(60-Above)</th></tr>'
        . '<tr>' . $row4Lead . $mf . '</tr>';
}

/**
 * Forms 4.1 / 4.2: families listed one by one (family head, then dependents), 5 families per page,
 * GRAND TOTAL row on top and SUB-TOTAL row at the bottom of every page, like the paper forms.
 */
function dromic_render_family_forms(string $formNo, string $title, array $families, array $ctx, string $scope): string
{
    $grand  = dromic_tally($families);
    $cnt    = dromic_family_counts($families);
    // Only rows with real data are printed (no blank family / dependent rows). Families are packed onto pages of up to ~22 rows.
    $chunks = []; $cur = []; $curRows = 0;
    foreach ($families as $f) {
        $need = 1 + count($f['deps']);
        if ($cur && $curRows + $need > 22) { $chunks[] = $cur; $cur = []; $curRows = 0; }
        $cur[] = $f; $curRows += $need;
    }
    $chunks[] = $cur;   // an empty list still gives one (empty) page
    $pages  = count($chunks);
    // Header ends at the Male/Female row (no GRAND TOTAL figures row beneath it); the name cell spans all four header rows.
    $thead  = dromic_age_header('<th rowspan="4" class="nm" style="width:19%">NAME OF FAMILY<br>HEADS /DEPENDENTS</th>');
    $html = '';
    $n = 0;
    foreach ($chunks as $ci => $chunk) {
        $rows = '';
        $sub = dromic_blank_row();
        foreach ($chunk as $f) {
            $n++;
            $ht = $f['head'] ? dromic_person_tally($f['head']) : dromic_blank_row();
            // A family whose head is not in the center (only dependents are) keeps its head row, marked "not in center".
            $rows .= '<tr class="fh"><td class="nm">' . $n . '. ' . md_h($f['head_name']) . ' <i>(Family Head' . ($f['head'] ? '' : ($scope === 'inside' ? ' - not in center' : '')) . ')</i></td>' . dromic_cells($ht, true) . '</tr>';
            $ft = dromic_blank_row();
            if ($f['head']) dromic_add($ft, $ht);
            foreach ($f['deps'] as $i => $d) {
                $dt = dromic_person_tally($d);
                $rows .= '<tr><td class="nm dep">' . chr(97 + ($i % 26)) . '. ' . md_h($d['name']) . '</td>' . dromic_cells($dt, true) . '</tr>';
                dromic_add($ft, $dt);
            }
            dromic_add($sub, $ft);
        }
        if (!$chunk) $rows = '<tr><td class="nm" colspan="16" style="text-align:center">' . ($scope === 'inside' ? 'No evacuees recorded inside the evacuation center.' : 'No families outside the evacuation center.') . '</td></tr>';
        $html .= '<div class="dromic-sheet">'
            . dromic_head_html($formNo, $title, false, false, $ctx['province'])
            . '<div class="dromic-meta"><div class="l">'
            .   dromic_dl([['Municipality', strtoupper($ctx['municipality'])], ['Barangay', $ctx['barangay']], ['Disaster', $ctx['disaster']], ['Occurrence', $ctx['occurrence']], ['Evacuation Center', $scope === 'inside' ? $ctx['center'] : '']])
            . '</div><div class="r">'
            .   dromic_dl([['Region', $ctx['region']], ['Province', strtoupper($ctx['province'])]], 'rp')
            .   dromic_dl([['Total Number of Families with Less than 5 Members', (string) $cnt['lt5']], ['Total Number of Families with 5 or More Members', (string) $cnt['ge5']]], 'tot')
            . '</div></div>'
            . '<table class="dromic-table"><thead>' . $thead . '</thead><tbody>'
            . $rows
            . '<tr class="sub"><td class="nm">SUB-TOTAL</td>' . dromic_cells($sub, false) . '</tr>'
            . '</tbody></table>'
            . '<div class="dromic-note">Page ' . ($ci + 1) . ' of ' . $pages . ($grand['unspecified'] > 0 ? ' &middot; ' . (int) $grand['unspecified'] . ' person(s) have no age or sex on record and appear only in the Grand Total column.' : '') . '</div>'
            . dromic_sign_html($ctx)
            . '</div>';
    }
    return $html;
}

/** Form 4.3 */
function dromic_render_form43(array $sum, array $ctx): string
{
    $asOf = $ctx['asOf'];
    $thead = dromic_age_header('<th rowspan="4" class="nm" style="width:11%">AREA OF EVACUATION<br>CENTER</th><th colspan="2" rowspan="3">TOTAL NUMBER OF</th>', '<th>Family Heads</th><th>Dependents</th>');
    $body = '';
    foreach ($sum['rows'] as $r) {
        $body .= '<tr><td class="area">' . md_h($r['label']) . '</td><td class="n">' . $r['heads'] . '</td><td class="n">' . $r['dependents'] . '</td>' . dromic_cells($r, false) . '</tr>';
    }
    for ($i = count($sum['rows']); $i < 5; $i++) $body .= '<tr><td class="area">&nbsp;</td>' . str_repeat('<td></td>', 17) . '</tr>';
    $t = $sum['total'];
    $body .= '<tr class="total"><td class="area">TOTAL</td><td class="n">' . $t['heads'] . '</td><td class="n">' . $t['dependents'] . '</td>' . dromic_cells($t, false) . '</tr>';

    return '<div class="dromic-sheet">'
        . dromic_head_html('FORM # 4.3', 'SUMMARY OF PROFILE OF EVACUEES INSIDE AND OUTSIDE EVACUATION CENTERS', true, true, $ctx['province'])
        . '<div class="asof">As of <u>' . md_h($asOf->format('F j, Y')) . '</u> (Date)<br><u>' . md_h($asOf->format('g:i A')) . '</u> (Time)</div>'
        . '<div class="dromic-meta">'
        .   dromic_dl([['Municipality', strtoupper($ctx['municipality'])], ['Disaster', $ctx['disaster']], ['Occurrence', $ctx['occurrence']], ['Evacuation Center', $ctx['center']]])
        .   dromic_dl([['Region', $ctx['region']], ['Barangay', $ctx['barangay']], ['Total Number of Families with Less than 5 Members', (string) $sum['lt5']], ['Total Number of Families with 5 or More Members', (string) $sum['ge5']]])
        . '</div>'
        . '<table class="dromic-table"><thead>' . $thead . '</thead><tbody>' . $body . '</tbody></table>'
        . ($sum['unspecified'] > 0 ? '<div class="dromic-note">Note: ' . (int) $sum['unspecified'] . ' person(s) have no age or sex on record, so they appear only in the Grand Total column.</div>' : '')
        . dromic_sign_html($ctx)
        . '</div>';
}

/** Form 3 */
function dromic_render_form3(array $rows, array $ctx): string
{
    $asOf = $ctx['asOf'];
    $body = '';
    foreach ($rows as $r) {
        $addr = trim((string) ($r['address'] ?? ''));
        if ($ctx['showBrgy']) $addr = trim($addr . ($addr !== '' ? ', ' : '') . md_short_barangay((string) $r['barangay']));
        // One combined list: the Dead / Injured / Missing status is shown first in REMARKS (the paper form has no separate column).
        $status  = DROMIC_CASUALTY_TYPES[$r['type'] ?? ''] ?? '';
        $remarks = ($status !== '' ? '<b>' . md_h($status) . '</b>' : '') . (trim((string) ($r['notes'] ?? '')) !== '' ? ($status !== '' ? ' - ' : '') . md_h($r['notes']) : '');
        $body .= '<tr><td class="l">' . md_h($r['last_name']) . '</td><td class="l">' . md_h($r['first_name']) . '</td><td>' . md_h(mb_strtoupper(mb_substr((string) ($r['middle_initial'] ?? ''), 0, 1))) . '</td>'
              . '<td>' . md_h($r['age'] ?? '') . '</td><td>' . md_h($r['sex'] ?? '') . '</td><td class="l">' . md_h($addr) . '</td>'
              . '<td class="l">' . md_h($r['cause'] ?? '') . '</td><td class="l">' . $remarks . '</td></tr>';
    }
    if (!$rows) $body = '<tr><td colspan="8" style="text-align:center">No casualties recorded.</td></tr>';

    return '<div class="dromic-sheet">'
        . '<div class="formno">DROMIC FORM # 3<br>(Annex D)</div>'
        . '<div class="center">Republic of the Philippines<br>Province of ' . md_h($ctx['province']) . '<br><b>Barangay Disaster Risk Reduction and Management</b><br><b>DISASTER RESPONSE, OPERATIONS, MONITORING AND INFORMATION CENTER</b></div>'
        . '<div class="title">MASTERLIST OF CASUALTIES</div>'
        . '<div class="asof"><u>' . ($ctx['typeLabel'] === '' ? 'All' : md_h($ctx['typeLabel'])) . '</u>(Dead/Injured/Missing)</div>'
        . '<div class="asof">As of <u class="l">' . md_h($asOf->format('F j, Y, g:i A')) . '</u>(Date &amp; Time)</div>'
        . '<div class="dromic-meta m3"><div class="l">'
        .   dromic_dl([['Region', $ctx['region']], ['Type of Disaster', $ctx['disaster']], ['Date of Occurrence', $ctx['occurrence']]])
        . '</div><div class="r">'
        .   dromic_dl([['Province', strtoupper($ctx['province'])], ['Municipality', strtoupper($ctx['municipality'])], ['Barangay', $ctx['barangay']]], 'rt')
        . '</div></div>'
        . '<table class="dromic-table f3"><thead>'
        . '<tr><th colspan="3">NAME</th><th rowspan="2" style="width:5%">AGE</th><th rowspan="2" style="width:5%">SEX</th><th rowspan="2" style="width:22%">ADDRESS<br>(Sitio/Purok/Zone/Street)</th><th rowspan="2" style="width:17%">CAUSE OF<br>DEATH/ INJURY</th><th rowspan="2" style="width:17%">REMARKS</th></tr>'
        . '<tr class="h2"><th style="width:14%">(Last Name)</th><th style="width:14%">(First Name)</th><th style="width:5%">(MI)</th></tr>'
        . '</thead><tbody>' . $body . '</tbody></table>'
        . dromic_sign_html($ctx)
        . '</div>';
}

/**
 * Full page: event / barangay pickers, print + Excel export. The Social Worker prepares their barangay's form;
 * the MDRRMO Officer picks a barangay and reviews / prints what that barangay would submit.
 *   $mode 'evac'     -> Forms 4.1, 4.2, 4.3
 *   $mode 'casualty' -> Form 3
 *   $fixedBarangayId null = the user may pick a barangay or "All" (MDRRMO Officer);
 *                     int  = locked to that barangay (Social Worker; 0 = account has no barangay)
 */
function dromic_report_page(string $mode, ?int $fixedBarangayId = null): void
{
    $events  = db_query('SELECT * FROM disaster_events ORDER BY event_id DESC')->fetchAll();
    $eventId = (int) ($_GET['event_id'] ?? 0);
    if (!$eventId) {
        foreach ($events as $e) { if (($e['status'] ?? '') === 'Active') { $eventId = (int) $e['event_id']; break; } }
        if (!$eventId && $events) $eventId = (int) $events[0]['event_id'];
    }
    $event = $eventId ? md_find_event($eventId) : null;

    $selectable = $fixedBarangayId === null;
    $brgyId     = $selectable ? (int) ($_GET['barangay_id'] ?? 0) : (int) $fixedBarangayId;
    // These are barangay forms (signed by the Barangay Secretary / BDRRM Chairman): the Social Worker prepares their own
    // barangay's form; the MDRRMO Officer REVIEWS one barangay's form at a time (no "All barangays").
    $noBrgy     = $brgyId <= 0;
    $brgyName   = $brgyId ? md_short_barangay(brgy_name($brgyId)) : '';
    $centerName = $brgyId ? (string) db_query('SELECT center_name FROM evacuation_centers WHERE barangay_id = ?', [$brgyId])->fetchColumn() : '';

    // Same for Social Worker and MDRRMO Officer: Iloilo / Oton / Region 6, one combined casualty form, Form 4.2 only.
    $sw   = true;
    $form = '4.2';
    $type = null;

    $ctx = [
        'disaster'   => $event ? md_event_type_label($event) : '',
        'occurrence' => $event ? md_date($event['date'], 'F j, Y') : '',
        'barangay'   => $brgyName !== '' ? strtoupper($brgyName) : 'ALL BARANGAYS',
        'center'     => $brgyId ? $centerName : 'ALL EVACUATION CENTERS',
        'asOf'       => new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')),
        'brgyId'     => $brgyId ?: null,
        'showBrgy'   => !$brgyId,
        'typeLabel'  => $type === null ? '' : DROMIC_CASUALTY_TYPES[$type],
        'region'       => $sw ? 'Region 6' : DROMIC_REGION,
        'province'     => $sw ? 'Iloilo' : DROMIC_PROVINCE,
        'municipality' => $sw ? 'Oton' : DROMIC_MUNICIPALITY,
    ];

    $sheet = '';
    if ($event && !$noBrgy) {
        if ($mode === 'casualty') {
            $sheet = dromic_render_form3(dromic_casualties($eventId, $type, $brgyId ?: null), $ctx);
        } else {
            if (($event['status'] ?? '') === 'Active') dromic_sync_outside($eventId);
            if ($form === '4.1')      $sheet = dromic_render_family_forms('FORM # 4.1', 'PROFILE OF EVACUEES OUTSIDE EVACUATION CENTER', dromic_families($eventId, 'outside', $brgyId ?: null), $ctx, 'outside');
            elseif ($form === '4.2')  $sheet = dromic_render_family_forms('FORM # 4.2', 'PROFILE OF EVACUEES INSIDE EVACUATION CENTER', dromic_families($eventId, 'inside', $brgyId ?: null), $ctx, 'inside');
            else                      $sheet = dromic_render_form43(dromic_summary($eventId, $brgyId ?: null), $ctx);
        }
    }

    if ($sheet !== '' && ($_GET['export'] ?? '') === 'xls') {
        $label = $mode === 'casualty' ? 'Form-3' . ($ctx['typeLabel'] !== '' ? '-' . $ctx['typeLabel'] : '') : 'Form-' . $form;
        $fname = 'DROMIC-' . $label . '-' . preg_replace('/[^A-Za-z0-9]+/', '-', (string) $event['event_name']) . '.xls';
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        echo "\xEF\xBB\xBF" . '<html><head><meta charset="utf-8"><style>' . dromic_css_excel() . '</style></head><body>' . $sheet . '</body></html>';
        exit;
    }

    $self  = basename($_SERVER['SCRIPT_NAME']);
    $url   = static fn(array $o): string => $self . '?' . http_build_query(array_filter(array_merge(
        ['event_id' => $eventId, 'barangay_id' => $selectable ? $brgyId : null, 'form' => $mode === 'evac' ? $form : null, 'type' => $mode === 'casualty' ? $type : null], $o),
        static fn($v) => $v !== null && $v !== '' && $v !== 0));

    page_start($mode === 'casualty' ? 'Casualty Report' : 'Evacuation Report', null, ['reports.php' . ($eventId ? '?event_id=' . (int) $eventId : ''), 'Reports']);
    echo '<style>' . dromic_css() . '</style>';
    echo '<div class="page-head"><h1 class="page-title">' . ($mode === 'casualty' ? 'Casualty Report (DROMIC Form 3)' : 'Evacuation Report (DROMIC Form 4.2)') . ($selectable ? ' - Barangay Review' : '') . '</h1></div>';

    echo '<div class="dromic-toolbar">';
    // MDRRMO Officer: the disaster event and barangay come from Reports > Barangay Reports, so no pickers here.
    // Social Worker: keeps the disaster event picker (barangay is locked to their own).
    if (!$selectable) {
        echo '<form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">'
           . '<select class="dash-select" name="event_id" aria-label="Disaster event" onchange="this.form.submit()">';
        foreach ($events as $e) echo '<option value="' . (int) $e['event_id'] . '"' . ((int) $e['event_id'] === $eventId ? ' selected' : '') . '>' . md_h($e['event_name']) . ' (' . md_h(md_date($e['date'])) . ')</option>';
        echo '</select></form>';
    }
    if ($sheet !== '') echo '<div class="page-actions"><a class="btn btn-success" href="' . md_h($url(['export' => 'xls'])) . '">Export Excel</a><button class="btn btn-primary" onclick="window.print()">Print / Save as PDF</button></div>';
    echo '</div>';

    if (!$sw) echo '<div class="dromic-tabs">';
    if ($sw) {
        // no form / type selectors for the Social Worker
    } elseif ($mode === 'evac') {
        foreach (['4.3' => 'Form 4.3 Summary', '4.2' => 'Form 4.2 Inside Center', '4.1' => 'Form 4.1 Outside Center'] as $k => $lbl)
            echo '<a class="' . ($form === $k ? 'on' : '') . '" href="' . md_h($url(['form' => $k])) . '">' . md_h($lbl) . '</a>';
    } else {
        foreach (DROMIC_CASUALTY_TYPES as $k => $lbl)
            echo '<a class="' . ($type === $k ? 'on' : '') . '" href="' . md_h($url(['type' => $k])) . '">' . md_h($lbl) . '</a>';
    }
    if (!$sw) echo '</div>';

    if (!$event)       echo '<div class="card empty">No disaster event found. These reports are generated per disaster event.</div>';
    elseif ($noBrgy && $selectable) echo '<div class="card empty">Open a barangay from Reports &gt; Barangay Reports to review its ' . ($mode === 'casualty' ? 'Masterlist of Casualties (Form 3)' : 'Profile of Evacuees Inside Evacuation Center (Form 4.2)') . '.</div>';
    elseif ($noBrgy)   echo '<div class="card empty">Your account has no barangay assigned. Please contact an MDRRMO Officer.</div>';
    else               echo '<div class="dromic-scroll"><div class="dromic-area">' . $sheet . '</div></div>';

    page_end();
}
