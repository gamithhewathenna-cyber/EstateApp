<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

Auth::start();

function getClientIP() {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) return trim(explode(',',$_SERVER[$k])[0]);
    }
    return '0.0.0.0';
}

function getUserAgent() {
    return substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
}

// ── CHECK REMEMBER ME COOKIE ──────────────────────
if (empty($_SESSION['user_id']) && !empty($_COOKIE['remember_token'])) {
    $cookieToken = $_COOKIE['remember_token'];
    $row = DB::fetchOne("SELECT rt.*, u.name, u.role, u.is_active
        FROM remember_tokens rt
        JOIN users u ON rt.user_id = u.id
        WHERE rt.token = ? AND rt.expires_at > NOW()", [$cookieToken]);

    if ($row && $row['is_active']) {
        // Verify IP AND user agent must match
        $currentIP = getClientIP();
        $currentUA = getUserAgent();

        if ($row['ip_address'] === $currentIP && $row['user_agent'] === $currentUA) {
            // Valid — restore session
            $_SESSION['user_id']    = $row['user_id'];
            $_SESSION['user_name']  = $row['name'];
            $_SESSION['user_role']  = $row['role'];
            $_SESSION['login_time'] = time();
            // Rotate token for security
            $newToken = bin2hex(random_bytes(48));
            DB::execute("UPDATE remember_tokens SET token=?, expires_at=DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id=?",
                [$newToken, $row['id']]);
            setcookie('remember_token', $newToken, time() + (30*24*3600), '/', '', false, true);
            header('Location: ' . BASE_URL . '/index.php');
            exit;
        } else {
            // IP or device changed — delete token and clear cookie
            DB::execute("DELETE FROM remember_tokens WHERE token=?", [$cookieToken]);
            setcookie('remember_token', '', time()-3600, '/');
        }
    } else {
        // Expired or invalid — clear cookie
        setcookie('remember_token', '', time()-3600, '/');
    }
}

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$error   = '';
$success = '';
$view    = $_GET['view'] ?? 'login';

// ── HANDLE LOGIN ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $username    = trim($_POST['username'] ?? '');
    $password    = trim($_POST['password'] ?? '');
    $rememberMe  = !empty($_POST['remember_me']);

    if ($username && $password) {
        // Try username first, then email
        $user = DB::fetchOne("SELECT * FROM users WHERE username=? AND is_active=1", [$username]);
        if (!$user) {
            $user = DB::fetchOne("SELECT * FROM users WHERE email=? AND is_active=1", [$username]);
        }

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id']    = $user['id'];
            $_SESSION['user_name']  = $user['name'];
            $_SESSION['user_role']  = $user['role'];
            $_SESSION['login_time'] = time();
            DB::execute("UPDATE users SET last_login=NOW() WHERE id=?", [$user['id']]);

            // Handle Remember Me
            if ($rememberMe) {
                $token     = bin2hex(random_bytes(48));
                $ip        = getClientIP();
                $ua        = getUserAgent();
                $expires   = date('Y-m-d H:i:s', strtotime('+30 days'));

                // Delete old tokens for this user+device
                DB::execute("DELETE FROM remember_tokens WHERE user_id=? AND ip_address=? AND user_agent=?",
                    [$user['id'], $ip, $ua]);

                // Insert new token
                DB::execute("INSERT INTO remember_tokens (user_id, token, ip_address, user_agent, expires_at) VALUES (?,?,?,?,?)",
                    [$user['id'], $token, $ip, $ua, $expires]);

                // Set cookie for 30 days, HttpOnly for security
                setcookie('remember_token', $token, time() + (30*24*3600), '/', '', false, true);
            }

            header('Location: ' . BASE_URL . '/index.php');
            exit;
        } else {
            $error = 'Invalid username/email or password.';
        }
    } else {
        $error = 'Please enter your username (or email) and password.';
    }
}

// ── HANDLE FORGOT PASSWORD ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'forgot') {
    $username = trim($_POST['username'] ?? '');
    if (!$username) {
        $error = 'Please enter your username.';
        $view  = 'forgot';
    } else {
        $user = DB::fetchOne("SELECT * FROM users WHERE (username=? OR email=?) AND is_active=1",
            [$username, $username]);
        if ($user) {
            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
            DB::execute("UPDATE users SET password=CONCAT('RESET::',?,'::',?) WHERE id=?",
                [$token, $expires, $user['id']]);
            $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host      = $_SERVER['HTTP_HOST'];
            $resetLink = $protocol . '://' . $host . BASE_URL . '/login.php?view=reset&token=' . $token . '&uid=' . $user['id'];
            $_SESSION['reset_link'] = $resetLink;
            $_SESSION['reset_user'] = sanitize($user['name']);
        } else {
            $_SESSION['reset_link'] = '';
            $_SESSION['reset_user'] = '';
        }
        $view = 'forgot_sent';
    }
}

// ── HANDLE RESET PASSWORD ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset') {
    $uid   = (int)($_POST['uid'] ?? 0);
    $token = trim($_POST['token'] ?? '');
    $pass1 = $_POST['password']  ?? '';
    $pass2 = $_POST['password2'] ?? '';
    $view  = 'reset';

    if (!$pass1 || strlen($pass1) < 6)  { $error = 'Password must be at least 6 characters.'; }
    elseif ($pass1 !== $pass2)          { $error = 'Passwords do not match.'; }
    else {
        $user = DB::fetchOne("SELECT * FROM users WHERE id=? AND is_active=1", [$uid]);
        if ($user && str_starts_with($user['password'], 'RESET::')) {
            $parts   = explode('::', $user['password']);
            $dbToken = $parts[1] ?? '';
            $expires = $parts[2] ?? '';
            if ($dbToken === $token && strtotime($expires) > time()) {
                $hash = password_hash($pass1, PASSWORD_BCRYPT, ['cost'=>10]);
                DB::execute("UPDATE users SET password=? WHERE id=?", [$hash, $uid]);
                // Clear all remember tokens for this user on password reset
                DB::execute("DELETE FROM remember_tokens WHERE user_id=?", [$uid]);
                $success = 'Password reset successfully! You can now log in.';
                $view = 'login';
            } else {
                $error = 'Reset link has expired or is invalid. Please request a new one.';
            }
        } else {
            $error = 'Invalid reset link. Please request a new one.';
        }
    }
}

// ── VALIDATE RESET TOKEN (GET) ────────────────────
$resetToken   = $_GET['token'] ?? '';
$resetUid     = (int)($_GET['uid'] ?? 0);
$tokenInvalid = false;
if ($view === 'reset' && $resetToken && $resetUid) {
    $user = DB::fetchOne("SELECT * FROM users WHERE id=? AND is_active=1", [$resetUid]);
    if (!$user || !str_starts_with($user['password'], 'RESET::')) {
        $tokenInvalid = true;
    } else {
        $parts = explode('::', $user['password']);
        if (($parts[1]??'') !== $resetToken || strtotime($parts[2]??'') <= time()) {
            $tokenInvalid = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>
  <?= $view==='forgot'||$view==='forgot_sent' ? 'Forgot Password' : ($view==='reset' ? 'Reset Password' : 'Login') ?>
  – <?= APP_NAME ?>
</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">
<style>
.login-page{min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--green-900);padding:20px}
.login-card{background:#fff;border-radius:16px;padding:36px 32px;width:400px;max-width:100%;box-shadow:0 20px 60px rgba(0,0,0,.3)}
.login-logo{text-align:center;margin-bottom:28px}
.login-logo .lmark{width:56px;height:56px;background:var(--green-400);border-radius:14px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px}
.login-logo .lmark img{width:100%;height:100%;object-fit:contain;padding:6px;border-radius:14px}
.login-logo .lmark i{color:#fff;font-size:30px}
.login-logo h1{font-size:22px;font-weight:700;color:var(--green-900);margin-bottom:4px}
.login-logo p{font-size:13px;color:var(--gray-400)}
.form-group{margin-bottom:16px}
.form-group label{display:block;font-size:12px;font-weight:700;color:var(--gray-600);text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px}
.form-group input[type=text],.form-group input[type=email],.form-group input[type=password]{width:100%;padding:11px 14px;border:1.5px solid #d8ddd5;border-radius:9px;font-size:14px;color:var(--green-900);transition:border-color .15s}
.form-group input:focus{outline:none;border-color:var(--green-400);box-shadow:0 0 0 3px rgba(99,153,34,.12)}
.pw-wrap{position:relative}
.pw-wrap input{padding-right:42px!important}
.pw-toggle{position:absolute;right:11px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--gray-400);font-size:18px;padding:0}
.pw-toggle:hover{color:var(--green-600)}
.remember-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
.remember-check{display:flex;align-items:center;gap:8px;cursor:pointer;user-select:none}
.remember-check input[type=checkbox]{width:17px;height:17px;accent-color:var(--green-600);cursor:pointer;border-radius:4px}
.remember-check span{font-size:13px;color:var(--gray-600);font-weight:500}
.remember-note{font-size:11px;color:var(--gray-400)}
.btn-login{width:100%;padding:12px;background:var(--green-600);color:#fff;border:none;border-radius:9px;font-size:15px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:background .15s}
.btn-login:hover{background:var(--green-800)}
.btn-login.teal{background:var(--teal-600)}
.btn-login.teal:hover{background:var(--teal-800,#0a5243)}
.link-row{text-align:center;margin-top:18px;font-size:13px;color:var(--gray-400)}
.link-row a{color:var(--green-600);text-decoration:none;font-weight:600}
.link-row a:hover{text-decoration:underline}
.divider{border:none;border-top:1px solid #e8ede5;margin:20px 0}
.reset-link-box{background:var(--green-50);border:1.5px solid var(--green-200);border-radius:10px;padding:14px;margin:16px 0;word-break:break-all}
.reset-link-box a{color:var(--green-700);font-size:12px;text-decoration:none;font-weight:600}
.step-badge{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:var(--green-600);color:#fff;font-size:12px;font-weight:700;flex-shrink:0}
.strength-bar{height:4px;border-radius:2px;margin-top:5px;background:#e8e8e8;overflow:hidden}
.strength-fill{height:100%;border-radius:2px;transition:width .3s,background .3s;width:0}
.security-info{background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:10px 12px;margin-top:12px;font-size:11px;color:#0369a1;display:flex;align-items:flex-start;gap:7px}
.security-info i{font-size:14px;flex-shrink:0;margin-top:1px}
</style>
</head>
<body>
<div class="login-page">
  <div class="login-card">

    <!-- LOGO -->
    <div class="login-logo">
      <?php
      try {
        $settingsRows = DB::fetchAll("SELECT `key`,`value` FROM app_settings WHERE `key` IN ('app_name','logo_file','logo_updated')", []);
        $s = array_column($settingsRows,'value','key');
        $dbAppName = $s['app_name'] ?? APP_NAME;
        $dbLogo    = $s['logo_file'] ?? '';
        $dbLogoTs  = $s['logo_updated'] ?? '1';
        $dbLogoUrl = $dbLogo ? BASE_URL.'/assets/img/'.$dbLogo.'?v='.$dbLogoTs : '';
      } catch(Exception $e){ $dbAppName=APP_NAME; $dbLogoUrl=''; }
      ?>
      <div class="lmark">
        <?php if ($dbLogoUrl): ?>
          <img src="<?= $dbLogoUrl ?>" alt="Logo">
        <?php else: ?>
          <i class="ti ti-leaf"></i>
        <?php endif; ?>
      </div>
      <h1><?= sanitize($dbAppName) ?></h1>
      <p>Estate Management System</p>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-error" style="margin-bottom:16px">
      <i class="ti ti-alert-circle"></i> <?= sanitize($error) ?>
    </div>
    <?php endif; ?>
    <?php if ($success && $success !== 'reset_link' && $success !== 'forgot_sent'): ?>
    <div class="alert alert-success" style="margin-bottom:16px">
      <i class="ti ti-check"></i> <?= sanitize($success) ?>
    </div>
    <?php endif; ?>

    <!-- ── LOGIN FORM ── -->
    <?php if ($view === 'login'): ?>
    <form method="POST">
      <input type="hidden" name="action" value="login">
      <div class="form-group">
        <label>Username or Email</label>
        <input type="text" name="username" placeholder="Enter username or email"
               required autofocus value="<?= sanitize($_POST['username'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Password</label>
        <div class="pw-wrap">
          <input type="password" name="password" id="pw-login" placeholder="Enter your password" required>
          <button type="button" class="pw-toggle" onclick="togglePw('pw-login',this)">
            <i class="ti ti-eye"></i>
          </button>
        </div>
      </div>

      <!-- REMEMBER ME -->
      <div class="remember-row">
        <label class="remember-check">
          <input type="checkbox" name="remember_me" id="remember-me" value="1">
          <span>Remember me</span>
        </label>
        <span class="remember-note" id="remember-note" style="display:none">30 days on this device</span>
      </div>

      <button type="submit" class="btn-login">
        <i class="ti ti-login"></i> Login
      </button>

      <!-- Security info shown when remember me is checked -->
      <div class="security-info" id="security-info" style="display:none">
        <i class="ti ti-shield-check"></i>
        <div>
          <strong>Secure Remember Me</strong> — You'll stay logged in for 30 days on this device and network.
          If you change your computer or internet connection, you'll be logged out automatically for security.
        </div>
      </div>
    </form>
    <div class="link-row" style="margin-top:20px">
      <a href="login.php?view=forgot"><i class="ti ti-key" style="font-size:13px;vertical-align:-1px"></i> Forgot Password?</a>
    </div>
    <div class="link-row" style="margin-top:8px;font-size:11px;color:var(--gray-300)">
      Contact your administrator if you need access
    </div>

    <!-- ── FORGOT PASSWORD ── -->
    <?php elseif ($view === 'forgot'): ?>
    <div style="text-align:center;margin-bottom:20px">
      <div style="width:52px;height:52px;background:var(--amber-50);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:10px">
        <i class="ti ti-key" style="font-size:26px;color:var(--amber-600)"></i>
      </div>
      <h2 style="font-size:18px;font-weight:700;color:var(--green-900);margin-bottom:4px">Forgot Password?</h2>
      <p style="font-size:13px;color:var(--gray-400)">Enter your username or email to generate a reset link.</p>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="forgot">
      <div class="form-group">
        <label>Username or Email</label>
        <input type="text" name="username" placeholder="Enter your username or email" required autofocus>
      </div>
      <button type="submit" class="btn-login teal">
        <i class="ti ti-send"></i> Generate Reset Link
      </button>
    </form>
    <hr class="divider">
    <div class="link-row">
      <a href="login.php"><i class="ti ti-arrow-left" style="font-size:13px;vertical-align:-1px"></i> Back to Login</a>
    </div>

    <!-- ── FORGOT SENT ── -->
    <?php elseif ($view === 'forgot_sent'): ?>
    <?php $resetLink = $_SESSION['reset_link'] ?? ''; $resetUser = $_SESSION['reset_user'] ?? ''; ?>
    <div style="text-align:center;margin-bottom:20px">
      <div style="width:52px;height:52px;background:var(--green-50);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:10px">
        <i class="ti ti-link" style="font-size:26px;color:var(--green-600)"></i>
      </div>
      <h2 style="font-size:18px;font-weight:700;color:var(--green-900);margin-bottom:4px">Reset Link Ready</h2>
      <?php if ($resetLink): ?>
      <p style="font-size:13px;color:var(--gray-400)">For <strong><?= $resetUser ?></strong></p>
      <?php endif; ?>
    </div>
    <?php if ($resetLink): ?>
    <div style="background:var(--gray-50);border-radius:10px;padding:14px 16px;margin-bottom:16px">
      <div style="font-size:12px;font-weight:700;color:var(--gray-600);text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px">How to use</div>
      <?php foreach(['Copy the link below','Open it in a browser','Set your new password — expires in 1 hour'] as $i=>$step): ?>
      <div style="display:flex;gap:10px;margin-bottom:8px;align-items:flex-start">
        <span class="step-badge"><?= $i+1 ?></span>
        <span style="font-size:13px;color:var(--gray-700)"><?= $step ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="reset-link-box">
      <div style="font-size:11px;font-weight:700;color:var(--green-700);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em">Reset Link:</div>
      <a href="<?= htmlspecialchars($resetLink) ?>" id="reset-link-text"><?= htmlspecialchars($resetLink) ?></a>
    </div>
    <button type="button" onclick="copyResetLink()" class="btn-login" style="background:var(--gray-600);margin-bottom:10px">
      <i class="ti ti-copy" id="copy-icon"></i> <span id="copy-label">Copy Link</span>
    </button>
    <a href="<?= htmlspecialchars($resetLink) ?>" class="btn-login" style="background:var(--green-600);text-decoration:none">
      <i class="ti ti-external-link"></i> Open Reset Page
    </a>
    <div style="font-size:11px;color:var(--gray-400);text-align:center;margin-top:12px">
      <i class="ti ti-clock" style="font-size:12px;vertical-align:-1px"></i> Expires in 1 hour
    </div>
    <?php endif; ?>
    <hr class="divider">
    <div class="link-row">
      <a href="login.php"><i class="ti ti-arrow-left" style="font-size:13px;vertical-align:-1px"></i> Back to Login</a>
    </div>

    <!-- ── RESET PASSWORD ── -->
    <?php elseif ($view === 'reset'): ?>
    <div style="text-align:center;margin-bottom:20px">
      <div style="width:52px;height:52px;background:<?= $tokenInvalid?'var(--red-50)':'var(--green-50)' ?>;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:10px">
        <i class="ti ti-<?= $tokenInvalid?'alert-circle':'lock-open' ?>" style="font-size:26px;color:<?= $tokenInvalid?'var(--red-400)':'var(--green-600)' ?>"></i>
      </div>
      <h2 style="font-size:18px;font-weight:700;color:var(--green-900);margin-bottom:4px">
        <?= $tokenInvalid ? 'Link Expired or Invalid' : 'Set New Password' ?>
      </h2>
      <?php if ($tokenInvalid): ?>
      <p style="font-size:13px;color:var(--red-400)">This reset link has expired or already been used.</p>
      <?php else: ?>
      <p style="font-size:13px;color:var(--gray-400)">Choose a strong new password.</p>
      <?php endif; ?>
    </div>
    <?php if (!$tokenInvalid): ?>
    <form method="POST" id="reset-form">
      <input type="hidden" name="action" value="reset">
      <input type="hidden" name="uid" value="<?= $resetUid ?>">
      <input type="hidden" name="token" value="<?= htmlspecialchars($resetToken) ?>">
      <div class="form-group">
        <label>New Password</label>
        <div class="pw-wrap">
          <input type="password" name="password" id="pw-new" placeholder="Min. 6 characters"
                 required minlength="6" oninput="checkStrength(this.value);checkMatch()">
          <button type="button" class="pw-toggle" onclick="togglePw('pw-new',this)"><i class="ti ti-eye"></i></button>
        </div>
        <div class="strength-bar"><div class="strength-fill" id="strength-fill"></div></div>
        <div style="font-size:11px;margin-top:3px;color:var(--gray-400)" id="strength-label"></div>
      </div>
      <div class="form-group">
        <label>Confirm Password</label>
        <div class="pw-wrap">
          <input type="password" name="password2" id="pw-confirm" placeholder="Repeat password"
                 required oninput="checkMatch()">
          <button type="button" class="pw-toggle" onclick="togglePw('pw-confirm',this)"><i class="ti ti-eye"></i></button>
        </div>
        <div style="font-size:11px;margin-top:3px" id="match-label"></div>
      </div>
      <button type="submit" class="btn-login" id="reset-submit">
        <i class="ti ti-check"></i> Set New Password
      </button>
    </form>
    <?php endif; ?>
    <hr class="divider">
    <div class="link-row">
      <a href="login.php?view=forgot"><i class="ti ti-refresh" style="font-size:13px;vertical-align:-1px"></i> Request New Link</a>
      &nbsp;·&nbsp;
      <a href="login.php"><i class="ti ti-login" style="font-size:13px;vertical-align:-1px"></i> Login</a>
    </div>
    <?php endif; ?>

  </div>
</div>

<script>
function togglePw(id, btn) {
  var inp  = document.getElementById(id);
  var icon = btn.querySelector('i');
  if (!inp) return;
  inp.type = inp.type === 'password' ? 'text' : 'password';
  icon.className = inp.type === 'password' ? 'ti ti-eye' : 'ti ti-eye-off';
}
function checkStrength(val) {
  var fill = document.getElementById('strength-fill');
  var lbl  = document.getElementById('strength-label');
  if (!fill) return;
  var score = 0;
  if (val.length >= 6)  score++;
  if (val.length >= 10) score++;
  if (/[A-Z]/.test(val)) score++;
  if (/[0-9]/.test(val)) score++;
  if (/[^A-Za-z0-9]/.test(val)) score++;
  var pct   = (score/5)*100;
  var color = score<=1?'#ef4444':score<=2?'#f59e0b':score<=3?'#3b82f6':'#22c55e';
  var text  = score<=1?'Weak':score<=2?'Fair':score<=3?'Good':'Strong';
  fill.style.width=pct+'%'; fill.style.background=color;
  lbl.textContent=val.length>0?text:''; lbl.style.color=color;
  checkMatch();
}
function checkMatch() {
  var p1  = document.getElementById('pw-new');
  var p2  = document.getElementById('pw-confirm');
  var lbl = document.getElementById('match-label');
  var btn = document.getElementById('reset-submit');
  if (!p1||!p2||!lbl) return;
  if (!p2.value) { lbl.textContent=''; return; }
  var ok = p1.value===p2.value;
  lbl.textContent = ok?'✔ Passwords match':'✘ Passwords do not match';
  lbl.style.color = ok?'#22c55e':'#ef4444';
  if (btn) btn.disabled = !ok;
}
function copyResetLink() {
  var link = document.getElementById('reset-link-text');
  if (!link) return;
  navigator.clipboard.writeText(link.href).then(function(){
    document.getElementById('copy-icon').className='ti ti-check';
    document.getElementById('copy-label').textContent='Copied!';
    setTimeout(function(){
      document.getElementById('copy-icon').className='ti ti-copy';
      document.getElementById('copy-label').textContent='Copy Link';
    },2500);
  });
}
// Remember me checkbox — show/hide info
var rmCb = document.getElementById('remember-me');
if (rmCb) {
  rmCb.addEventListener('change', function() {
    var note = document.getElementById('remember-note');
    var info = document.getElementById('security-info');
    if (note) note.style.display = this.checked ? 'inline' : 'none';
    if (info) info.style.display = this.checked ? 'flex'   : 'none';
  });
}
</script>
</body>
</html>
