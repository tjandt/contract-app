<?php
require_once __DIR__ . '/includes/bootstrap.php';
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
$urgency = deadline_urgency($deadline['days_left'], REMINDER_WARN_DAYS, REMINDER_URGENT_DAYS);
$documents = get_contract_documents($pdo, $id);

// Nächste Abbuchung (Zahlungskalender)
$dataset = load_cost_dataset($pdo);
$nextPayments = payments_in_range(
    array_values(array_filter($dataset['contracts'], fn($c) => (int) $c['id'] === $id)),
    $dataset['history'], new DateTimeImmutable('today'), new DateTimeImmutable('+400 days'), today_string()
);
$nextPayment = $nextPayments[0] ?? null;

// Aktuelle Preiserhöhung(en) -> Hinweis auf mögliches Sonderkündigungsrecht
$recentIncreases = array_values(array_filter(
    get_price_overview($pdo)['recent_increases'],
    fn($r) => $r['contract_id'] === $id
));

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
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <button type="submit" class="btn btn-danger">Löschen</button>
        </form>
    </div>
</div>

<?php foreach ($recentIncreases as $inc): ?>
    <div class="alert alert-warn">
        <strong>Preiserhöhung am <?= format_date_de($inc['date']) ?>:</strong>
        <?= format_eur($inc['from']) ?> → <?= format_eur($inc['to']) ?> (<?= e(format_pct($inc['pct'])) ?>).
        Bei einseitigen Preiserhöhungen kann ein Sonderkündigungsrecht bestehen (z. B. bei Strom, Gas, Telekommunikation) –
        bitte Vertrag und Anbieterschreiben prüfen.
    </div>
<?php endforeach; ?>

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
                <div class="info-row"><span class="k">Kündigungsfrist</span><span class="v"><?= e(notice_label($contract)) ?> <?= e(end_alignment_label($contract['end_alignment'] ?? 'none')) ?></span></div>
                <div class="info-row"><span class="k">Kosten</span><span class="v"><?= format_eur((float) $contract['current_cost']) ?> / <?= cycle_label($contract['billing_cycle']) ?></span></div>
                <div class="info-row"><span class="k">Kosten (mtl. umgerechnet)</span><span class="v"><?= format_eur(to_monthly_amount((float) $contract['current_cost'], $contract['billing_cycle'])) ?></span></div>
                <?php if ($nextPayment): ?>
                <div class="info-row"><span class="k">Nächste Abbuchung</span><span class="v"><?= format_date_de($nextPayment['date']) ?> · <?= format_eur($nextPayment['amount']) ?></span></div>
                <?php endif; ?>

                <?php if ($deadline['state'] === 'cancellable'): ?>
                <div class="info-row"><span class="k">Laufzeitende (nächste Periode)</span><span class="v"><?= format_date_de($deadline['period_end']->format('Y-m-d')) ?></span></div>
                <div class="info-row"><span class="k">Kündbar bis</span><span class="v">
                    <span class="badge badge-<?= e($urgency) ?>"><?= format_date_de($deadline['cancel_deadline']->format('Y-m-d')) ?></span>
                    <span class="text-secondary" style="font-weight:400;"> (<?= $deadline['days_left'] === 0 ? 'heute' : 'in ' . $deadline['days_left'] . ' Tag' . ($deadline['days_left'] === 1 ? '' : 'en') ?>)</span>
                </span></div>
                <?php if ($deadline['locked_period_end']): ?>
                <div class="info-row"><span class="k">Hinweis</span><span class="v text-secondary" style="font-weight:400;">
                    Die Frist für die Periode bis <?= format_date_de($deadline['locked_period_end']->format('Y-m-d')) ?> ist verstrichen –
                    der Vertrag verlängert sich automatisch; nächstmöglich kündbar zum <?= format_date_de($deadline['period_end']->format('Y-m-d')) ?>.
                </span></div>
                <?php endif; ?>
                <?php elseif ($deadline['state'] === 'open'): ?>
                <div class="info-row"><span class="k">Kündbarkeit</span><span class="v">
                    <span class="badge badge-neutral">jederzeit kündbar</span>
                    <span class="text-secondary" style="font-weight:400;"> – bei Kündigung heute endet der Vertrag am <?= format_date_de($deadline['earliest_end']->format('Y-m-d')) ?></span>
                </span></div>
                <?php elseif ($deadline['state'] === 'ends'): ?>
                <div class="info-row"><span class="k">Vertragsende</span><span class="v">
                    <?= format_date_de($deadline['period_end']->format('Y-m-d')) ?>
                    <span class="text-secondary" style="font-weight:400;"> (endet automatisch, keine Verlängerung)</span>
                </span></div>
                <?php endif; ?>

                <?php if ($contract['cancellation_submitted_at']): ?>
                <div class="info-row"><span class="k">Kündigung eingereicht</span><span class="v"><?= format_date_de($contract['cancellation_submitted_at']) ?></span></div>
                <div class="info-row"><span class="k">Bestätigung des Anbieters</span><span class="v">
                    <?php if ($contract['cancellation_confirmed_at']): ?>
                        <span class="badge badge-ok">erhalten am <?= format_date_de($contract['cancellation_confirmed_at']) ?></span>
                    <?php else: ?>
                        <span class="badge badge-warn">noch ausstehend</span>
                    <?php endif; ?>
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

        <?php if ($deadline['cancel_deadline']): ?>
        <div class="card mt-24">
            <div class="section-title">iCal-Export</div>
            <p class="text-secondary" style="margin:0 0 12px; font-size:13.5px;">Kündigungstermin in deinen Kalender importieren.</p>
            <a class="btn btn-secondary btn-sm" href="ical.php?id=<?= $id ?>">Termin exportieren (.ics)</a>
        </div>
        <?php endif; ?>

        <div class="card mt-24">
            <div class="section-title">Dokumente</div>
            <?php if (empty($documents)): ?>
                <div class="empty-state" style="padding:10px 0;">Noch keine Dokumente.</div>
            <?php endif; ?>
            <?php foreach ($documents as $doc): ?>
            <div class="history-item">
                <div>
                    <div>
                        <?php if (in_array($doc['mime_type'], DOCUMENT_INLINE_TYPES, true)): ?>
                            <a href="document_download.php?id=<?= (int) $doc['id'] ?>&amp;inline=1" target="_blank" rel="noopener" class="provider-link"><?= e($doc['original_name']) ?></a>
                        <?php else: ?>
                            <a href="document_download.php?id=<?= (int) $doc['id'] ?>" class="provider-link"><?= e($doc['original_name']) ?></a>
                        <?php endif; ?>
                    </div>
                    <div class="reason"><?= e(doc_type_label($doc['doc_type'])) ?> · <?= format_bytes((int) $doc['size_bytes']) ?> · <?= format_date_de(substr($doc['uploaded_at'], 0, 10)) ?></div>
                </div>
                <div class="history-item-right">
                    <a href="document_download.php?id=<?= (int) $doc['id'] ?>" class="icon-btn" title="Herunterladen" aria-label="Herunterladen">⤓</a>
                    <form method="post" action="document_delete.php" class="inline-form" onsubmit="return confirm('Dieses Dokument wirklich löschen?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $doc['id'] ?>">
                        <button type="submit" class="history-delete-btn" title="Dokument löschen" aria-label="Dokument löschen">✕</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>

            <form method="post" action="document_upload.php" enctype="multipart/form-data" class="upload-form">
                <?= csrf_field() ?>
                <input type="hidden" name="contract_id" value="<?= $id ?>">
                <div class="input-group">
                    <input type="file" name="document" required accept=".pdf,.png,.jpg,.jpeg,.webp,.txt,.docx" aria-label="Datei auswählen">
                    <select name="doc_type" aria-label="Dokumenttyp">
                        <?php foreach (DOCUMENT_TYPES as $t): ?>
                            <option value="<?= $t ?>"><?= e(doc_type_label($t)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-hint">PDF, Bilder, TXT oder DOCX, max. <?= format_bytes(DOCUMENT_MAX_BYTES) ?>.</div>
                <button type="submit" class="btn btn-secondary btn-sm" style="margin-top:10px;">Hochladen</button>
            </form>
        </div>
    </div>

    <div>
        <div class="card">
            <div class="section-title">Kostenänderung erfassen</div>
            <p class="text-secondary" style="margin:0 0 14px; font-size:13.5px;">
                Wurde der Preis erhöht (oder gesenkt)? Trage die Änderung hier ein – sie wird in der Historie festgehalten und aktualisiert automatisch den aktuellen Preis.
            </p>
            <form method="post" action="cost_update.php">
                <?= csrf_field() ?>
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
                        <div><?= format_date_de($h['valid_from']) ?><?php if ($h['valid_from'] > date('Y-m-d')): ?> <span class="badge badge-neutral">geplant</span><?php endif; ?></div>
                        <?php if ($h['reason']): ?><div class="reason"><?= e($h['reason']) ?></div><?php endif; ?>
                    </div>
                    <div class="history-item-right">
                        <div class="amt"><?= format_eur((float) $h['amount']) ?></div>
                        <form method="post" action="cost_history_delete.php" class="inline-form" onsubmit="return confirm('Diesen Kostenhistorie-Eintrag wirklich löschen?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="history_id" value="<?= (int) $h['id'] ?>">
                            <input type="hidden" name="contract_id" value="<?= (int) $contract['id'] ?>">
                            <button type="submit" class="history-delete-btn" title="Eintrag löschen" aria-label="Eintrag löschen">✕</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
