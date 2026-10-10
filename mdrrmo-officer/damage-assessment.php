<?php
require_once '../includes/access.php'; require_page_access();
require_once '../includes/md.php';

require_once '../includes/damage.php';
db_ensure_damage_assessments_table();

// Assessments submitted by Social Workers (view-only here). Family head comes from the linked household.
$assessments = [];
$daRows = db_query(
    'SELECT d.*, h.firstname, h.middlename, h.lastname, h.nameextension
     FROM damage_assessments d LEFT JOIN household h ON h.household_id = d.household_id
     ORDER BY d.created_at DESC'
)->fetchAll();
foreach ($daRows as $r) {
    $head = trim((string) ($r['lastname'] ?? ''));
    if ($head !== '') {
        $given = trim(implode(' ', array_filter([$r['firstname'] ?? '', $r['middlename'] ?? '', $r['nameextension'] ?? ''])));
        $head .= $given !== '' ? ', ' . $given : '';
    } else {
        $head = (string) $r['reporter'];
    }
    try {
        $date = (new DateTimeImmutable($r['created_at']))->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d');
    } catch (Throwable $e) {
        $date = substr((string) $r['created_at'], 0, 10);
    }
    $assessments[] = [
        'id'               => (int) $r['assessment_id'],
        'barangay'         => $r['barangay'],
        'family_head_name' => $head,
        'damage_level'     => da_md_level((string) $r['damage_status']),
        'assessed_date'    => $date,
        'notes'            => $r['description'],
        'ref'              => $r['reference_code'],
        'asset_type'       => $r['asset_type'],
        'reporter'         => $r['reporter'],
        'lat'              => $r['latitude'] !== null ? (float) $r['latitude'] : null,
        'lng'              => $r['longitude'] !== null ? (float) $r['longitude'] : null,
        'accuracy'         => $r['gps_accuracy_m'] !== null ? (int) round((float) $r['gps_accuracy_m']) : null,
        'source'           => $r['location_source'],
        'address'          => $r['address'] ?? null,
        'flags'            => da_flag_labels($r['location_flags']),
        'has_photo'        => !empty($r['photo_path']),
        'signed'           => da_row_signature_valid($r),
        'reported_level'   => $r['damage_status'],
    ];
}

$perBarangay = [];
$updatedAt   = [];
foreach ($assessments as $a) {
    $perBarangay[$a['barangay']][$a['damage_level']] = ($perBarangay[$a['barangay']][$a['damage_level']] ?? 0) + 1;
    if (empty($updatedAt[$a['barangay']]) || $a['assessed_date'] > $updatedAt[$a['barangay']]) {
        $updatedAt[$a['barangay']] = $a['assessed_date'];
    }
}
$names = md_barangay_names(array_keys($perBarangay));

function md_dmg_counts(array $perLevel): array
{
    $totally   = (int) ($perLevel['Totally Damaged'] ?? 0);
    $partially = (int) ($perLevel['Partially Damaged'] ?? 0);
    return [$totally + $partially, $totally, $partially];
}

$eventId  = (int) ($_GET['event_id'] ?? 0);
$event    = md_find_event($eventId);
$evQuery  = $event ? 'event_id=' . $eventId : '';
$selected = $_GET['barangay'] ?? '';
$selected = in_array($selected, $names, true) ? $selected : null;

require '../includes/layout.php';
if ($selected !== null) {
    page_start('Damage Assessment', null, ['damage-assessment.php' . ($evQuery ? '?' . $evQuery : ''), '← Damage Assessment']);
} else {
    page_start('Damage Assessment', null, ['disasters.php', '← Disaster Events']);
}
?>

<div class="md-page">
<?php if ($selected === null): ?>
    <?php
        $all = [0, 0, 0];
        foreach ($names as $n) {
            foreach (md_dmg_counts($perBarangay[$n] ?? []) as $i => $v) $all[$i] += $v;
        }
    ?>
    <?php if ($event): ?><div class="sw-event-label">Event:<strong><?= md_h($event['event_name']) ?></strong></div><?php endif; ?>
    <div class="sw-casualty-title">Damage Assessment Monitoring</div>
    <div class="md-split">
        <div class="card sw-total-card">
            <div class="stat-label">Assessments Count</div>
            <div class="stat-value"><?= number_format($all[0]) ?></div>
        </div>
        <div class="card sw-breakdown-card">
            <h3>Assessment Count Breakdown</h3>
            <div class="sw-breakdown md-breakdown-2">
                <div class="sw-breakdown-item fatal"><label>Totally Damaged</label><strong><?= number_format($all[1]) ?></strong></div>
                <div class="sw-breakdown-item injured"><label>Partially Damaged</label><strong><?= number_format($all[2]) ?></strong></div>
            </div>
        </div>
    </div>

    <div class="hh-dir-head">
        <div class="sw-records-title">Barangay Damage Assessment Records</div>
        <span class="hh-count"><?= count($names) ?> Barangay folders</span>
    </div>

    <div class="hh-folders">
        <?php foreach ($names as $n):
            $count = md_dmg_counts($perBarangay[$n] ?? [])[0];
            $meta  = !empty($updatedAt[$n]) ? 'Updated ' . md_date($updatedAt[$n], 'j M Y') : 'No records yet';
        ?>
            <div class="hh-folder">
                <div>
                    <div class="hh-folder-name"><?= md_h($n) ?></div>
                    <div class="hh-folder-meta"><?= md_h($meta) ?></div>
                    <div class="hh-folder-count"><strong><?= $count ?></strong> <span><?= $count === 1 ? 'household' : 'households' ?></span></div>
                </div>
                <a class="btn btn-dark-sm" href="damage-assessment.php?<?= $evQuery ? $evQuery . '&amp;' : '' ?>barangay=<?= urlencode($n) ?>">Open records</a>
            </div>
        <?php endforeach; ?>
    </div>

<?php else: ?>
    <?php
        [$total, $totally, $partially] = md_dmg_counts($perBarangay[$selected] ?? []);
        $records = array_values(array_filter($assessments, fn($a) => $a['barangay'] === $selected));
        usort($records, fn($a, $b) => strcmp($b['assessed_date'], $a['assessed_date']));
    ?>
    <div class="sw-event-label">Barangay:<strong><?= md_h(md_short_barangay($selected)) ?></strong></div>

    <div class="sw-casualty-title">Total Assessments</div>
    <div class="md-split">
        <div class="card sw-total-card">
            <div class="stat-label">Assessments Count</div>
            <div class="stat-value"><?= number_format($total) ?></div>
        </div>
        <div class="card sw-breakdown-card">
            <h3>Assessment Count Breakdown</h3>
            <div class="sw-breakdown md-breakdown-2">
                <div class="sw-breakdown-item fatal"><label>Totally Damaged</label><strong><?= number_format($totally) ?></strong></div>
                <div class="sw-breakdown-item injured"><label>Partially Damaged</label><strong><?= number_format($partially) ?></strong></div>
            </div>
        </div>
    </div>

    <div class="sw-casualty-title mt">Assessment Records</div>
    <label class="sw-search">
        <span aria-hidden="true">⌕</span>
        <input type="search" id="mdSearch" placeholder="Search..." autocomplete="off">
    </label>

    <div class="sw-data-table">
        <table class="table" id="mdTable">
            <thead>
                <tr><th>Family Head Name</th><th>Damage Level</th><th>Date</th><th class="vr-right">Action</th></tr>
            </thead>
            <tbody>
            <?php if (!$records): ?>
                <tr><td colspan="4" class="empty">No damage assessments recorded for <?= md_h($selected) ?> yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($records as $r): ?>
                <tr data-search="<?= md_h(strtolower($r['family_head_name'] . ' ' . $r['damage_level'])) ?>">
                    <td><strong><?= md_h($r['family_head_name']) ?></strong></td>
                    <td><?= md_h($r['damage_level']) ?></td>
                    <td><?= md_h(md_date($r['assessed_date'])) ?></td>
                    <td class="vr-right"><button type="button" class="btn btn-dark-sm" onclick="openModal('damageView-<?= (int) $r['id'] ?>')">View</button></td>
                </tr>
            <?php endforeach; ?>
            <tr id="mdNoMatch" hidden><td colspan="4" class="empty">No assessments match your search.</td></tr>
            </tbody>
        </table>
    </div>

    <?php foreach ($records as $r): $mid = 'damageView-' . (int) $r['id']; ?>
        <div id="<?= $mid ?>" class="modal">
            <div class="modal-box md-view-modal">
                <div class="modal-head">
                    <div>
                        <h2>Damage Assessment Details</h2>
                        <div class="mini"><?= md_h($selected) ?> · Record #<?= (int) $r['id'] ?></div>
                    </div>
                    <button type="button" class="icon-btn" onclick="closeModal('<?= $mid ?>')" aria-label="Close">×</button>
                </div>
                <div class="sw-view-grid">
                    <div class="sw-view-item"><label>Family Head</label><div><?= md_h($r['family_head_name']) ?></div></div>
                    <div class="sw-view-item"><label>Damage Level</label><div><?= md_h($r['damage_level']) ?></div></div>
                    <div class="sw-view-item"><label>Date Assessed</label><div><?= md_h(md_date($r['assessed_date'])) ?></div></div>
                    <div class="sw-view-item md-view-wide"><label>Notes</label><div><?= $r['notes'] ? md_h($r['notes']) : 'No notes.' ?></div></div>
                    <div class="sw-view-item"><label>Reference</label><div><?= md_h($r['ref']) ?></div></div>
                    <div class="sw-view-item"><label>Asset Type · Reported Damage</label><div><?= md_h($r['asset_type']) ?> · <?= md_h($r['reported_level']) ?></div></div>
                    <div class="sw-view-item md-view-wide"><label>Photo Evidence (watermarked)</label>
                        <div>
                            <?php if ($r['has_photo']): ?>
                                <img class="md-dmg-photo" alt="Damage photo for record <?= (int) $r['id'] ?>" data-src="damage-photo.php?id=<?= (int) $r['id'] ?>">
                            <?php else: ?>No photo submitted.<?php endif; ?>
                            <?php if ($r['has_photo']): ?>
                                <div class="mini"><a class="md-dmg-link" target="_blank" rel="noopener" href="damage-photo.php?id=<?= (int) $r['id'] ?>&amp;original=1">View original (unwatermarked)</a></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="sw-view-item md-view-wide"><label>Georeference</label>
                        <div>
                            <?php if ($r['lat'] !== null): ?>
                                <?= md_h(number_format($r['lat'], 6)) ?>, <?= md_h(number_format($r['lng'], 6)) ?>
                                <?= $r['accuracy'] !== null ? '(±' . (int) $r['accuracy'] . ' m)' : '' ?>
                                · device GPS read at photo capture
                                · <a class="md-dmg-link" target="_blank" rel="noopener" href="https://www.openstreetmap.org/?mlat=<?= urlencode((string) $r['lat']) ?>&amp;mlon=<?= urlencode((string) $r['lng']) ?>#map=18/<?= urlencode((string) $r['lat']) ?>/<?= urlencode((string) $r['lng']) ?>">Open in OpenStreetMap</a>
                                <?php if (!empty($r['address'])): ?><div class="mini">📍 <?= md_h($r['address']) ?></div><?php endif; ?>
                                <div class="md-dmg-map" data-lat="<?= md_h($r['lat']) ?>" data-lng="<?= md_h($r['lng']) ?>" data-acc="<?= md_h($r['accuracy'] ?? '') ?>"></div>
                            <?php else: ?>No location recorded.<?php endif; ?>
                        </div>
                    </div>
                    <div class="sw-view-item md-view-wide"><label>Integrity Check</label>
                        <div>
                            <span class="badge <?= $r['signed'] ? 'b-green' : 'b-red' ?>"><?= $r['signed'] ? 'Signature valid' : 'Signature mismatch' ?></span>
                            <?php foreach ($r['flags'] as $fl): ?><span class="badge b-yellow"><?= md_h($fl) ?></span> <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="actions">
                    <button type="button" class="btn btn-primary" onclick="closeModal('<?= $mid ?>')">Close</button>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        mdBindSearch('mdSearch', 'mdTable', 'mdNoMatch');

        // Load each record's photo and mini-map only when its modal is opened.
        document.querySelectorAll('.modal[id^="damageView-"]').forEach(function (modal) {
            var done = false;
            new MutationObserver(function () {
                if (!modal.classList.contains('show')) return;
                var img = modal.querySelector('.md-dmg-photo');
                if (img && !img.getAttribute('src')) img.src = img.dataset.src;
                var el = modal.querySelector('.md-dmg-map');
                if (!el) return;
                if (done) { el._map.invalidateSize(); return; }
                done = true;
                var pos = [+el.dataset.lat, +el.dataset.lng];
                var m = L.map(el, { scrollWheelZoom: false }).setView(pos, 17);
                L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                }).addTo(m);
                L.marker(pos).addTo(m);
                if (el.dataset.acc) L.circle(pos, { radius: +el.dataset.acc, weight: 1, color: '#5aa6f0', fillOpacity: 0.15 }).addTo(m);
                el._map = m;
            }).observe(modal, { attributes: true, attributeFilter: ['class'] });
        });
    });
    </script>
<?php endif; ?>
</div>

<?php page_end(); ?>
