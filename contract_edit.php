<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pdo = get_pdo();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$contract = null;

if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM contracts WHERE id = ?");
    $stmt->execute([$id]);
    $contract = $stmt->fetch();
    if (!$contract) {
        http_response_code(404);
        die('Vertrag nicht gefunden.');
    }
}

$categories = $pdo->query("SELECT * FROM categories ORDER BY sort_order")->fetchAll();

$v = $contract ?? [
    'name' => '', 'category_id' => '', 'provider_name' => '', 'provider_url' => '',
    'contract_number' => '', 'status' => 'active', 'start_date' => date('Y-m-d'),
    'initial_term_months' => 12, 'renewal_term_months' => 12, 'notice_period_days' => 30,
    'notice_period_unit' => 'days', 'end_alignment' => 'none',
    'billing_cycle' => 'monthly', 'billing_anchor_date' => '', 'current_cost' => '', 'notes' => '',
    'cancelled_at' => '', 'cancellation_submitted_at' => '', 'cancellation_confirmed_at' => '',
];

// Nach einem Validierungsfehler die eingegebenen Werte wieder einsetzen
$oldInput = $_SESSION['old_input'] ?? null;
unset($_SESSION['old_input']);
if (is_array($oldInput)) {
    $v = array_merge($v, array_intersect_key($oldInput, $v + ['price_valid_from' => 1]));
}

$pageTitle = $contract ? 'Vertrag bearbeiten' : 'Neuer Vertrag';
$activeNav = 'contracts';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1><?= $contract ? 'Vertrag bearbeiten' : 'Neuer Vertrag' ?></h1>
        <p><?= $contract ? e($contract['name']) : 'Trage die Stammdaten deines Vertrags ein' ?></p>
    </div>
</div>

<form method="post" action="contract_save.php" class="card">
    <?= csrf_field() ?>
    <?php if ($contract): ?><input type="hidden" name="id" value="<?= (int) $contract['id'] ?>"><?php endif; ?>

    <div class="form-grid">
        <div class="form-field full">
            <label for="name">Vertragsname *</label>
            <input type="text" id="name" name="name" required value="<?= e($v['name']) ?>" placeholder="z. B. Stromvertrag Stadtwerke">
        </div>

        <div class="form-field">
            <label for="category_id">Kategorie</label>
            <select id="category_id" name="category_id">
                <option value="">– keine –</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= (string) $v['category_id'] === (string) $cat['id'] ? 'selected' : '' ?>>
                        <?= e($cat['icon']) ?> <?= e($cat['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-field">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="active" <?= $v['status'] !== 'paused' ? 'selected' : '' ?>>Aktiv</option>
                <option value="paused" <?= $v['status'] === 'paused' ? 'selected' : '' ?>>Pausiert</option>
            </select>
            <div class="form-hint">Gilt nur, solange unten keine Kündigung eingetragen ist.</div>
        </div>

        <div class="form-field">
            <label for="provider_name">Anbieter</label>
            <input type="text" id="provider_name" name="provider_name" value="<?= e($v['provider_name']) ?>" placeholder="z. B. Vodafone">
        </div>

        <div class="form-field">
            <label for="provider_url">Link zum Anbieter</label>
            <input type="url" id="provider_url" name="provider_url" value="<?= e($v['provider_url']) ?>" placeholder="https://…">
        </div>

        <div class="form-field">
            <label for="contract_number">Vertragsnummer</label>
            <input type="text" id="contract_number" name="contract_number" value="<?= e($v['contract_number']) ?>">
        </div>

        <div class="form-field">
            <label for="start_date">Vertragsbeginn *</label>
            <input type="date" id="start_date" name="start_date" required value="<?= e($v['start_date']) ?>">
        </div>

        <div class="form-field">
            <label for="current_cost">Kosten je Abrechnungszyklus (€) *</label>
            <input type="number" step="0.01" min="0" id="current_cost" name="current_cost" required value="<?= e((string) $v['current_cost']) ?>">
        </div>

        <div class="form-field">
            <label for="billing_cycle">Abrechnungszyklus</label>
            <select id="billing_cycle" name="billing_cycle">
                <option value="monthly" <?= $v['billing_cycle'] === 'monthly' ? 'selected' : '' ?>>Monatlich</option>
                <option value="quarterly" <?= $v['billing_cycle'] === 'quarterly' ? 'selected' : '' ?>>Vierteljährlich</option>
                <option value="yearly" <?= $v['billing_cycle'] === 'yearly' ? 'selected' : '' ?>>Jährlich</option>
            </select>
        </div>

        <div class="form-field">
            <label for="initial_term_months">Erstlaufzeit (Monate)</label>
            <input type="number" min="0" id="initial_term_months" name="initial_term_months" value="<?= e((string) $v['initial_term_months']) ?>">
        </div>

        <div class="form-field">
            <label for="renewal_term_months">Verlängerung (Monate)</label>
            <input type="number" min="0" id="renewal_term_months" name="renewal_term_months" value="<?= e((string) $v['renewal_term_months']) ?>">
            <div class="form-hint">0 = keine automatische Verlängerung: Der Vertrag endet nach der Erstlaufzeit von selbst bzw. läuft danach unbefristet und ist jederzeit kündbar.</div>
        </div>

        <div class="form-field">
            <label for="notice_period_days">Kündigungsfrist</label>
            <div class="input-group">
                <input type="number" min="0" id="notice_period_days" name="notice_period_days" value="<?= e((string) $v['notice_period_days']) ?>">
                <select name="notice_period_unit" aria-label="Einheit der Kündigungsfrist">
                    <option value="days" <?= $v['notice_period_unit'] !== 'months' ? 'selected' : '' ?>>Tage</option>
                    <option value="months" <?= $v['notice_period_unit'] === 'months' ? 'selected' : '' ?>>Monate</option>
                </select>
            </div>
            <div class="form-hint">Die Kündigung muss spätestens so lange vor dem Vertragsende zugehen.</div>
        </div>

        <div class="form-field">
            <label for="end_alignment">Vertragsende</label>
            <select id="end_alignment" name="end_alignment">
                <?php foreach (END_ALIGNMENTS as $al): ?>
                    <option value="<?= $al ?>" <?= $v['end_alignment'] === $al ? 'selected' : '' ?>><?= e(ucfirst(end_alignment_label($al))) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-hint">Bei Klauseln wie „Kündigung zum Monats-/Quartalsende“: Das Laufzeitende wird auf das jeweilige Ende aufgerundet.</div>
        </div>

        <div class="form-field">
            <label for="billing_anchor_date">Abbuchung ab / Fälligkeit (optional)</label>
            <input type="date" id="billing_anchor_date" name="billing_anchor_date" value="<?= e($v['billing_anchor_date'] ?? '') ?>">
            <div class="form-hint">Datum einer Abbuchung, z. B. die jährliche Beitragsfälligkeit. Daraus ergibt sich der Zahlungskalender. Leer = Vertragsbeginn.</div>
        </div>

        <div class="form-field full">
            <label for="notes">Notizen</label>
            <textarea id="notes" name="notes" placeholder="z. B. Sonderkonditionen, Rabatte…"><?= e($v['notes']) ?></textarea>
        </div>
    </div>

    <div class="mt-24" style="padding-top:20px; border-top:1px solid var(--border);">
        <div class="section-title">Kündigung</div>
        <div class="form-grid">
            <div class="form-field">
                <label for="cancellation_submitted_at">Kündigung eingereicht am (optional)</label>
                <input type="date" id="cancellation_submitted_at" name="cancellation_submitted_at" value="<?= e($v['cancellation_submitted_at'] ?? '') ?>">
                <div class="form-hint">
                    Datum, an dem du die Kündigung abgeschickt hast. Ist „Gekündigt zum“ leer, berechnet die App das
                    Vertragsende aus Laufzeit und Kündigungsfrist.
                </div>
            </div>

            <div class="form-field">
                <label for="cancellation_confirmed_at">Bestätigung des Anbieters erhalten am</label>
                <input type="date" id="cancellation_confirmed_at" name="cancellation_confirmed_at" value="<?= e($v['cancellation_confirmed_at'] ?? '') ?>">
                <div class="form-hint">Solange hier nichts steht, erinnert dich das Dashboard nach <?= CANCELLATION_CONFIRMATION_DAYS ?> Tagen an die fehlende Bestätigung.</div>
            </div>

            <div class="form-field">
                <label for="cancelled_at">Gekündigt zum / Vertragsende (optional)</label>
                <input type="date" id="cancelled_at" name="cancelled_at" value="<?= e($v['cancelled_at'] ?? '') ?>">
                <div class="form-hint">
                    Kann in der Zukunft liegen (Vertrag läuft bis dahin ganz normal in den Kosten mit)
                    oder in der Vergangenheit (z. B. um einen bereits beendeten Vertrag nachträglich
                    für die Kostenhistorie zu erfassen). Leer lassen, wenn noch nicht gekündigt.
                </div>
            </div>
        </div>
    </div>

    <?php if ($contract): ?>
        <div class="mt-24" style="padding-top:20px; border-top:1px solid var(--border);">
            <div class="form-field" style="max-width:320px;">
                <label for="price_valid_from">Geänderten Preis gültig ab</label>
                <input type="date" id="price_valid_from" name="price_valid_from" value="<?= e($oldInput['price_valid_from'] ?? date('Y-m-d')) ?>">
                <div class="form-hint">
                    Gilt nur, wenn du die Kosten oben änderst: Es wird ein Eintrag in der Kostenhistorie ab diesem Datum angelegt.
                    Ein Datum in der Zukunft wird am Stichtag automatisch wirksam.
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="form-actions">
        <a href="<?= $contract ? 'contract_view.php?id=' . (int) $contract['id'] : 'contracts.php' ?>" class="btn btn-secondary">Abbrechen</a>
        <button type="submit" class="btn btn-primary">Speichern</button>
    </div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
