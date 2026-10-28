<?php
require_once __DIR__ . '/config/config.php';

// Already logged in? go straight to the dashboard.
if (is_logged_in()) {
    redirect('dashboard.php');
}

$error = '';

if (is_post()) {
    verify_csrf();

    $inputUsername = trim($_POST['username'] ?? '');
    $inputPassword = (string) ($_POST['password'] ?? '');

    if ($inputUsername === '' || $inputPassword === '') {
        $error = 'Please enter both username and password.';
    } else {
        $stmt = db()->prepare('SELECT id, username, full_name, role, roles, store_id, photo, password FROM users WHERE username = ? AND status = "active"');
        $stmt->execute([$inputUsername]);
        $user = $stmt->fetch();

        $assignedRoles = $user ? array_values(array_filter(array_map('trim', explode(',', $user['roles'] ?: $user['role'])))) : [];
        if ($user && !in_array('staff', $assignedRoles, true) && password_verify($inputPassword, $user['password'])) {
            session_regenerate_id(true);

            $_SESSION['user'] = [
                'id' => $user['id'],
                'username' => $user['username'],
                'full_name' => $user['full_name'],
                'role' => $user['role'],
                'roles' => $assignedRoles,
                'store_id' => $user['store_id'] !== null ? (int) $user['store_id'] : null,
                'photo' => $user['photo'],
            ];

            log_audit('login', 'user', (int) $user['id']);

            $destination = $_SESSION['redirect_after_login'] ?? 'dashboard.php';
            unset($_SESSION['redirect_after_login']);
            // Guard against an open redirect via a manipulated session value.
            if (!is_string($destination) || str_starts_with($destination, 'http') || !preg_match('/^[a-zA-Z0-9_\-\/.?=&]*$/', $destination)) {
                $destination = 'dashboard.php';
            }
            redirect($destination);
        } else {
            // Same message either way — don't reveal whether the username exists.
            $error = 'Invalid username or password. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Login - <?= e(APP_NAME) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
  <style>
    :root {
      --primary: #195f43;
      --primary-dark: #10452f;
      --panel-green: #124832;
      --accent: #f4bf00;
    }
    body {
      background: #f2f6f2;
      min-height: 100vh; display: flex; align-items: center; justify-content: center;
      font-family: 'Segoe UI', sans-serif;
      padding: 2rem 1rem;
      position: relative; overflow-x: hidden;
    }
    body::before {
      content: ""; position: fixed; inset: 0; pointer-events: none;
      background-image:
        radial-gradient(circle at 18% 28%, rgba(25, 95, 67, 0.11) 0%, transparent 45%),
        radial-gradient(circle at 82% 72%, rgba(244, 191, 0, 0.08) 0%, transparent 42%);
      animation: pulseBg 18s ease-in-out infinite alternate;
    }
    body::after {
      content: ""; position: fixed; inset: -80px; pointer-events: none; opacity: 0.55;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 200 200'%3E%3Cpath fill='%23195f43' fill-opacity='0.035' d='M100 0L120 80L200 100L120 120L100 200L80 120L0 100L80 80Z'/%3E%3C/svg%3E");
      background-size: 64px 64px;
      animation: floatBg 55s linear infinite;
    }
    @keyframes pulseBg { from { opacity: 0.72; transform: scale(1); } to { opacity: 1; transform: scale(1.06); } }
    @keyframes floatBg { from { transform: translate(0, 0) rotate(0deg); } to { transform: translate(110px, 55px) rotate(360deg); } }
    .particles { position: fixed; inset: 0; pointer-events: none; overflow: hidden; z-index: 0; }
    .particle { position: absolute; width: 5px; height: 5px; border-radius: 50%; background: var(--primary); opacity: 0.22; animation: floatParticle 16s ease-in-out infinite; }
    .particle:nth-child(1) { left: 9%; top: 22%; animation-duration: 18s; }
    .particle:nth-child(2) { left: 29%; top: 72%; animation-delay: 2s; animation-duration: 22s; }
    .particle:nth-child(3) { left: 69%; top: 18%; animation-delay: 4s; animation-duration: 20s; }
    .particle:nth-child(4) { left: 88%; top: 76%; animation-delay: 1s; animation-duration: 24s; }
    .particle:nth-child(5) { left: 52%; top: 9%; animation-delay: 3s; animation-duration: 19s; }
    @keyframes floatParticle {
      0%, 100% { transform: translate(0, 0) scale(1); opacity: 0.18; }
      50% { transform: translate(16px, -42px) scale(1.25); opacity: 0.38; }
    }
    .login-shell { width: 100%; max-width: 900px; position: relative; z-index: 1; }
    .login-layout {
      display: flex; min-height: 516px; overflow: hidden; border: 1px solid #dce3dd;
      border-radius: 16px; background: #fff; box-shadow: 0 18px 45px rgba(18, 72, 50, 0.08);
    }
    .logo-panel {
      flex: 0 0 45%; display: flex; flex-direction: column; justify-content: center;
      padding: 48px; background: var(--panel-green); color: #fff;
    }
    .logo-card { width: 260px; max-width: 100%; padding: 12px; margin: 0 auto 46px; border-radius: 17px; background: #fff; }
    .login-logo { display: block; width: 100%; height: auto; border-radius: 10px; }
    .brand-copy { max-width: 280px; }
    .logo-panel .welcome { margin: 0 0 8px; color: var(--accent); font-size: 0.78rem; font-weight: 800; letter-spacing: 0.1em; text-transform: uppercase; }
    .logo-panel h2 { margin: 0 0 7px; color: #fff; font-size: 2rem; font-weight: 700; line-height: 1.15; }
    .logo-panel .tagline { margin: 0; color: rgba(255, 255, 255, 0.92); font-size: 1rem; line-height: 1.55; }
    .login-container {
      flex: 1; display: flex; align-items: center; padding: 54px 48px; background: #fff;
    }
    .login-content { width: 100%; max-width: 400px; margin: 0 auto; }
    .login-header { margin-bottom: 28px; }
    .login-header h1 { margin-bottom: 10px; color: #103e2d; font-size: 1.75rem; font-weight: 700; }
    .login-header p { margin: 0; color: #5d6862; font-size: 0.92rem; }
    .form-label { margin-bottom: 6px; color: #101512; font-size: 0.82rem; font-weight: 600; }
    .form-control { min-height: 43px; border: 1px solid #d8ddd9; border-radius: 6px; padding: 10px 13px; }
    .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 0.2rem rgba(25, 95, 67, 0.16); }
    .btn-primary { background: var(--primary); border-color: var(--primary); border-radius: 6px; padding: 10px 0; font-weight: 600; }
    .btn-primary:hover, .btn-primary:focus { background: var(--primary-dark); border-color: var(--primary-dark); }
    @media (max-width: 767.98px) {
      body { padding: 1rem; }
      .login-layout { flex-direction: column; }
      .logo-panel { flex-basis: auto; padding: 30px; }
      .logo-card { width: 190px; margin-bottom: 26px; }
      .brand-copy { text-align: center; margin: 0 auto; }
      .logo-panel h2 { font-size: 1.65rem; }
      .login-container { padding: 38px 28px; }
    }
  </style>
</head>
<body>
  <div class="particles" aria-hidden="true">
    <div class="particle"></div>
    <div class="particle"></div>
    <div class="particle"></div>
    <div class="particle"></div>
    <div class="particle"></div>
  </div>
  <main class="login-shell">
    <div class="login-layout">
      <div class="logo-panel">
        <div class="logo-card">
          <img src="assets/images/yollow_logo.png" alt="<?= e(BUSINESS_NAME) ?> logo" class="login-logo">
        </div>
        <div class="brand-copy">
          <p class="welcome">Welcome to</p>
          <h2>Vacman Enterprise</h2>
          <p class="tagline">Premium Materials.<br>Modern Living.</p>
        </div>
      </div>
      <div class="login-container">
        <div class="login-content">
          <div class="login-header">
            <h1>Sign in to your account</h1>
            <p>Enter your administrator credentials to continue.</p>
          </div>

          <?php if ($error !== ''): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            <?= e($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
          <?php endif; ?>

          <form method="POST" action="login.php" autocomplete="off">
            <?= csrf_field() ?>
            <div class="mb-3">
              <label for="username" class="form-label">Username</label>
              <input type="text" class="form-control" id="username" name="username" required autofocus value="<?= old($_POST, 'username') ?>">
            </div>

            <div class="mb-4">
              <label for="password" class="form-label">Password</label>
              <input type="password" class="form-control" id="password" name="password" required>
            </div>

            <button type="submit" class="btn btn-primary w-100">
              Log in
            </button>
          </form>
        </div>
      </div>
    </div>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
