<?php require_once '../includes/access.php'; require_page_access(); require '../includes/layout.php'; page_start('Casualty Monitoring'); 
$rows=[['CR-001','Brgy. Jaro','John Santos','Injured','Minor','2026-05-01'],['CR-002','Brgy. Jaro','Maria Cruz','Injured','Moderate','2026-05-01'],['CR-003','Brgy. Molo','Pedro Reyes','Missing','—','2026-05-01'],['CR-004','Brgy. Arevalo','Ana Garcia','Fatality','Critical','2026-04-30'],['CR-005','Brgy. La Paz','Jose Flores','Injured','Minor','2026-04-30']];
?>

<div class="page-head">
    <h1 class="page-title">Casualty Monitoring</h1>
    <button class="btn btn-primary" onclick="openModal('casualtyModal')">＋ Record Casualty</button>
</div>

<div class="grid g4">
    <?php foreach([['89','Total Casualties','red'],['62','Injured','yellow'],['18','Missing','blue'],['9','Fatalities','gray']] as $s): ?>
        <div class="card">
            <div class="stat">
                <div class="stat-icon <?=$s[2]?>">⚠</div>
                <div>
                    <div class="stat-value"><?=$s[0]?></div>
                    <div class="stat-label"><?=$s[1]?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>


<div class="grid g2 mt">
    <div class="card">
        <h2>Casualty Count per Barangay</h2>
        <?php foreach([['Jaro',31],['Molo',20],['Mandurriao',16],['Arevalo',12],['La Paz',10]] as $x): ?>
            <div class="barangay-row">
                <div class="barangay-row-head">
                    <span>Brgy. <?=$x[0]?></span>
                    <b><?=$x[1]?></b>
                </div>
                <div class="progress-track progress-track--lg">
                    <div class="progress-fill progress-fill--red" style="--fill:<?=$x[1]*2.8?>%"></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <h2>Severity Summary</h2>
    <div class="grid g2">
        <div class="alert alert-danger">
            <b>Critical</b>
            <div class="mini">9 cases</div>
        </div>
        <div class="alert alert-warning">
            <b>Moderate</b>
            <div class="mini">18 cases</div>
        </div>
        <div class="alert alert-info">
            <b>Minor</b>
            <div class="mini">53 cases</div>
        </div>
        <div class="alert alert-success">
            <b>Resolved</b>
            <div class="mini">41 cases</div>
        </div>
    </div>
</div>

<div class="card mt">
    <h2>Casualty Records</h2>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Barangay</th>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Severity</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($rows as $r): ?>
                    <tr>
                        <?php foreach($r as $i=>$v): ?>
                            <td>
                                <?=$i===3?status_badge($v):htmlspecialchars($v)?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!--Record Casualty form-->
<div id="casualtyModal" class="modal">
    <div class="modal-box">
        <div class="modal-head">
            <h2>Record Casualty</h2>
            <button class="icon-btn" onclick="closeModal('casualtyModal')">✕</button>
        </div>
        <form onsubmit="event.preventDefault();alert('Casualty record saved.');closeModal('casualtyModal')">
            <div class="form-grid">
                <div class="field">
                    <label>Barangay</label>
                    <input required>
                </div>
                <div class="field">
                    <label>Name</label>
                    <input required>
                </div>
                <div class="field">
                    <label>Type</label>
                    <select>
                        <option>Injured</option>
                        <option>Missing</option>
                        <option>Fatality</option>
                    </select>
                </div>
                <div class="field">
                    <label>Severity</label>
                    <select>
                        <option>Minor</option>
                        <option>Moderate</option>
                        <option>Critical</option>
                    </select>
                </div>
                <div class="field">
                    <label>Date</label>
                    <input type="date">
                </div>
                <div class="field">
                    <label>Notes</label>
                    <input>
                </div>
            </div>
            <div class="actions mt">
                <button class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>
<?php page_end(); ?>