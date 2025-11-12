<?php
session_start();
if (!isset($_SESSION['username']) || ($_SESSION['usertype'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit();
}

$embed = isset($_GET['embed']) && $_GET['embed'] == '1';
$err = '';
$ok = '';
$cats = [];
$shouldScroll = false;

$C_MONGO_FAILED = false;
try {
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/db/mongo.php';
    $db = get_mongo_db();
    $catsCol = $db->selectCollection('categories');
    $itemsCol = $db->selectCollection('inventory_items');

    // Add category
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add'])) {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') { $err = 'Category name is required'; }
        else {
            $last = $catsCol->findOne([], ['sort'=>['id'=>-1], 'projection'=>['id'=>1]]);
            $nextId = ($last && isset($last['id']) ? (int)$last['id'] : 0) + 1;
            // Enforce unique name at app layer
            $exists = $catsCol->findOne(['name'=>$name], ['projection'=>['_id'=>1]]);
            if ($exists) { $err = 'Add failed (duplicate?)'; }
            else {
                $catsCol->insertOne(['id'=>$nextId,'name'=>$name,'created_at'=>date('Y-m-d H:i:s')]);
                $ok = 'Category added';
            }
        }
    }

    // Rename category
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rename'])) {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($id <= 0 || $name === '') { $err = 'Invalid rename request'; }
        else {
            $doc = $catsCol->findOne(['id'=>$id], ['projection'=>['name'=>1]]);
            $oldName = trim((string)($doc['name'] ?? ''));
            $catsCol->updateOne(['id'=>$id], ['$set'=>['name'=>$name]]);
            $ok = 'Category renamed';
            if ($oldName !== '' && strcasecmp($oldName, $name) !== 0) {
                $itemsCol->updateMany(['category'=>$oldName], ['$set'=>['category'=>$name]]);
            }
        }
    }

    // Delete category
    if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'delete') {
        $id = (int)$_GET['id'];
        if ($id > 0) {
            $catsCol->deleteOne(['id'=>$id]);
            $ok = 'Category deleted';
        }
    }

    // Load categories
    $cur = $catsCol->find([], ['sort'=>['id'=>-1]]);
    foreach ($cur as $d) {
        $cats[] = [
            'id' => (int)($d['id'] ?? 0),
            'name' => (string)($d['name'] ?? ''),
            'created_at' => (string)($d['created_at'] ?? ''),
        ];
    }
    $shouldScroll = count($cats) > 5;
} catch (Throwable $e) {
    $C_MONGO_FAILED = true;
}

if ($C_MONGO_FAILED) {
    $conn = new mysqli('localhost', 'root', '', 'inventory_system');
    if ($conn->connect_error) {
        http_response_code(500);
        echo 'DB connection failed';
        exit();
    }

    // Ensure categories table exists
    $conn->query("CREATE TABLE IF NOT EXISTS categories (
        id INT(11) NOT NULL AUTO_INCREMENT,
        name VARCHAR(100) NOT NULL UNIQUE,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

    // Ensure models table exists (so cascade delete works when removing categories)
    $conn->query("CREATE TABLE IF NOT EXISTS models (
        id INT(11) NOT NULL AUTO_INCREMENT,
        category_id INT(11) NOT NULL,
        name VARCHAR(150) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_cat_model (category_id, name),
        INDEX idx_category_id (category_id),
        CONSTRAINT fk_models_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

    // Handle add category
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add'])) {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') { $err = 'Category name is required'; }
        else {
            $stmt = $conn->prepare('INSERT INTO categories (name) VALUES (?)');
            $stmt->bind_param('s', $name);
            if ($stmt->execute()) { $ok = 'Category added'; } else { $err = 'Add failed (duplicate?)'; }
            $stmt->close();
        }
    }

    // Handle rename category
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rename'])) {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($id <= 0 || $name === '') { $err = 'Invalid rename request'; }
        else {
            $oldName='';
            $gs = $conn->prepare('SELECT name FROM categories WHERE id = ? LIMIT 1');
            if ($gs) { $gs->bind_param('i', $id); if ($gs->execute()) { $gr=$gs->get_result(); if ($row=$gr->fetch_assoc()) { $oldName=trim($row['name'] ?? ''); } } $gs->close(); }
            $stmt = $conn->prepare('UPDATE categories SET name = ? WHERE id = ?');
            $stmt->bind_param('si', $name, $id);
            if ($stmt->execute()) {
                $ok = 'Category renamed';
                if ($oldName !== '' && strcasecmp($oldName, $name) !== 0) {
                    $up = $conn->prepare('UPDATE inventory_items SET category = ? WHERE category = ?');
                    if ($up) { $up->bind_param('ss', $name, $oldName); $up->execute(); $up->close(); }
                }
            } else { $err = 'Rename failed (duplicate name?)'; }
            $stmt->close();
        }
    }

    // Handle delete category
    if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'delete') {
        $id = (int)$_GET['id'];
        if ($id > 0) {
            $del = $conn->prepare('DELETE FROM categories WHERE id = ?');
            $del->bind_param('i', $id);
            if ($del->execute()) { $ok = 'Category deleted'; } else { $err = 'Delete failed'; }
            $del->close();
        }
    }

    // Load categories
    $cats = [];
    $res = $conn->query('SELECT id, name, created_at FROM categories ORDER BY id DESC');
    if ($res) { while ($r = $res->fetch_assoc()) { $cats[] = $r; } $res->close(); }
    $conn->close();
    $shouldScroll = count($cats) > 5;
}
?>
<!DOCTYPE html>
<html lang="en">
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Manage Categories</title>
  <link href="css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" />
  <?php if ($embed): ?>
  <style>
    html, body { height: 100%; overflow: auto; }
    body { background: #fff; }
    .container { height: 100%; padding-top: 0.5rem; padding-bottom: 0.5rem; }
    .card { height: 100%; display: flex; flex-direction: column; }
    .card-body { flex: 1 1 auto; display: flex; flex-direction: column; padding: 0 !important; }
    .card-header { padding: 0.75rem 1rem; }
    .table-responsive { flex: 1 1 auto; height: 100%; overflow: auto; overflow-x: auto; }
    /* Sticky header for better readability when scrolling */
    .table-responsive thead th { position: sticky; top: 0; z-index: 2; background: #f8f9fa; }
  </style>
  <?php endif; ?>
</head>
<body class="bg-light">
  <div class="container py-4">
    <?php if (!$embed): ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
      
    </div>
    <?php endif; ?>

    <?php if ($err): ?><div class="alert alert-danger"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
    <?php if ($ok): ?><div class="alert alert-success"><?php echo htmlspecialchars($ok); ?></div><?php endif; ?>


    <div class="card">
      <div class="card-header">Categories</div>
      <div class="card-body p-0">
        <div class="table-responsive"<?php echo $embed ? ' style="height: 100%; overflow: auto;"' : ($shouldScroll ? ' style="max-height: 350px; overflow-y: auto;"' : ''); ?>>
          <table class="table table-striped table-hover mb-0">
            <thead class="table-light">
              <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Created</th>
                <th style="width: 160px;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($cats)): ?>
                <tr><td colspan="4" class="p-4 text-muted">No categories yet.</td></tr>
              <?php else: foreach ($cats as $c): ?>
                <tr>
                  <td><?php echo htmlspecialchars($c['id']); ?></td>
                  <td><?php echo htmlspecialchars($c['name']); ?></td>
                  <td><?php echo htmlspecialchars(date('Y-m-d h:i A', strtotime($c['created_at']))); ?></td>
                  <td>
                    <div class="btn-group btn-group-sm">
                      <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#renameModal<?php echo (int)$c['id']; ?>"><i class="bi bi-pencil-square"></i> Edit</button>
                      <a class="btn btn-outline-danger" href="categories.php?action=delete&id=<?php echo urlencode($c['id']); ?>" onclick="return confirm('Delete this category? This will also delete its models.');"><i class="bi bi-trash"></i> Delete</a>
                    </div>
                    <!-- Rename Modal -->
                    <div class="modal fade" id="renameModal<?php echo (int)$c['id']; ?>" tabindex="-1" aria-hidden="true">
                      <div class="modal-dialog modal-dialog-centered">
                        <form method="POST" class="modal-content">
                          <input type="hidden" name="rename" value="1" />
                          <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>" />
                          <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Edit Category</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                          </div>
                          <div class="modal-body">
                            <label class="form-label">Name</label>
                            <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($c['name']); ?>" required />
                          </div>
                          <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Save</button>
                          </div>
                        </form>
                      </div>
                    </div>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
