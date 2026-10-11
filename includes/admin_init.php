<?php
require_once __DIR__ . '/../config.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Create users table if it doesn't exist
        $createSql = "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            role ENUM('admin','staff') NOT NULL DEFAULT 'staff',
            full_name VARCHAR(100) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;";

        $pdo->exec($createSql);

        // Check if admin user already exists
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
        $stmt->execute([':username' => 'admin']);
        $exists = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($exists) {
            $message = 'Admin account already exists. No changes made.';
        } else {
            $hash = password_hash('admin123', PASSWORD_DEFAULT);
            $insert = $pdo->prepare('INSERT INTO users (username, password, role, full_name) VALUES (:username, :password, :role, :full_name)');
            $insert->execute([
                ':username' => 'admin',
                ':password' => $hash,
                ':role' => 'admin',
                ':full_name' => 'System Administrator'
            ]);

            $message = 'Default admin created: username "admin" password "admin123". DELETE this file after use.';
        }
    } catch (PDOException $e) {
        // (printed once, escaped below; the details go to the PHP error log)
        error_log('admin_init.php: ' . $e->getMessage());
        $message = 'The admin account could not be created because of a database problem. Check that the database was imported (database/lgu_assistance_system.sql), then try again.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Admin Initializer</title>
    <style>body{font-family:Arial,Helvetica,sans-serif;margin:40px} .card{max-width:600px;padding:18px;border:1px solid #ddd;border-radius:6px} button{padding:10px 14px}</style>
</head>
<body>
<div class="card">
    <h2>One-time Admin Initializer</h2>
    <p>This script creates a default admin account for the application.</p>
    <p><strong>Default credentials:</strong> <code>admin</code> / <code>admin123</code></p>
    <?php if ($message): ?>
        <p><strong><?php echo htmlspecialchars($message); ?></strong></p>
    <?php endif; ?>

    <form method="post">
        <p>Click the button below to create the default admin account (if it does not already exist).</p>
        <button type="submit">Create Default Admin Account</button>
    </form>

    <hr>
    <p style="color:#a00"><strong>Security:</strong> Delete or move this file after creating the admin account.</p>
    <p>Login at: <a href="../login/login.php?role=admin">login/login.php</a></p>
</div>
</body>
</html>
