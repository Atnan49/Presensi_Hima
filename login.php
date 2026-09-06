<?php
// ============================================
// login.php - Halaman Login Administrator
// Desain: Neo-Brutalism (Bold, Minimalist, High Contrast)
// ============================================

require_once 'config.php';
startSessionSafe();

// Jika sudah login, langsung ke dashboard
if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($token)) {
        $error = 'Validasi sesi keamanan (CSRF) gagal. Silakan muat ulang halaman.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = 'Username dan password wajib diisi.';
        } else {
            try {
                $db = getDB();
                $stmt = $db->prepare("SELECT id, username, password_hash, name FROM admins WHERE username = ? LIMIT 1");
                $stmt->execute([$username]);
                $admin = $stmt->fetch();

                if ($admin && password_verify($password, $admin['password_hash'])) {
                    // Login Berhasil
                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['admin_user'] = [
                        'id'       => $admin['id'],
                        'username' => $admin['username'],
                        'name'     => $admin['name']
                    ];
                    header('Location: index.php');
                    exit;
                } else {
                    $error = 'Username atau password yang Anda masukkan salah.';
                }
            } catch (Exception $e) {
                $error = 'Terjadi kesalahan sistem: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login Admin - Sistem Presensi Mahasiswa</title>
  <link rel="stylesheet" href="assets/style.css">
  <style>
    body {
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      background-color: var(--bg-main);
      padding: 20px;
    }
    .login-container {
      width: 100%;
      max-width: 420px;
    }
    .login-card {
      background: var(--bg-main);
      border: 3px solid #000000;
      box-shadow: 8px 8px 0px #000000;
      padding: 32px 28px;
    }
    .login-header {
      margin-bottom: 24px;
      padding-bottom: 16px;
      border-bottom: 3px solid #000000;
    }
    .login-logo {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: var(--color-yellow);
      color: #000000;
      border: 2px solid #000000;
      padding: 4px 12px;
      font-family: var(--font-mono);
      font-weight: 800;
      font-size: 13px;
      letter-spacing: 0.12em;
      box-shadow: 2px 2px 0px #000000;
      margin-bottom: 12px;
    }
    .login-title {
      font-size: 20px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--text-main);
      line-height: 1.2;
    }
    .login-subtitle {
      font-size: 12px;
      font-family: var(--font-mono);
      color: var(--text-secondary);
      margin-top: 6px;
      font-weight: 600;
    }
    .login-footer {
      margin-top: 24px;
      padding-top: 16px;
      border-top: 2px dashed #000000;
      font-size: 11px;
      font-family: var(--font-mono);
      color: var(--text-secondary);
      text-align: center;
    }
  </style>
</head>
<body>

<div class="login-container">
  <div class="login-card">
    <div class="login-header">
      <div class="login-logo">RFID PRESENSI</div>
      <h1 class="login-title">Autentikasi Admin</h1>
      <div class="login-subtitle">Masuk untuk mengelola presensi &amp; data panitia</div>
    </div>

    <?php if (!empty($error)): ?>
      <div class="alert-box alert-danger mb-3">
        <div class="alert-content">
          <div class="alert-title" style="color: var(--color-red);">Akses Ditolak</div>
          <div class="alert-desc" style="color: var(--text-main);"><?= htmlspecialchars($error) ?></div>
        </div>
      </div>
    <?php endif; ?>

    <form method="POST" action="login.php">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">

      <div class="form-group">
        <label class="form-label" for="username">Username Administrator</label>
        <input type="text" id="username" name="username" placeholder="Masukkan username" required autofocus
               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" autocomplete="username">
      </div>

      <div class="form-group">
        <label class="form-label" for="password">Password</label>
        <input type="password" id="password" name="password" placeholder="Masukkan password" required
               autocomplete="current-password"
               style="width: 100%; padding: 10px 14px; font-size: 13px; border: 2px solid #000; outline: none; box-shadow: var(--shadow-sm);">
      </div>

      <button type="submit" class="btn btn-warning" style="width: 100%; padding: 12px; font-size: 14px; margin-top: 10px;">
        MASUK KE DASHBOARD &rarr;
      </button>
    </form>

    <div class="login-footer">
      SISTEM PRESENSI MAHASISWA &bull; ESP8266 + RFID &bull; v1.1
    </div>
  </div>
</div>

</body>
</html>
