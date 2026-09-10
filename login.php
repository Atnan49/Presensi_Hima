<?php
// Login administrator presensi HIMATIF

require_once 'config.php';
// Otomatis cek & migrasi struktur database saat membuka web tanpa perlu buka phpMyAdmin
try {
    getDB();
} catch (\Throwable $e) {}
startSessionSafe();

if (isLoggedIn()) {
    header('Location: dashboard');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lockoutTime = $_SESSION['login_lockout'] ?? 0;
    if ($lockoutTime > time()) {
        $remaining = $lockoutTime - time();
        $error = "Terlalu banyak percobaan login gagal. Silakan tunggu {$remaining} detik.";
    } else {
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
                        // Login Berhasil -> Hindari Session Fixation
                        session_regenerate_id(true);
                        unset($_SESSION['login_attempts'], $_SESSION['login_lockout']);

                        $_SESSION['admin_logged_in'] = true;
                        $_SESSION['admin_user'] = [
                            'id'       => $admin['id'],
                            'username' => $admin['username'],
                            'name'     => $admin['name']
                        ];
                        header('Location: dashboard');
                        exit;
                    } else {
                        // Rate limiting percobaan login
                        $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
                        if ($_SESSION['login_attempts'] >= 5) {
                            $_SESSION['login_lockout'] = time() + 60; // 60 detik cooldown
                            $error = 'Terlalu banyak percobaan login gagal. Silakan tunggu 1 menit sebelum mencoba kembali.';
                        } else {
                            $attemptsLeft = 5 - $_SESSION['login_attempts'];
                            $error = "Username atau password salah. (Sisa percobaan: {$attemptsLeft})";
                        }
                    }
                } catch (Exception $e) {
                    error_log("Login error: " . $e->getMessage());
                    $error = 'Terjadi kesalahan sistem saat memproses login. Silakan hubungi administrator.';
                }
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
  <title>Login Admin - Presensi HIMATIF UMS</title>
  <link rel="icon" type="image/x-icon" href="assets/Image/favicon.ico?v=2">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/Image/favicon-32x32.png?v=2">
  <link rel="icon" type="image/png" sizes="16x16" href="assets/Image/favicon-16x16.png?v=2">
  <link rel="apple-touch-icon" sizes="180x180" href="assets/Image/apple-touch-icon.png?v=2">
  <link rel="manifest" href="site.webmanifest">
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
      text-align: center;
    }
    .login-brand {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 10px;
      margin-bottom: 12px;
    }
    .login-logo-img {
      width: 80px;
      height: 80px;
      object-fit: contain;
      filter: none;
      transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .login-logo-img:hover {
      transform: scale(1.05);
    }
    .login-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: var(--color-yellow);
      color: #000000;
      border: 2px solid #000000;
      padding: 3px 10px;
      font-family: var(--font-mono);
      font-weight: 800;
      font-size: 11px;
      letter-spacing: 0.12em;
      box-shadow: 2px 2px 0px #000000;
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
      <div class="login-brand">
        <img src="assets/Image/logo-himatif-light.png" alt="Logo HIMATIF UMS" class="login-logo-img">
        <div class="login-badge">HIMATIF UMS</div>
      </div>
      <h1 class="login-title">Sistem Presensi</h1>
      <div class="login-subtitle">Masuk untuk mengelola presensi RFID &amp; program kerja</div>
    </div>

    <?php if (!empty($error)): ?>
      <div class="alert-box alert-danger mb-3">
        <div class="alert-content">
          <div class="alert-title" style="color: var(--color-red);">Akses Ditolak</div>
          <div class="alert-desc" style="color: var(--text-main);"><?= htmlspecialchars($error) ?></div>
        </div>
      </div>
    <?php endif; ?>

    <form method="POST" action="login">
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
               style="width: 100%; padding: 10px 14px; font-size: 13px; border: 2px solid #000; box-shadow: var(--shadow-sm);">
      </div>

      <button type="submit" class="btn btn-warning" style="width: 100%; padding: 12px; font-size: 14px; margin-top: 10px;">
        Masuk ke Dashboard
      </button>
    </form>

    <div class="login-footer">
      SISTEM PRESENSI MAHASISWA &bull; ESP8266 + RFID &bull; v1.1
    </div>
  </div>
</div>

</body>
</html>
