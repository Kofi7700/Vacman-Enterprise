<?php
require_once __DIR__ . '/config/config.php';
require_login();

$pdo = db();
$userId = (int) current_user()['id'];

if (is_post() && isset($_POST['update_profile'])) {
    verify_csrf();
    $errors = validate($_POST, ['full_name' => 'required', 'email' => 'email']);

    if (empty($errors)) {
        try {
            $pdo->prepare('UPDATE users SET full_name = ?, email = ?, phone = ? WHERE id = ?')
                ->execute([trim($_POST['full_name']), trim($_POST['email'] ?? '') ?: null, trim($_POST['phone'] ?? '') ?: null, $userId]);
            $_SESSION['user']['full_name'] = trim($_POST['full_name']);
            log_audit('update', 'user', $userId, null, ['full_name' => $_POST['full_name']]);
            flash_set('success', 'Profile updated.');
        } catch (PDOException $e) {
            error_log('Update profile failed: ' . $e->getMessage());
            flash_set('danger', 'Could not update your profile.');
        }
    } else {
        flash_set('danger', implode(' ', $errors));
    }
    redirect('profile.php');
}

// ---- Handle Profile Picture Upload -------------------------------------
if (is_post() && isset($_POST['upload_photo'])) {
    verify_csrf();

    $allowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $maxBytes = 2 * 1024 * 1024; // 2MB
    $file = $_FILES['photo'] ?? null;

    if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
        flash_set('danger', 'Please choose an image to upload.');
        redirect('profile.php');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        flash_set('danger', 'Upload failed. Please try again.');
        redirect('profile.php');
    }
    if ($file['size'] > $maxBytes) {
        flash_set('danger', 'Image must be smaller than 2MB.');
        redirect('profile.php');
    }

    // Verify it's actually an image (not just a renamed file) and read its
    // real MIME type rather than trusting the browser-supplied one.
    $imageInfo = @getimagesize($file['tmp_name']);
    $mime = $imageInfo['mime'] ?? null;
    if (!$imageInfo || !isset($allowedTypes[$mime])) {
        flash_set('danger', 'Please upload a JPG, PNG, or WEBP image.');
        redirect('profile.php');
    }

    $uploadDir = ROOT_PATH . '/assets/uploads/avatars';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $filename = 'user_' . $userId . '_' . time() . '.' . $allowedTypes[$mime];
    $destination = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        error_log('Profile photo move_uploaded_file failed for user ' . $userId);
        flash_set('danger', 'Could not save the uploaded image.');
        redirect('profile.php');
    }

    // Remove the old photo file, if any, so we don't leave orphans behind.
    $stmt = $pdo->prepare('SELECT photo FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $oldPhoto = $stmt->fetchColumn();
    if ($oldPhoto) {
        $oldPath = ROOT_PATH . '/' . $oldPhoto;
        if (str_starts_with(realpath($oldPath) ?: '', realpath($uploadDir)) && is_file($oldPath)) {
            @unlink($oldPath);
        }
    }

    $webPath = 'assets/uploads/avatars/' . $filename;
    $pdo->prepare('UPDATE users SET photo = ? WHERE id = ?')->execute([$webPath, $userId]);
    $_SESSION['user']['photo'] = $webPath;
    log_audit('update', 'user', $userId, null, ['photo' => $webPath]);
    flash_set('success', 'Profile picture updated.');
    redirect('profile.php');
}

if (is_post() && isset($_POST['change_password'])) {
    verify_csrf();
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($current, $row['password'])) {
        flash_set('danger', 'Your current password is incorrect.');
    } elseif (strlen($new) < 8) {
        flash_set('danger', 'New password must be at least 8 characters.');
    } elseif ($new !== $confirm) {
        flash_set('danger', 'New password and confirmation do not match.');
    } else {
        $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
        log_audit('change_password', 'user', $userId);
        flash_set('success', 'Password changed successfully.');
    }
    redirect('profile.php');
}

$stmt = $pdo->prepare('SELECT username, full_name, email, phone, photo, role, created_at FROM users WHERE id = ?');
$stmt->execute([$userId]);
$me = $stmt->fetch();

$pageTitle = 'My Profile';
require __DIR__ . '/includes/header.php';
?>

<div class="row">
  <div class="col-lg-6">
    <div class="card shadow mb-4">
      <div class="card-header py-3"><h6 class="m-0 fw-bold text-primary">Account Details</h6></div>
      <div class="card-body">
        <div class="d-flex align-items-center mb-4">
          <?php if (!empty($me['photo'])): ?>
          <img src="<?= e($me['photo']) ?>" alt="Profile picture" class="rounded-circle me-3" style="width: 88px; height: 88px; object-fit: cover;">
          <?php else: ?>
          <img src="https://ui-avatars.com/api/?name=<?= urlencode($me['full_name'] ?: 'User') ?>&background=e74a3b&color=fff&size=88" alt="Profile picture" class="rounded-circle me-3" style="width: 88px; height: 88px;">
          <?php endif; ?>
          <form method="POST" action="profile.php" enctype="multipart/form-data" class="d-flex align-items-center flex-wrap gap-2">
            <?= csrf_field() ?>
            <input type="hidden" name="upload_photo" value="1">
            <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="form-control form-control-sm" style="width: auto;" required>
            <button type="submit" class="btn btn-sm btn-outline-primary">Upload</button>
          </form>
        </div>
        <form method="POST" action="profile.php">
          <?= csrf_field() ?>
          <input type="hidden" name="update_profile" value="1">
          <div class="mb-3">
            <label class="form-label">Username</label>
            <input type="text" class="form-control" value="<?= e($me['username']) ?>" disabled>
          </div>
          <div class="mb-3">
            <label class="form-label">Role</label>
            <input type="text" class="form-control" value="<?= e(str_replace('_', ' ', ucfirst($me['role']))) ?>" disabled>
          </div>
          <div class="mb-3">
            <label class="form-label">Full Name</label>
            <input type="text" class="form-control" name="full_name" value="<?= e($me['full_name']) ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Email</label>
            <input type="email" class="form-control" name="email" value="<?= e($me['email']) ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">Phone</label>
            <input type="text" class="form-control" name="phone" value="<?= e($me['phone']) ?>">
          </div>
          <button type="submit" class="btn btn-primary">Save Changes</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card shadow mb-4">
      <div class="card-header py-3"><h6 class="m-0 fw-bold text-primary">Change Password</h6></div>
      <div class="card-body">
        <form method="POST" action="profile.php">
          <?= csrf_field() ?>
          <input type="hidden" name="change_password" value="1">
          <div class="mb-3">
            <label class="form-label">Current Password</label>
            <input type="password" class="form-control" name="current_password" required>
          </div>
          <div class="mb-3">
            <label class="form-label">New Password</label>
            <input type="password" class="form-control" name="new_password" minlength="8" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Confirm New Password</label>
            <input type="password" class="form-control" name="confirm_password" minlength="8" required>
          </div>
          <button type="submit" class="btn btn-primary">Change Password</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
