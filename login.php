<?php
declare(strict_types=1);

/**
 * login.php
 * Leafty - Authentication Portal
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';
$redirect = 'index.php';
if (!empty($_GET['redirect'])) {
    $rawRedirect = urldecode((string)$_GET['redirect']);
    // Safety: ensure only relative internal redirection
    if (strpos($rawRedirect, '//') === false && strpos($rawRedirect, ':') === false && str_starts_with($rawRedirect, '/')) {
        $redirect = ltrim($rawRedirect, '/');
    }
}

// Handle POST authentication
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Both username and password are required.';
    } else {
        $result = attemptLogin($username, $password);
        if ($result['ok']) {
            if (wantsJson()) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'redirect' => $redirect]);
                exit;
            }
            header('Location: ' . $redirect);
            exit;
        } else {
            $error = $result['error'] ?? 'Invalid username or password.';
        }
    }

    if (wantsJson()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $error]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign In – Leafty Agriculture IS</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(135deg, #122B20 0%, #1B4332 60%, #2D6A4F 100%);
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      padding: 1.25rem;
    }

    .login-card {
      background: #FFFFFF;
      border-radius: 16px;
      box-shadow: 0 20px 50px rgba(18, 43, 32, 0.28);
      width: 100%;
      max-width: 420px;
      padding: 2.5rem 2.25rem 2rem;
      border: 1px solid #DCE4DF;
    }

    .brand-header {
      text-align: center;
      margin-bottom: 2rem;
    }
    .brand-logo {
      width: 58px;
      height: 58px;
      background: #E9F5EE;
      border-radius: 50%;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 0.75rem;
      color: #1B4332;
    }
    .brand-logo svg { width: 34px; height: 34px; fill: currentColor; }
    .brand-header h1 {
      font-size: 1.55rem;
      font-weight: 700;
      color: #18221D;
      letter-spacing: -0.02em;
    }
    .brand-header p {
      font-size: 0.85rem;
      color: #52635A;
      margin-top: 0.35rem;
    }

    .alert {
      padding: 0.8rem 1rem;
      border-radius: 8px;
      font-size: 0.86rem;
      margin-bottom: 1.25rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    .alert-danger {
      background: #FEE2E2;
      color: #DC2626;
      border: 1px solid #FECACA;
    }

    .form-group {
      margin-bottom: 1.2rem;
    }
    label {
      display: block;
      font-size: 0.82rem;
      font-weight: 600;
      color: #18221D;
      margin-bottom: 0.4rem;
    }
    .input-wrap {
      position: relative;
    }
    input[type="text"],
    input[type="password"] {
      width: 100%;
      padding: 0.75rem 1rem;
      border: 1.5px solid #DCE4DF;
      border-radius: 8px;
      font-size: 0.95rem;
      color: #18221D;
      background: #F8F9F6;
      outline: none;
      transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
    }
    input:focus {
      background: #FFFFFF;
      border-color: #1B4332;
      box-shadow: 0 0 0 3px rgba(27, 67, 50, 0.2);
    }
    .input-wrap input[type="password"],
    .input-wrap input[type="text"].has-toggle {
      padding-right: 2.75rem;
    }
    .pw-toggle {
      position: absolute;
      right: 0.85rem;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      cursor: pointer;
      color: #7E8F85;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .pw-toggle:hover { color: #18221D; }

    .btn-submit {
      width: 100%;
      padding: 0.85rem;
      background: #1B4332;
      color: #FFFFFF;
      font-size: 1rem;
      font-weight: 600;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      transition: background 0.2s, transform 0.1s;
      margin-top: 0.5rem;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
    }
    .btn-submit:hover { background: #2D6A4F; }
    .btn-submit:active { transform: scale(0.99); }
    .btn-submit:disabled { opacity: 0.6; cursor: not-allowed; }

    .card-footer-note {
      text-align: center;
      margin-top: 1.75rem;
      font-size: 0.78rem;
      color: #7E8F85;
    }
  </style>
</head>
<body>

  <div class="login-card">
    <div class="brand-header">
      <div class="brand-logo">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/>
          <path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"/>
        </svg>
      </div>
      <h1>Leafty</h1>
      <p>Agriculture Information System Portal</p>
    </div>

    <?php if ($error !== ''): ?>
    <div class="alert alert-danger" role="alert">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <span><?= e($error) ?></span>
    </div>
    <?php endif; ?>

    <form method="POST" action="login.php?redirect=<?= urlencode($redirect) ?>" autocomplete="off">
      <div class="form-group">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" placeholder="e.g. admin or staff"
               value="<?= e($_POST['username'] ?? '') ?>" required autofocus autocomplete="username">
      </div>

      <div class="form-group">
        <label for="password">Password</label>
        <div class="input-wrap">
          <input type="password" id="password" name="password" placeholder="Enter your password" required autocomplete="current-password">
          <button type="button" class="pw-toggle" id="pwToggle" aria-label="Toggle password visibility">
            <svg id="eyeIcon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
            </svg>
          </button>
        </div>
      </div>

      <button type="submit" class="btn-submit" id="submitBtn">Sign In</button>
    </form>

    <div class="card-footer-note">
      Protected with Role-Based Access & Soft-Delete Session Guards
    </div>
  </div>

  <script>
    (function () {
      const toggle = document.getElementById('pwToggle');
      const input = document.getElementById('password');
      const eyeIcon = document.getElementById('eyeIcon');

      const eyeOpen = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
      const eyeSlash = '<line x1="1" y1="1" x2="23" y2="23"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07A3 3 0 0 1 9.07 9.07M6.53 6.53A10.72 10.72 0 0 0 1 12s4 8 11 8c2.17 0 4.17-.67 5.86-1.81"/>';

      if (toggle && input) {
        toggle.addEventListener('click', () => {
          const isPw = input.type === 'password';
          input.type = isPw ? 'text' : 'password';
          if (isPw) input.classList.add('has-toggle'); else input.classList.remove('has-toggle');
          eyeIcon.innerHTML = isPw ? eyeSlash : eyeOpen;
        });
      }
    })();
  </script>
</body>
</html>
