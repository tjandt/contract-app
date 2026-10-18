<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$pdo = get_pdo();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT c.*, cat.name AS category_name, cat.color_hex, cat.icon
                        FROM contracts c
                        LEFT JOIN categories cat ON cat.id = c.category_id
                        WHERE c.id = ?");
$stmt->execute([$id]);
$contract = $stmt->fetch();

if (!$contract) {
    http_response_code(404);
    die('Vertrag nicht gefunden.');
}

$histStmt = $pdo->prepare("SELECT * FROM cost_history WHERE contract_id = ? ORDER BY valid_from DESC, id DESC");
$histStmt->execute([$id]);
$history = $histStmt->fetchAll();

$deadline = calculate_contract_deadline($contract);
$urgency = deadline_urgency($deadline['days_left']);

$pageTitle = $contract['name'];
$activeNav = 'contracts';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($contract['name']) ?></h1>
        <p>
            <?php if ($contract['category_name']): ?>
                <span class="cat-pill">
                    <span class="cat-dot" style="background: <?= e($contract['color_hex']) ?>"></span>
                    <?= e($contract['category_name']) ?>
                </span>
            <?php endif; ?>
            &nbsp;·&nbsp; <span class="badge <?= effective_status_badge_class($contract) ?>"><?= effective_status_label($contract) ?></span>
        </p>
    </div>
    <div style="display:flex; gap:10px;">
        <a href="contract_edit.php?id=<?= $id ?>" class="btn btn-secondary">Bearbeiten</a>
        <form method="post" action="contract_delete.php" onsubmit="return confirm('Diesen Vertrag wirklich unwiderruflich löschen?');">
            <input type="hidden" name="id" value="<?= $id ?>">
            <button type="submit" class="btn btn-danger">Löschen</button>
        </form>
    </div>
</div>

<?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success">Gespeichert.</div>
<?php endif; ?>

<div class="detail-grid">
    <div>
        <div class="card">
            <div class="section-title">Stammdaten</div>
            <div class="info-list">
                <div class="info-row"><span class="k">Anbieter</span><span class="v"><?= e($contract['provider_name']) ?: '–' ?></span></div>
                <div class="info-row"><span class="k">Link</span><span class="v">
                    <?php if ($contract['provider_url']): ?>
                        <a class="provider-link" href="<?= e($contract['provider_url']) ?>" target="_blank" rel="noopener">Zum Anbieter ↗</a>
                    <?php else: ?>–<?php endif; ?>
                </span></div>
                <div class="info-row"><span class="k">Vertragsnummer</span><span class="v"><?= e($contract['contract_number']) ?: '–' ?></span></div>
                <div class="info-row"><span class="k">Vertragsbeginn</span><span class="v"><?= format_date_de($contract['start_date']) ?></span></div>
                <div class="info-row"><span class="k">Erstlaufzeit</span><span class="v"><?= (int) $contract['initial_term_months'] ?> Monate</span></div>
                <div class="info-row"><span class="k">Verlängerung</span><span class="v"><?= $contract['renewal_term_months'] > 0 ? $contract['renewal_term_months'] . ' Monate (automatisch)' : 'keine' ?></span></div>
                <div class="info-row"><span class="k">Kündigungsfrist</span><span class="v"><?= (int) $contract['notice_period_days'] ?> Tage</span></div>
                <div class="info-row"><span class="k">Kosten</span><span class="v"><?= format_eur((float) $contract['current_cost']) ?> / <?= cycle_label($contract['billing_cycle']) ?></span></div>
                <div class="info-row"><span class="k">Kosten (mtl. umgerechnet)</span><span class="v"><?= format_eur(to_monthly_amount((float) $contract['current_cost'], $contract['billing_cycle'])) ?></span></div>
                <?php if ($contract['status'] === 'active' && $deadline['period_end']): ?>
                <div class="info-row"><span class="k">Laufzeitende (aktuelle Periode)</span><span class="v"><?= format_date_de($deadline['period_end']->format('Y-m-d')) ?></span></div>
                <div class="info-row"><span class="k">Kündbar bis</span><span class="v">
                    <span class="badge badge-<?= $urgency ?>"><?= format_date_de($deadline['cancel_deadline']->format('Y-m-d')) ?></span>
                </span></div>
                <?php endif; ?>
                <?php if ($contract['cancelled_at']): ?>
                <div class="info-row"><span class="k">Gekündigt zum</span><span class="v">
                    <?= format_date_de($contract['cancelled_at']) ?>
                    <?php if (is_contract_running($contract)): ?>
                        <span class="text-secondary" style="font-weight:400;"> (läuft noch)</span>
                    <?php else: ?>
                        <span class="text-secondary" style="font-weight:400;"> (beendet)</span>
                    <?php endif; ?>
                </span></div>
                <?php endif; ?>
                <?php if ($contract['notes']): ?>
                <div class="info-row"><span class="k">Notizen</span><span class="v"><?= nl2br(e($contract['notes'])) ?></span></div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($contract['provider_url']): ?>
        <div class="card mt-24">
            <div class="section-title">iCal-Export</div>
            <p class="text-secondary" style="margin:0 0 12px; font-size:13.5px;">Kündigungstermin in deinen Kalender importieren.</p>
            <a class="btn btn-secondary btn-sm" href="ical.php?id=<?= $id ?>">Termin exportieren (.ics)</a>
        </div>
        <?php endif; ?>
    </div>

    <div>
        <div class="card">
            <div class="section-title">Kostenänderung erfassen</div>
            <p class="text-secondary" style="margin:0 0 14px; font-size:13.5px;">
                Wurde der Preis erhöht (oder gesenkt)? Trage die Änderung hier ein – sie wird in der Historie festgehalten und aktualisiert automatisch den aktuellen Preis.
            </p>
            <form method="post" action="cost_update.php">
                <input type="hidden" name="contract_id" value="<?= $id ?>">
                <div class="form-field" style="margin-bottom:14px;">
                    <label for="amount">Neuer Betrag (€ / <?= cycle_label($contract['billing_cycle']) ?>)</label>
                    <input type="number" step="0.01" min="0" id="amount" name="amount" required>
                </div>
                <div class="form-field" style="margin-bottom:14px;">
                    <label for="valid_from">Gültig ab</label>
                    <input type="date" id="valid_from" name="valid_from" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-field" style="margin-bottom:16px;">
                    <label for="reason">Grund (optional)</label>
                    <input type="text" id="reason" name="reason" placeholder="z. B. Preiserhöhung Energiekosten">
                </div>
                <button type="submit" class="btn btn-primary btn-block">Änderung speichern</button>
            </form>
        </div>

        <div class="card mt-24">
            <div class="section-title">Kostenhistorie</div>
            <?php if (empty($history)): ?>
                <div class="empty-state">Noch keine Einträge.</div>
            <?php else: ?>
                <?php foreach ($history as $h): ?>
                <div class="history-item">
                    <div>
                        <div><?= format_date_de($h['valid_from']) ?></div>
                        <?php if ($h['reason']): ?><div class="reason"><?= e($h['reason']) ?></div><?php endif; ?>
                    </div>
                    <div class="history-item-right">
                        <div class="amt"><?= format_eur((float) $h['amount']) ?></div>
                        <form method="post" action="cost_history_delete.php" onsubmit="return confirm('Diesen Kostenhistorie-Eintrag wirklich löschen?');">
                            <input type="hidden" name="history_id" value="<?= (int) $h['id'] ?>">
                            <input type="hidden" name="contract_id" value="<?= (int) $contract['id'] ?>">
                            <button type="submit" class="history-delete-btn" title="Eintrag löschen">✕</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
