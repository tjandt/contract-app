<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
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
    'billing_cycle' => 'monthly', 'current_cost' => '', 'notes' => '', 'cancelled_at' => '',
];

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
            <div class="form-hint">0 = keine automatische Verlängerung / jederzeit kündbar</div>
        </div>

        <div class="form-field">
            <label for="notice_period_days">Kündigungsfrist (Tage)</label>
            <input type="number" min="0" id="notice_period_days" name="notice_period_days" value="<?= e((string) $v['notice_period_days']) ?>">
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
                <label for="cancelled_at">Gekündigt zum (optional)</label>
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
        <div class="form-hint mt-24">
            Hinweis: Änderst du die Kosten hier, wird automatisch ein neuer Eintrag in der Kostenhistorie ab heute angelegt.
        </div>
    <?php endif; ?>

    <div class="form-actions">
        <a href="<?= $contract ? 'contract_view.php?id=' . (int) $contract['id'] : 'contracts.php' ?>" class="btn btn-secondary">Abbrechen</a>
        <button type="submit" class="btn btn-primary">Speichern</button>
    </div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
