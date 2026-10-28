<?php
require_once '../config.php';
require_once 'account_layout.php';
require_once '../includes/documents.php';

$ctx = account_context($pdo);
$account_id = (int)$ctx['user']['id'];

$stmt = $pdo->prepare("
    SELECT d.id, d.document_name, d.file_path, d.uploaded_at,
           a.id AS app_id, a.assistance_type_id, a.status, a.created_at AS applied_at
    FROM applicant_documents d
    JOIN applicant a ON d.applicant_id = a.id
    WHERE a.account_id = ?
    ORDER BY a.created_at DESC, d.document_name, d.id
");
$stmt->execute([$account_id]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group by application
$apps = [];
foreach ($rows as $r) {
    $apps[$r['app_id']]['info'] = $r;
    $apps[$r['app_id']]['docs'][] = $r;
}

account_page_start($ctx, 'My Documents', 'documents', [
    'title'    => 'My Documents',
    'subtitle' => count($rows) . ' file' . (count($rows) === 1 ? '' : 's') . ' from ' . count($apps) . ' application' . (count($apps) === 1 ? '' : 's') . '.',
    'icon'     => 'fa-folder-open',
]);
?>

<?php if ($apps): ?>
<div class="ap-card acc-list-card">
    <div class="acc-list-head">
        <h3 class="acc-h3">Uploaded documents</h3>
        <div class="acc-toolbar">
            <label class="acc-search">
                <i class="fas fa-search"></i>
                <input type="search" id="docSearch" placeholder="Search documents" aria-label="Search documents">
            </label>
            <select id="docType" aria-label="Filter by program">
                <option value="">All programs</option>
                <?php foreach ($ASSISTANCE_PROGRAMS as $p): ?>
                    <option value="<?= $p['type_id'] ?>"><?= e($p['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <?php foreach ($apps as $app_id => $g):
        $i = $g['info'];
        $p = get_program_by_type($i['assistance_type_id']); ?>
    <section class="acc-doc-group" data-doc-group data-type="<?= (int)$i['assistance_type_id'] ?>">
        <header class="acc-doc-group-head">
            <span class="acc-app-icon tone-<?= $p['tone'] ?? 'blue' ?>"><i class="fas <?= $p['icon'] ?? 'fa-file-alt' ?>"></i></span>
            <div>
                <strong><?= e($p['name'] ?? 'Application') ?> · #<?= (int)$app_id ?></strong>
                <small class="ap-muted">Submitted <?= e(date('M d, Y', strtotime($i['applied_at']))) ?> · <?= count($g['docs']) ?> file<?= count($g['docs']) === 1 ? '' : 's' ?></small>
            </div>
            <span class="acc-status s-<?= strtolower($i['status']) ?>"><?= e($i['status']) ?></span>
            <a href="view_application?id=<?= (int)$app_id ?>" class="ap-link-btn">View application <i class="fas fa-chevron-right"></i></a>
        </header>
        <div class="acc-gallery">
            <?php foreach ($g['docs'] as $d):
                $url = document_url((int)$d['id'], false, '');   // opened through document.php (checks it is yours)
                $is_img = preg_match('/\.(jpe?g|png|webp|gif)$/i', $d['file_path']); ?>
            <figure class="acc-doc" data-doc data-name="<?= e(strtolower($d['document_name'])) ?>">
                <?php if ($is_img): ?>
                    <a href="<?= e($url) ?>" class="acc-shot" data-lightbox data-caption="<?= e($d['document_name']) ?>">
                        <img src="<?= e($url) ?>" alt="<?= e($d['document_name']) ?>" loading="lazy">
                    </a>
                <?php else: ?>
                    <a href="<?= e($url) ?>" class="acc-shot acc-shot-file" target="_blank" rel="noopener"><i class="far fa-file-alt"></i></a>
                <?php endif; ?>
                <figcaption>
                    <strong><?= e($d['document_name']) ?></strong>
                    <small class="ap-muted"><?= e(date('M d, Y', strtotime($d['uploaded_at']))) ?></small>
                    <a href="<?= e(document_url((int)$d['id'], true, '')) ?>" download class="ap-link-btn acc-small"><i class="fas fa-download"></i> Download</a>
                </figcaption>
            </figure>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endforeach; ?>

    <div class="acc-empty" id="docNoMatch" hidden>
        <i class="fas fa-search"></i>
        <p>No documents match your search.</p>
    </div>
</div>
<?php else: ?>
<div class="ap-card acc-card acc-empty">
    <i class="far fa-folder-open"></i>
    <h4>No documents yet</h4>
    <p>The photos you upload with your applications will appear here.</p>
    <a href="landingpage#programs" class="ap-btn ap-btn-primary"><i class="fas fa-plus"></i> Start an application</a>
</div>
<?php endif; ?>

<?php account_page_end(); ?>
