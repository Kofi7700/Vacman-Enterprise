<?php
require_once __DIR__ . '/config/config.php';
require_login();

$pdo = db();
$userId = (int) current_user()['id'];

// ---- Update personal details -----------------------------------------
if (is_post() && isset($_POST['update_profile'])) {
    verify_csrf();
    $errors = validate($_POST, ['full_name' => 'required', 'email' => 'email']);
    if (trim($_POST['full_name'] ?? '') === '') $errors[] = 'Full name is required.';

    if (!$errors) {
        try {
            $fullName = trim($_POST['full_name']);
            $pdo->prepare('UPDATE users SET full_name = ?, email = ?, phone = ? WHERE id = ?')
                ->execute([
                    $fullName,
                    trim($_POST['email'] ?? '') ?: null,
                    trim($_POST['phone'] ?? '') ?: null,
                    $userId,
                ]);
            $_SESSION['user']['full_name'] = $fullName;
            log_audit('update', 'user', $userId, null, ['full_name' => $fullName]);
            flash_set('success', 'Profile updated.');
        } catch (PDOException $e) {
            error_log('Update profile failed: ' . $e->getMessage());
            flash_set('danger', 'Could not update your profile.');
        }
    } else {
        flash_set('danger', implode(' ', array_unique($errors)));
    }
    redirect('profile.php');
}

// ---- Replace profile picture ----------------------------------------
if (is_post() && isset($_POST['upload_photo'])) {
    verify_csrf();
    $allowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $maxBytes = 2 * 1024 * 1024;
    $maxDimension = 4096;
    $file = $_FILES['photo'] ?? null;

    if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
        flash_set('danger', 'Please choose an image to upload.');
        redirect('profile.php');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        flash_set('danger', 'Upload failed. Please try again.');
        redirect('profile.php');
    }
    if ((int) $file['size'] < 1 || (int) $file['size'] > $maxBytes) {
        flash_set('danger', 'Image must be smaller than 2MB.');
        redirect('profile.php');
    }

    $imageInfo = @getimagesize($file['tmp_name']);
    $mime = $imageInfo['mime'] ?? null;
    if (!$imageInfo || !isset($allowedTypes[$mime])) {
        flash_set('danger', 'Please upload a JPG, PNG, or WEBP image.');
        redirect('profile.php');
    }
    if ((int) $imageInfo[0] > $maxDimension || (int) $imageInfo[1] > $maxDimension) {
        flash_set('danger', 'Image dimensions must not exceed 4096 × 4096 pixels.');
        redirect('profile.php');
    }

    $uploadDir = ROOT_PATH . '/assets/uploads/avatars';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        error_log('Could not create avatar upload directory for user ' . $userId);
        flash_set('danger', 'Could not prepare the profile image folder.');
        redirect('profile.php');
    }

    try {
        $filename = 'user_' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $allowedTypes[$mime];
    } catch (Exception $e) {
        error_log('Avatar filename generation failed: ' . $e->getMessage());
        flash_set('danger', 'Could not prepare the uploaded image.');
        redirect('profile.php');
    }
    $destination = $uploadDir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        error_log('Profile photo move_uploaded_file failed for user ' . $userId);
        flash_set('danger', 'Could not save the uploaded image.');
        redirect('profile.php');
    }

    $stmt = $pdo->prepare('SELECT photo FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $oldPhoto = $stmt->fetchColumn();
    $webPath = 'assets/uploads/avatars/' . $filename;

    try {
        $pdo->prepare('UPDATE users SET photo = ? WHERE id = ?')->execute([$webPath, $userId]);
    } catch (PDOException $e) {
        @unlink($destination);
        error_log('Profile photo database update failed: ' . $e->getMessage());
        flash_set('danger', 'Could not update your profile picture.');
        redirect('profile.php');
    }

    if ($oldPhoto && $oldPhoto !== $webPath) {
        $oldPath = ROOT_PATH . '/' . ltrim($oldPhoto, '/\\');
        $oldRealPath = realpath($oldPath);
        $uploadRealPath = realpath($uploadDir);
        if ($oldRealPath && $uploadRealPath
            && str_starts_with($oldRealPath, $uploadRealPath . DIRECTORY_SEPARATOR)
            && is_file($oldRealPath)) {
            @unlink($oldRealPath);
        }
    }

    $_SESSION['user']['photo'] = $webPath;
    log_audit('update', 'user', $userId, null, ['photo' => $webPath]);
    flash_set('success', 'Profile picture updated.');
    redirect('profile.php');
}

// ---- Change password -------------------------------------------------
if (is_post() && isset($_POST['change_password'])) {
    verify_csrf();
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($currentPassword, $row['password'])) {
        flash_set('danger', 'Your current password is incorrect.');
    } elseif (strlen($newPassword) < 8) {
        flash_set('danger', 'New password must be at least 8 characters.');
    } elseif ($newPassword !== $confirmPassword) {
        flash_set('danger', 'New password and confirmation do not match.');
    } else {
        try {
            $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')
                ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
            log_audit('change_password', 'user', $userId);
            flash_set('success', 'Password changed successfully.');
        } catch (PDOException $e) {
            error_log('Change password failed: ' . $e->getMessage());
            flash_set('danger', 'Could not change your password.');
        }
    }
    redirect('profile.php');
}

$stmt = $pdo->prepare('SELECT username, full_name, email, phone, photo, role, roles, status, created_at FROM users WHERE id = ?');
$stmt->execute([$userId]);
$me = $stmt->fetch();
if (!$me) {
    session_destroy();
    redirect('login.php');
}

$completionFields = [$me['full_name'], $me['email'], $me['phone'], $me['photo']];
$completedFields = count(array_filter($completionFields, static fn($value) => trim((string) $value) !== ''));
$profileCompletion = (int) round(($completedFields / count($completionFields)) * 100);
$roleLabel = implode(' · ', array_map(
    static fn (string $role): string => ucwords(str_replace('_', ' ', $role)),
    explode(',', $me['roles'] ?: $me['role'])
));
$pageTitle = 'My Profile';
require __DIR__ . '/includes/header.php';
?>

<section class="profile-hero mb-4">
  <div>
    <span class="profile-overline"><i class="bi bi-person-circle me-2"></i>Personal workspace</span>
    <h2>Keep your account current and secure.</h2>
    <p>Update your contact details, refresh your profile picture, and manage your sign-in password.</p>
  </div>
  <div class="profile-member-since"><i class="bi bi-calendar3"></i><span>Member since<strong><?= e(date('F Y', strtotime($me['created_at']))) ?></strong></span></div>
</section>

<div class="row g-4 align-items-start">
  <div class="col-xl-4">
    <aside class="profile-summary-card">
      <div class="profile-summary-cover"></div>
      <div class="profile-avatar-wrap">
        <?php if ($me['photo']): ?>
        <img src="<?= e($me['photo']) ?>" alt="<?= e($me['full_name']) ?> profile picture" class="profile-avatar" id="profileAvatarPreview">
        <?php else: ?>
        <span class="profile-avatar profile-avatar-fallback" id="profileAvatarFallback"><?= e(strtoupper(substr($me['full_name'] ?: 'U', 0, 1))) ?></span>
        <img src="" alt="Profile picture preview" class="profile-avatar d-none" id="profileAvatarPreview">
        <?php endif; ?>
        <span class="profile-online-dot" title="Active account"></span>
      </div>
      <div class="profile-summary-body">
        <h3><?= e($me['full_name']) ?></h3>
        <p>@<?= e($me['username']) ?></p>
        <span class="profile-role"><i class="bi bi-shield-check"></i><?= e($roleLabel) ?></span>

        <form method="POST" action="profile.php" enctype="multipart/form-data" class="profile-photo-form" id="profilePhotoForm">
          <?= csrf_field() ?><input type="hidden" name="upload_photo" value="1">
          <input type="file" name="photo" id="profilePhotoInput" accept="image/jpeg,image/png,image/webp" class="visually-hidden" required>
          <label for="profilePhotoInput" class="profile-photo-picker"><i class="bi bi-camera"></i><span>Choose new photo</span></label>
          <small id="profilePhotoName">JPG, PNG or WEBP · Maximum 2MB</small>
          <button type="submit" class="btn profile-upload-button" id="profileUploadButton" disabled><i class="bi bi-cloud-arrow-up me-2"></i>Upload photo</button>
        </form>

        <div class="profile-completion">
          <div><span>Profile completion</span><strong><?= $profileCompletion ?>%</strong></div>
          <div class="profile-progress" role="progressbar" aria-label="Profile completion" aria-valuenow="<?= $profileCompletion ?>" aria-valuemin="0" aria-valuemax="100"><span style="width: <?= $profileCompletion ?>%"></span></div>
          <small><?= $profileCompletion === 100 ? 'Your profile is complete.' : 'Add your contact details and photo to complete it.' ?></small>
        </div>
      </div>
      <footer class="profile-summary-footer"><span><i class="bi bi-check-circle"></i><?= e(ucfirst($me['status'])) ?> account</span><span><i class="bi bi-lock"></i>Role protected</span></footer>
    </aside>
  </div>

  <div class="col-xl-8">
    <section class="profile-panel mb-4">
      <header class="profile-panel-header"><span><i class="bi bi-person-vcard"></i></span><div><strong>Personal information</strong><small>Details used across your workspace</small></div></header>
      <form method="POST" action="profile.php" class="profile-form">
        <?= csrf_field() ?><input type="hidden" name="update_profile" value="1">
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label" for="profileUsername">Username</label><div class="profile-readonly-field"><i class="bi bi-at"></i><input type="text" class="form-control" id="profileUsername" value="<?= e($me['username']) ?>" disabled></div><small class="profile-field-help">Only an administrator can change your username.</small></div>
          <div class="col-md-6"><label class="form-label" for="profileRole">Access role</label><div class="profile-readonly-field"><i class="bi bi-shield-lock"></i><input type="text" class="form-control" id="profileRole" value="<?= e($roleLabel) ?>" disabled></div><small class="profile-field-help">Your role controls available workspace features.</small></div>
          <div class="col-12"><label class="form-label" for="profileFullName">Full name</label><input type="text" class="form-control" name="full_name" id="profileFullName" value="<?= e($me['full_name']) ?>" maxlength="100" autocomplete="name" required></div>
          <div class="col-md-7"><label class="form-label" for="profileEmail">Email address <span class="text-muted fw-normal">(optional)</span></label><input type="email" class="form-control" name="email" id="profileEmail" value="<?= e($me['email'] ?? '') ?>" maxlength="100" autocomplete="email" placeholder="you@example.com"></div>
          <div class="col-md-5"><label class="form-label" for="profilePhone">Phone number <span class="text-muted fw-normal">(optional)</span></label><input type="tel" class="form-control" name="phone" id="profilePhone" value="<?= e($me['phone'] ?? '') ?>" maxlength="20" autocomplete="tel" placeholder="+233 ..."></div>
        </div>
        <div class="profile-form-footer"><span><i class="bi bi-info-circle"></i>Your name appears on transactions and activity records.</span><button type="submit" class="btn profile-save-button"><i class="bi bi-check2 me-2"></i>Save profile</button></div>
      </form>
    </section>

    <section class="profile-panel">
      <header class="profile-panel-header security"><span><i class="bi bi-key"></i></span><div><strong>Password and security</strong><small>Use a strong password that you do not reuse elsewhere</small></div></header>
      <form method="POST" action="profile.php" class="profile-form" id="profilePasswordForm">
        <?= csrf_field() ?><input type="hidden" name="change_password" value="1">
        <div class="row g-3">
          <div class="col-12"><label class="form-label" for="currentPassword">Current password</label><div class="profile-password-field"><input type="password" class="form-control" name="current_password" id="currentPassword" autocomplete="current-password" required><button type="button" class="profile-password-toggle" data-password-target="currentPassword" aria-label="Show current password"><i class="bi bi-eye"></i></button></div></div>
          <div class="col-md-6"><label class="form-label" for="newPassword">New password</label><div class="profile-password-field"><input type="password" class="form-control" name="new_password" id="newPassword" minlength="8" autocomplete="new-password" required><button type="button" class="profile-password-toggle" data-password-target="newPassword" aria-label="Show new password"><i class="bi bi-eye"></i></button></div><div class="profile-password-strength" id="profilePasswordStrength"><span></span><span></span><span></span><span></span></div><small class="profile-field-help" id="profilePasswordHint">Use at least 8 characters.</small></div>
          <div class="col-md-6"><label class="form-label" for="confirmPassword">Confirm new password</label><div class="profile-password-field"><input type="password" class="form-control" name="confirm_password" id="confirmPassword" minlength="8" autocomplete="new-password" required><button type="button" class="profile-password-toggle" data-password-target="confirmPassword" aria-label="Show password confirmation"><i class="bi bi-eye"></i></button></div><small class="profile-field-help" id="profilePasswordMatch">Enter the new password again.</small></div>
        </div>
        <div class="profile-security-note"><i class="bi bi-shield-check"></i><div><strong>Your password is stored securely</strong><small>Changing it does not alter your username, role, or account permissions.</small></div></div>
        <div class="profile-form-footer password"><span><i class="bi bi-exclamation-circle"></i>You will use the new password on your next sign-in.</span><button type="submit" class="btn profile-save-button"><i class="bi bi-lock me-2"></i>Change password</button></div>
      </form>
    </section>
  </div>
</div>

<?php
$extraScripts = '<script>
(function () {
  document.querySelectorAll(".profile-password-toggle").forEach(function (button) {
    button.addEventListener("click", function () {
      const input = document.getElementById(button.dataset.passwordTarget);
      if (!input) return;
      const showing = input.type === "text";
      input.type = showing ? "password" : "text";
      button.innerHTML = showing ? "<i class=\"bi bi-eye\"></i>" : "<i class=\"bi bi-eye-slash\"></i>";
      button.setAttribute("aria-label", showing ? "Show password" : "Hide password");
    });
  });

  const photoInput = document.getElementById("profilePhotoInput");
  const photoPreview = document.getElementById("profileAvatarPreview");
  const photoFallback = document.getElementById("profileAvatarFallback");
  const photoName = document.getElementById("profilePhotoName");
  const uploadButton = document.getElementById("profileUploadButton");
  let previewUrl = null;
  if (photoInput) {
    photoInput.addEventListener("change", function () {
      const file = photoInput.files && photoInput.files[0];
      uploadButton.disabled = !file;
      if (!file) return;
      if (previewUrl) URL.revokeObjectURL(previewUrl);
      previewUrl = URL.createObjectURL(file);
      photoPreview.src = previewUrl;
      photoPreview.classList.remove("d-none");
      if (photoFallback) photoFallback.classList.add("d-none");
      photoName.textContent = file.name + " · " + Math.max(1, Math.round(file.size / 1024)) + " KB";
    });
  }

  const newPassword = document.getElementById("newPassword");
  const confirmPassword = document.getElementById("confirmPassword");
  const strength = document.getElementById("profilePasswordStrength");
  const hint = document.getElementById("profilePasswordHint");
  const match = document.getElementById("profilePasswordMatch");
  function updatePasswordFeedback() {
    const value = newPassword.value;
    let score = 0;
    if (value.length >= 8) score++;
    if (/[a-z]/.test(value) && /[A-Z]/.test(value)) score++;
    if (/\d/.test(value)) score++;
    if (/[^A-Za-z0-9]/.test(value)) score++;
    strength.dataset.score = score;
    hint.textContent = !value ? "Use at least 8 characters." : ["Very weak", "Needs improvement", "Fair", "Strong", "Very strong"][score];
    if (confirmPassword.value) {
      const matches = value === confirmPassword.value;
      match.textContent = matches ? "Passwords match." : "Passwords do not match.";
      match.classList.toggle("matches", matches);
      match.classList.toggle("mismatch", !matches);
    } else {
      match.textContent = "Enter the new password again.";
      match.classList.remove("matches", "mismatch");
    }
  }
  if (newPassword && confirmPassword) {
    newPassword.addEventListener("input", updatePasswordFeedback);
    confirmPassword.addEventListener("input", updatePasswordFeedback);
  }
})();
</script>';
require __DIR__ . '/includes/footer.php';
?>
