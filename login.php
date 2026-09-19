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
        $stmt = db()->prepare('SELECT id, username, full_name, role, password FROM users WHERE username = ?');
        $stmt->execute([$inputUsername]);
        $user = $stmt->fetch();

        if ($user && password_verify($inputPassword, $user['password'])) {
            session_regenerate_id(true);

            $_SESSION['user'] = [
                'id' => $user['id'],
                'username' => $user['username'],
                'full_name' => $user['full_name'],
                'role' => $user['role'],
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
      --primary: #e74a3b;
      --primary-dark: #c9302c;
      --bg-gradient-start: #ffffff;
      --bg-gradient-end: #eef2ff;
      --bg-radial-1: rgba(231, 74, 59, 0.08);
      --bg-radial-2: rgba(148, 163, 184, 0.08);
    }
    body {
      background: linear-gradient(135deg, var(--bg-gradient-start) 0%, var(--bg-gradient-end) 100%);
      min-height: 100vh; display: flex; align-items: center;
      font-family: 'Segoe UI', sans-serif;
      position: relative;
      overflow-x: hidden;
    }
    /* Animated background: soft moving glows + drifting texture */
    body::before {
      content: ""; position: fixed; inset: 0;
      background-image:
        radial-gradient(circle at 20% 30%, var(--bg-radial-1) 0%, transparent 50%),
        radial-gradient(circle at 80% 70%, var(--bg-radial-2) 0%, transparent 50%);
      pointer-events: none; z-index: 0;
      animation: pulseBg 20s ease-in-out infinite alternate;
    }
    body::after {
      content: ""; position: fixed; inset: 0;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 200 200'%3E%3Cpath fill='%23e74a3b' fill-opacity='0.03' d='M100 0L120 80L200 100L120 120L100 200L80 120L0 100L80 80Z'/%3E%3C/svg%3E");
      background-size: 60px 60px; background-repeat: repeat;
      pointer-events: none; z-index: 0; opacity: 0.6;
      animation: floatBg 60s linear infinite;
    }
    @keyframes pulseBg { 0% { opacity: 0.8; } 100% { opacity: 1; } }
    @keyframes floatBg { 0% { transform: translate(0,0) rotate(0deg); } 100% { transform: translate(100px,50px) rotate(360deg); } }

    /* Floating particles */
    .particles { position: fixed; inset: 0; pointer-events: none; z-index: 0; overflow: hidden; }
    .particle { position: absolute; width: 4px; height: 4px; background: var(--primary); border-radius: 50%; opacity: 0.3; animation: floatParticle 15s infinite ease-in-out; }
    .particle:nth-child(1) { left: 10%; top: 20%; animation-delay: 0s; animation-duration: 18s; }
    .particle:nth-child(2) { left: 30%; top: 60%; animation-delay: 2s; animation-duration: 22s; }
    .particle:nth-child(3) { left: 70%; top: 30%; animation-delay: 4s; animation-duration: 20s; }
    .particle:nth-child(4) { left: 85%; top: 75%; animation-delay: 1s; animation-duration: 25s; }
    .particle:nth-child(5) { left: 50%; top: 10%; animation-delay: 3s; animation-duration: 19s; }
    @keyframes floatParticle {
      0%, 100% { transform: translateY(0) translateX(0) scale(1); opacity: 0.3; }
      25% { transform: translateY(-20px) translateX(10px) scale(1.1); opacity: 0.5; }
      50% { transform: translateY(-40px) translateX(-5px) scale(0.9); opacity: 0.4; }
      75% { transform: translateY(-20px) translateX(15px) scale(1.05); opacity: 0.45; }
    }

    .login-container {
      max-width: 420px; width: 100%; padding: 40px; border-radius: 15px;
      background-color: rgba(255, 255, 255, 0.95);
      box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
      position: relative; z-index: 1;
    }
    .login-header { text-align: center; margin-bottom: 2rem; }
    .login-header h1 { color: var(--primary); font-weight: 700; margin-bottom: 0.5rem; }
    .login-header p { color: #6c757d; font-size: 0.9rem; }
    .form-control { border-radius: 8px; padding: 12px 15px; border: 1px solid #ddd; }
    .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 0.2rem rgba(231, 74, 59, 0.25); }
    .btn-primary { background: linear-gradient(to right, var(--primary), var(--primary-dark)); border: none; border-radius: 8px; padding: 12px 0; font-weight: 600; }
    .company-info { margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; text-align: center; }
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
  <div class="container">
    <div class="row justify-content-center">
      <div class="col-md-8 col-lg-6">
        <div class="login-container">
          <div class="login-header">
            <h1><i class="bi bi-building"></i> <?= e(strtoupper(APP_NAME)) ?></h1>
            <p><?= e(BUSINESS_NAME) ?> &middot; Sogakope, Ghana</p>
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
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-person"></i></span>
                <input type="text" class="form-control" id="username" name="username" placeholder="Enter your username"
                  required autofocus value="<?= old($_POST, 'username') ?>">
              </div>
            </div>

            <div class="mb-3">
              <label for="password" class="form-label">Password</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                <input type="password" class="form-control" id="password" name="password" placeholder="Enter your password" required>
                <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
            </div>

            <button type="submit" class="btn btn-primary w-100">
              <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
            </button>
          </form>

          <div class="company-info">
            <h6><i class="bi bi-info-circle"></i> System Information</h6>
            <p class="small mb-0"><?= e(BUSINESS_NAME) ?></p>
            <p class="small mb-0">Sogakope, Ghana &bull; <?= e(CURRENCY_CODE) ?> <?= e(CURRENCY_SYMBOL) ?> Currency</p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    document.getElementById('togglePassword')?.addEventListener('click', function () {
      const passwordInput = document.getElementById('password');
      const icon = this.querySelector('i');
      if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        icon.classList.replace('bi-eye', 'bi-eye-slash');
      } else {
        passwordInput.type = 'password';
        icon.classList.replace('bi-eye-slash', 'bi-eye');
      }
    });
  </script>
</body>
</html>
