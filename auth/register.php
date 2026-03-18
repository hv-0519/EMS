<?php
require_once __DIR__ . '/../includes/auth.php';
if (isLoggedIn()) redirect(APP_URL . '/dashboard/dashboard.php');

$pdo            = getDB();
$registerErrors = [];
$newUsername    = '';
$newEmpId       = '';
$regEmail       = '';
$mode           = 'form';

function uploadRegPhoto(string $n): string {
    if (empty($_FILES[$n]['name']) || ($_FILES[$n]['error'] ?? 1) !== 0) return 'default.png';
    $tmp = $_FILES[$n]['tmp_name'] ?? '';
    $sz  = (int)($_FILES[$n]['size'] ?? 0);
    if (!$tmp || $sz <= 0 || $sz > 2*1024*1024) return 'default.png';
    $ext = strtolower(pathinfo($_FILES[$n]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp'], true)) return 'default.png';
    if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0775, true);
    $file = 'emp_' . bin2hex(random_bytes(6)) . '.' . $ext;
    return move_uploaded_file($tmp, UPLOAD_DIR . $file) ? $file : 'default.png';
}

$departments = $pdo->query('SELECT id,department_name FROM departments ORDER BY department_name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name        = trim($_POST['name']           ?? '');
    $email       = trim($_POST['email']          ?? '');
    $pw          = $_POST['password']             ?? '';
    $confirm     = $_POST['confirm']              ?? '';
    $role        = $_POST['role']                 ?? 'employee';
    $phone       = trim($_POST['phone']           ?? '');
    $designation = trim($_POST['designation']     ?? '');
    $deptId      = (int)($_POST['department_id']  ?? 0) ?: null;
    $joining     = $_POST['joining_date']         ?? date('Y-m-d');
    $address     = trim($_POST['address']         ?? '');
    $regEmail    = $email;

    if (!$name)                                     $registerErrors[] = 'Full name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $registerErrors[] = 'Valid email is required.';
    if (strlen($pw) < 8)                            $registerErrors[] = 'Password must be at least 8 characters.';
    if ($pw !== $confirm)                           $registerErrors[] = 'Passwords do not match.';
    if (!in_array($role, ['admin','hr','employee'], true)) $role = 'employee';

    $chk = $pdo->prepare('SELECT id FROM users WHERE email=?');
    $chk->execute([$email]);
    if ($chk->fetch()) $registerErrors[] = 'This email is already registered.';

    if (!$registerErrors) {
        $pdo->beginTransaction();
        try {
            $username = generateUsername($name);
            $photo    = uploadRegPhoto('photo');

            $uCols = 'username,email,password,role,is_verified,display_name';
            $uVals = [$username, $email, password_hash($pw, PASSWORD_BCRYPT), $role, 1, $name];
            if (columnExists('users', 'avatar')) { $uCols .= ',avatar'; $uVals[] = $photo; }
            $ph = implode(',', array_fill(0, count($uVals), '?'));
            $pdo->prepare("INSERT INTO users ({$uCols}) VALUES ({$ph})")->execute($uVals);
            $uid     = (int)$pdo->lastInsertId();
            $empCode = 'EMP' . str_pad($uid, 4, '0', STR_PAD_LEFT);

            $eCols = 'user_id,employee_id,name,email,phone,department_id,designation,joining_date,salary,address,photo,status';
            $eVals = [$uid,$empCode,$name,$email,($phone?:null),$deptId,($designation?:null),$joining,0,($address?:null),$photo,'active'];
            $optCols = ['date_of_birth'=>($_POST['date_of_birth']??null)?:null,'gender'=>($_POST['gender']??null)?:null,'city'=>trim($_POST['city']??'')?:null,'state'=>trim($_POST['state']??'')?:null,'country'=>trim($_POST['country']??'')?:null,'postal_code'=>trim($_POST['postal_code']??'')?:null];
            foreach ($optCols as $col => $val) {
                if ($val !== null && columnExists('employees', $col)) { $eCols .= ",$col"; $eVals[] = $val; }
            }
            $ep = implode(',', array_fill(0, count($eVals), '?'));
            $pdo->prepare("INSERT INTO employees ({$eCols}) VALUES ({$ep})")->execute($eVals);

            $empPk = (int)$pdo->lastInsertId();
            ensureLeaveBalance($empPk);
            logActivity('Registered', "$name registered as $role", 'auth');
            $pdo->commit();
            $newUsername = $username;
            $newEmpId    = $empCode;
            $mode        = 'success';
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $registerErrors[] = 'Could not create account. Please try again.';
        }
    }
}

$v = fn(string $k, string $d = '') => htmlspecialchars($_POST[$k] ?? $d);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>EmpAxis — Create Account</title>
  <script>(function(){try{var s=localStorage.getItem('theme'),t=(s==='light'||s==='dark')?s:(window.matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light');document.documentElement.setAttribute('data-theme',t);}catch(e){}})()</script>
  <link rel="icon" type="image/png" href="<?= APP_URL ?>/logos/EmpAxis.png?v=2">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/auth.css">
  <style>
    /* ── Mobile step pills ── */
    .ea-mobile-steps{display:none;align-items:center;margin-bottom:20px}
    .ea-ms{display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:var(--text-light);white-space:nowrap;transition:color .3s}
    .ea-ms span{width:22px;height:22px;border-radius:50%;background:var(--border);color:var(--text-light);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;transition:all .3s}
    .ea-ms.active{color:var(--primary)}.ea-ms.active span{background:var(--primary);color:#fff;box-shadow:0 2px 8px rgba(255,107,107,.4)}
    .ea-ms.done{color:var(--success)}.ea-ms.done span{background:var(--success);color:#fff}
    .ea-ms-line{flex:1;height:2px;background:var(--border);margin:0 8px;border-radius:2px;min-width:20px}
    /* ── Wizard shell overrides ── */
    .ea-reg-shell .ea-right{min-height:100dvh;align-items:flex-start;padding-top:40px;padding-bottom:40px}
    .ea-reg-shell .ea-right-inner{padding-top:0}
    /* ── Responsive ── */
    @media(max-width:768px){
      .ea-reg-shell{flex-direction:column-reverse}
      .ea-reg-shell .ea-left{width:100%;min-height:auto}
      .ea-reg-shell .ea-left .ea-left-inner{padding:24px 20px 20px;min-height:auto}
      .ea-reg-shell .ea-left .ea-feat-list,
      .ea-reg-shell .ea-left .ea-metrics{display:none}
      .ea-reg-shell .ea-left .ea-hero{margin-bottom:0}
      .ea-reg-shell .ea-left .ea-headline{font-size:24px}
      .ea-reg-shell .ea-left .ea-theme-toggle{display:none}
      .ea-reg-shell .ea-right{border-left:none;padding-top:24px;min-height:auto}
      .ea-mobile-steps{display:flex}
      .ea-reg-top .ea-reg-step-label{display:none}
    }
    @media(max-width:480px){
      .ea-reg-shell .ea-right-inner{padding-left:16px;padding-right:16px}
      .ea-grid2{grid-template-columns:1fr !important}
    }
  </style>
</head>
<body class="ea-auth-body">
<?php if ($mode === 'success'): ?>
<!-- ══ SUCCESS ══ -->
<div class="ea-shell">
  <div class="ea-right" style="flex:1">
    <div class="ea-right-inner" style="max-width:460px">
      <div class="ea-success-wrap">
        <div class="ea-success-icon"><i class="fas fa-check"></i></div>
        <div class="ea-success-title">Account Created!</div>
        <p class="ea-success-sub">Welcome to EmpAxis. Save your credentials before signing in.</p>
        <div class="ea-creds">
          <div class="ea-cred" onclick="copyText('<?= htmlspecialchars($newUsername) ?>',this)">
            <div class="ea-cred-left"><div class="ea-cred-ico"><i class="fas fa-user"></i></div><div><div class="ea-cred-lbl">Username</div><div class="ea-cred-val"><?= htmlspecialchars($newUsername) ?></div></div></div>
            <div class="ea-cred-copy-btn"><i class="fas fa-copy"></i></div>
          </div>
          <div class="ea-cred" onclick="copyText('<?= htmlspecialchars($newEmpId) ?>',this)">
            <div class="ea-cred-left"><div class="ea-cred-ico"><i class="fas fa-id-card"></i></div><div><div class="ea-cred-lbl">Employee ID</div><div class="ea-cred-val"><?= htmlspecialchars($newEmpId) ?></div></div></div>
            <div class="ea-cred-copy-btn"><i class="fas fa-copy"></i></div>
          </div>
          <div class="ea-cred" onclick="copyText('<?= htmlspecialchars($regEmail) ?>',this)">
            <div class="ea-cred-left"><div class="ea-cred-ico"><i class="fas fa-envelope"></i></div><div><div class="ea-cred-lbl">Email</div><div class="ea-cred-val" style="font-size:14px"><?= htmlspecialchars($regEmail) ?></div></div></div>
            <div class="ea-cred-copy-btn"><i class="fas fa-copy"></i></div>
          </div>
        </div>
        <a href="<?= APP_URL ?>/auth/login.php" class="ea-btn ea-btn-primary ea-btn-block" style="margin-top:24px">
          <i class="fas fa-arrow-right-to-bracket"></i> Sign In Now
        </a>
      </div>
    </div>
  </div>
  <div class="ea-left">
    <div class="ea-left-inner">
      <div class="ea-brand"><div class="ea-brand-icon"><img src="<?= APP_URL ?>/logos/EmpAxis.png" alt="EmpAxis"></div><span class="ea-brand-name">Emp<b>Axis</b></span></div>
      <div class="ea-hero">
        <div class="ea-pill"><span class="ea-pill-dot"></span>Account Ready</div>
        <h1 class="ea-headline">You're all<br><em>set up!</em></h1>
        <p class="ea-sub">Your HR workspace is ready. Sign in to explore your dashboard, track attendance, and manage your profile.</p>
      </div>
      <div class="ea-feat-list">
        <div class="ea-feat"><div class="ea-feat-ico"><i class="fas fa-gauge-high"></i></div><div><div class="ea-feat-name">Personal Dashboard</div><div class="ea-feat-desc">Attendance, leaves &amp; payslips at a glance</div></div></div>
        <div class="ea-feat"><div class="ea-feat-ico"><i class="fas fa-clock"></i></div><div><div class="ea-feat-name">Attendance Tracking</div><div class="ea-feat-desc">Check in and out with a single click</div></div></div>
        <div class="ea-feat"><div class="ea-feat-ico"><i class="fas fa-calendar-check"></i></div><div><div class="ea-feat-name">Leave Management</div><div class="ea-feat-desc">Apply and track leave requests easily</div></div></div>
      </div>
      <button class="ea-theme-toggle" id="themeToggle"><i class="fas fa-sun ea-sun"></i><i class="fas fa-moon ea-moon"></i></button>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ══ WIZARD ══ -->
<div class="ea-shell ea-reg-shell">

  <!-- LEFT: Step-by-step form -->
  <div class="ea-right" style="flex:1;overflow-y:auto">
    <div class="ea-right-inner" style="max-width:500px;width:100%">

      <div class="ea-right-brand">
        <div class="ea-brand-icon-sm"><img src="<?= APP_URL ?>/logos/EmpAxis.png" alt="EmpAxis"></div>
        <span class="ea-brand-name-sm">Emp<b>Axis</b></span>
      </div>

      <!-- Progress bar -->
      <div class="ea-reg-top">
        <div class="ea-reg-step-label" id="stepLabel">Step 1 of 3 — Account Info</div>
        <div class="ea-reg-progress"><div class="ea-reg-progress-bar" id="progressBar" style="width:33.3%"></div></div>
      </div>

      <!-- Mobile step pills -->
      <div class="ea-mobile-steps">
        <div class="ea-ms" id="ms1"><span>1</span> Account</div>
        <div class="ea-ms-line"></div>
        <div class="ea-ms" id="ms2"><span>2</span> Profile</div>
        <div class="ea-ms-line"></div>
        <div class="ea-ms" id="ms3"><span>3</span> Review</div>
      </div>

      <?php if ($registerErrors): ?>
      <div class="ea-error-box">
        <i class="fas fa-circle-exclamation" style="flex-shrink:0"></i>
        <ul style="margin:0;padding-left:12px"><?php foreach ($registerErrors as $err): ?><li><?= htmlspecialchars($err) ?></li><?php endforeach; ?></ul>
      </div>
      <?php endif; ?>

      <form method="POST" enctype="multipart/form-data" id="regForm" novalidate>

        <!-- STEP 1 -->
        <div class="ea-step active" id="step1">
          <div class="ea-step-heading">Create your account</div>
          <p class="ea-step-desc">Enter your login credentials to get started.</p>

          <div class="ea-fgroup">
            <label>Full Name <span class="ea-req">*</span></label>
            <div class="ea-finput"><i class="fas fa-user ea-ficon"></i>
              <input type="text" name="name" id="f_name" class="ea-input" placeholder="John Doe" value="<?= $v('name') ?>" autocomplete="name">
            </div>
            <div class="ea-uname-hint" id="usernameHint"></div>
          </div>

          <div class="ea-fgroup">
            <label>Email Address <span class="ea-req">*</span></label>
            <div class="ea-finput"><i class="fas fa-envelope ea-ficon"></i>
              <input type="email" name="email" id="f_email" class="ea-input" placeholder="john@company.com" value="<?= $v('email') ?>" autocomplete="email">
            </div>
          </div>

          <div class="ea-grid2">
            <div class="ea-fgroup">
              <label>Password <span class="ea-req">*</span></label>
              <div class="ea-finput"><i class="fas fa-lock ea-ficon"></i>
                <input type="password" name="password" id="f_password" class="ea-input" placeholder="Min 8 characters" autocomplete="new-password">
                <button type="button" class="ea-eye" data-for="f_password"><i class="fas fa-eye"></i></button>
              </div>
              <div class="ea-pw-track"><div class="ea-pw-fill" id="pwFill"></div></div>
              <div class="ea-pw-hint" id="pwHint"></div>
            </div>
            <div class="ea-fgroup">
              <label>Confirm Password <span class="ea-req">*</span></label>
              <div class="ea-finput"><i class="fas fa-lock ea-ficon"></i>
                <input type="password" name="confirm" id="f_confirm" class="ea-input" placeholder="Repeat password" autocomplete="new-password">
                <button type="button" class="ea-eye" data-for="f_confirm"><i class="fas fa-eye"></i></button>
              </div>
            </div>
          </div>

          <div class="ea-fgroup">
            <label>Role</label>
            <div class="ea-finput"><i class="fas fa-id-badge ea-ficon"></i>
              <select name="role" id="f_role" class="ea-input">
                <option value="employee" <?= $v('role','employee')==='employee'?'selected':'' ?>>Employee</option>
                <option value="hr"       <?= $v('role')==='hr'?'selected':'' ?>>HR Manager</option>
                <option value="admin"    <?= $v('role')==='admin'?'selected':'' ?>>Admin</option>
              </select>
            </div>
          </div>

          <div class="ea-step-footer" style="border-top:none;padding-top:0;justify-content:flex-end">
            <button type="button" class="ea-btn ea-btn-primary" onclick="nextStep(1)">
              Next: Profile Info <i class="fas fa-arrow-right"></i>
            </button>
          </div>
        </div>

        <!-- STEP 2 -->
        <div class="ea-step" id="step2">
          <div class="ea-step-heading">Your profile details</div>
          <p class="ea-step-desc">Set up your employee record. All fields except phone are optional.</p>

          <div class="ea-grid2">
            <div class="ea-fgroup">
              <label>Phone <span class="ea-opt">(optional)</span></label>
              <div class="ea-finput"><i class="fas fa-phone ea-ficon"></i>
                <input type="tel" name="phone" id="f_phone" class="ea-input" placeholder="+91 9876543210" value="<?= $v('phone') ?>" autocomplete="tel">
              </div>
            </div>
            <div class="ea-fgroup">
              <label>Joining Date</label>
              <div class="ea-finput"><i class="fas fa-calendar ea-ficon"></i>
                <input type="date" name="joining_date" id="f_joining" class="ea-input" value="<?= $v('joining_date', date('Y-m-d')) ?>">
              </div>
            </div>
          </div>

          <div class="ea-grid2">
            <div class="ea-fgroup">
              <label>Department</label>
              <div class="ea-finput"><i class="fas fa-sitemap ea-ficon"></i>
                <select name="department_id" id="f_dept" class="ea-input">
                  <option value="">Select Department</option>
                  <?php foreach ($departments as $d): ?>
                  <option value="<?= $d['id'] ?>" <?= ($v('department_id')==$d['id'])?'selected':'' ?>><?= htmlspecialchars($d['department_name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="ea-fgroup">
              <label>Designation</label>
              <div class="ea-finput"><i class="fas fa-briefcase ea-ficon"></i>
                <input type="text" name="designation" id="f_desig" class="ea-input" placeholder="e.g. Software Engineer" value="<?= $v('designation') ?>">
              </div>
            </div>
          </div>

          <div class="ea-fgroup">
            <label>Address <span class="ea-opt">(optional)</span></label>
            <div class="ea-finput ea-textarea-wrap"><i class="fas fa-map-marker-alt ea-ficon"></i>
              <textarea name="address" id="f_address" class="ea-input ea-textarea" placeholder="Your full address…" rows="2"><?= $v('address') ?></textarea>
            </div>
          </div>

          <div class="ea-fgroup">
            <label>Profile Photo <span class="ea-opt">(optional)</span></label>
            <div class="ea-photo-zone" id="photoZone">
              <input type="file" name="photo" id="f_photo" accept="image/*" style="display:none">
              <div class="ea-photo-placeholder" id="photoPlaceholder">
                <i class="fas fa-camera"></i>
                <div><div>Click to upload your photo</div><small>JPG, PNG, WEBP · max 2 MB</small></div>
              </div>
              <div id="photoPreview" style="display:none;align-items:center;gap:12px">
                <img id="photoImg" src="" alt="preview" style="width:52px;height:52px;border-radius:50%;object-fit:cover;border:2px solid var(--primary)">
                <div><div id="photoName" style="font-size:13px;font-weight:600;color:var(--text-dark)"></div><div style="font-size:12px;color:var(--text-light)">Click to change</div></div>
              </div>
            </div>
          </div>

          <div class="ea-step-footer">
            <button type="button" class="ea-btn ea-btn-ghost" onclick="goStep(1)"><i class="fas fa-arrow-left"></i> Back</button>
            <button type="button" class="ea-btn ea-btn-primary" onclick="nextStep(2)">Next: Review <i class="fas fa-arrow-right"></i></button>
          </div>
        </div>

        <!-- STEP 3 -->
        <div class="ea-step" id="step3">
          <div class="ea-step-heading">Review &amp; confirm</div>
          <p class="ea-step-desc">Double-check your details before creating your account.</p>

          <div class="ea-review">
            <div class="ea-review-head">
              <i class="fas fa-user-circle" style="color:var(--primary)"></i> Account Info
              <button type="button" onclick="goStep(1)" style="margin-left:auto;background:none;border:none;color:var(--primary);font-size:11.5px;font-weight:600;cursor:pointer;font-family:inherit;padding:0">Edit</button>
            </div>
            <div class="ea-review-grid">
              <div class="ea-rv-item"><span>Full Name</span><b id="rv_name">—</b></div>
              <div class="ea-rv-item"><span>Email</span><b id="rv_email">—</b></div>
              <div class="ea-rv-item"><span>Role</span><b id="rv_role">—</b></div>
              <div class="ea-rv-item"><span>Password</span><b>••••••••</b></div>
            </div>
          </div>

          <div class="ea-review" style="margin-top:10px">
            <div class="ea-review-head">
              <i class="fas fa-address-card" style="color:var(--primary)"></i> Profile Info
              <button type="button" onclick="goStep(2)" style="margin-left:auto;background:none;border:none;color:var(--primary);font-size:11.5px;font-weight:600;cursor:pointer;font-family:inherit;padding:0">Edit</button>
            </div>
            <div class="ea-review-grid">
              <div class="ea-rv-item"><span>Phone</span><b id="rv_phone">—</b></div>
              <div class="ea-rv-item"><span>Joining Date</span><b id="rv_joining">—</b></div>
              <div class="ea-rv-item"><span>Department</span><b id="rv_dept">—</b></div>
              <div class="ea-rv-item"><span>Designation</span><b id="rv_desig">—</b></div>
              <div class="ea-rv-item" style="grid-column:1/-1"><span>Address</span><b id="rv_address">—</b></div>
            </div>
          </div>

          <div class="ea-step-footer">
            <button type="button" class="ea-btn ea-btn-ghost" onclick="goStep(2)"><i class="fas fa-arrow-left"></i> Back</button>
            <button type="submit" class="ea-btn ea-btn-success" id="submitBtn"><i class="fas fa-user-plus"></i> Create Account</button>
          </div>
        </div>

      </form>

      <div class="ea-switch" style="text-align:center;margin-top:20px">
        Already have an account? <a href="<?= APP_URL ?>/auth/login.php" class="ea-switch-link">Sign In &rarr;</a>
      </div>
    </div>
  </div>

  <!-- RIGHT: branded showcase panel (mirrors login's left panel) -->
  <div class="ea-left">
    <div class="ea-left-inner">
      <div class="ea-brand"><div class="ea-brand-icon"><img src="<?= APP_URL ?>/logos/EmpAxis.png" alt="EmpAxis"></div><span class="ea-brand-name">Emp<b>Axis</b></span></div>
      <div class="ea-hero">
        <div class="ea-pill"><span class="ea-pill-dot"></span>HR Platform</div>
        <h1 class="ea-headline">Join your<br><em>team today.</em></h1>
        <p class="ea-sub">Set up your employee account in under a minute and access your full HR workspace instantly.</p>
      </div>
      <!-- Step guide cards — highlight current step -->
      <div class="ea-feat-list">
        <div class="ea-feat ea-guide-step" id="guide1">
          <div class="ea-feat-ico"><i class="fas fa-lock"></i></div>
          <div><div class="ea-feat-name">Step 1 · Account Info</div><div class="ea-feat-desc">Name, email &amp; secure password</div></div>
          <i class="fas fa-chevron-right" style="color:rgba(255,255,255,.5);margin-left:auto;font-size:11px"></i>
        </div>
        <div class="ea-feat ea-guide-step" id="guide2">
          <div class="ea-feat-ico"><i class="fas fa-address-card"></i></div>
          <div><div class="ea-feat-name">Step 2 · Profile Details</div><div class="ea-feat-desc">Department, role &amp; photo</div></div>
        </div>
        <div class="ea-feat ea-guide-step" id="guide3">
          <div class="ea-feat-ico"><i class="fas fa-clipboard-check"></i></div>
          <div><div class="ea-feat-name">Step 3 · Review &amp; Submit</div><div class="ea-feat-desc">Confirm &amp; create your account</div></div>
        </div>
      </div>
      <div class="ea-metrics">
        <div class="ea-metric"><b>3</b><span>Roles</span></div>
        <div class="ea-metric-sep"></div>
        <div class="ea-metric"><b>8+</b><span>Modules</span></div>
        <div class="ea-metric-sep"></div>
        <div class="ea-metric"><b>100%</b><span>Free &amp; Open</span></div>
      </div>
      <button class="ea-theme-toggle" id="themeToggle"><i class="fas fa-sun ea-sun"></i><i class="fas fa-moon ea-moon"></i></button>
    </div>
  </div>

</div>
<?php endif; ?>

<script src="<?= APP_URL ?>/assets/js/main.js"></script>
<script>
// Theme
document.getElementById('themeToggle')?.addEventListener('click',()=>{const t=document.documentElement.getAttribute('data-theme')==='dark'?'light':'dark';document.documentElement.setAttribute('data-theme',t);try{localStorage.setItem('theme',t);}catch(e){}});

// Eye toggles
document.querySelectorAll('.ea-eye').forEach(b=>{b.addEventListener('click',function(){const f=document.getElementById(this.dataset.for),i=this.querySelector('i');if(!f)return;f.type=f.type==='password'?'text':'password';i.className=f.type==='password'?'fas fa-eye':'fas fa-eye-slash';});});

// Password strength
const pwInput=document.getElementById('f_password'),pwFill=document.getElementById('pwFill'),pwHint=document.getElementById('pwHint');
if(pwInput){pwInput.addEventListener('input',function(){const v=this.value;let s=0;if(v.length>=8)s++;if(/[A-Z]/.test(v))s++;if(/[0-9]/.test(v))s++;if(/[^A-Za-z0-9]/.test(v))s++;const L=[{p:'0%',c:'transparent',t:''},{p:'25%',c:'#EF4444',t:'Weak'},{p:'50%',c:'#F59E0B',t:'Fair'},{p:'75%',c:'#3B82F6',t:'Good'},{p:'100%',c:'#10B981',t:'Strong'}][s];if(pwFill){pwFill.style.width=L.p;pwFill.style.background=L.c;}if(pwHint){pwHint.textContent=L.t;pwHint.style.color=L.c;}});}

// Username hint
const nameInput=document.getElementById('f_name'),uHint=document.getElementById('usernameHint');
if(nameInput&&uHint){nameInput.addEventListener('input',function(){const p=this.value.toLowerCase().replace(/[^a-z ]/g,'').trim().split(/\s+/);const u=p[0]?(p[0].substring(0,8)+(p[1]?'.'+p[1].substring(0,8):'')).replace(/\.$/,''):'';uHint.textContent=u?'Username will be: '+u:'';});}

// Photo preview
const photoZone=document.getElementById('photoZone'),photoInput=document.getElementById('f_photo'),photoPlaceholder=document.getElementById('photoPlaceholder'),photoPreview=document.getElementById('photoPreview'),photoImg=document.getElementById('photoImg'),photoName=document.getElementById('photoName');
if(photoZone){
  photoZone.addEventListener('click',()=>photoInput.click());
  photoInput?.addEventListener('change',function(){const file=this.files[0];if(!file)return;const r=new FileReader();r.onload=e=>{if(photoImg)photoImg.src=e.target.result;if(photoName)photoName.textContent=file.name;photoPlaceholder.style.display='none';photoPreview.style.display='flex';};r.readAsDataURL(file);});
  ['dragover','dragenter'].forEach(ev=>photoZone.addEventListener(ev,e=>{e.preventDefault();photoZone.classList.add('drag');}));
  ['dragleave','drop'].forEach(ev=>photoZone.addEventListener(ev,()=>photoZone.classList.remove('drag')));
  photoZone.addEventListener('drop',e=>{e.preventDefault();if(e.dataTransfer.files[0]){const dt=new DataTransfer();dt.items.add(e.dataTransfer.files[0]);photoInput.files=dt.files;photoInput.dispatchEvent(new Event('change'));}});
}

// ── Wizard logic ──
const STEPS=3,stepLabels=['Step 1 of 3 — Account Info','Step 2 of 3 — Profile Details','Step 3 of 3 — Review & Submit'];
let currentStep=1;

function goStep(n){
  if(n<1||n>STEPS)return;
  document.querySelectorAll('.ea-step').forEach(s=>s.classList.remove('active'));
  document.getElementById('step'+n)?.classList.add('active');
  currentStep=n;
  document.getElementById('progressBar').style.width=(n/STEPS*100).toFixed(1)+'%';
  document.getElementById('stepLabel').textContent=stepLabels[n-1];
  // Mobile pills
  for(let i=1;i<=STEPS;i++){const el=document.getElementById('ms'+i);if(!el)continue;el.classList.toggle('active',i===n);el.classList.toggle('done',i<n);}
  // Guide highlight
  document.querySelectorAll('.ea-guide-step').forEach((el,idx)=>{
    const s=idx+1;
    el.style.background=s===n?'rgba(255,255,255,.18)':'rgba(255,255,255,.08)';
    el.style.borderColor=s===n?'rgba(255,255,255,.35)':'rgba(255,255,255,.15)';
    el.style.opacity=s<n?'0.6':'1';
  });
  window.scrollTo({top:0,behavior:'smooth'});
}

function validateStep1(){
  const name=document.getElementById('f_name').value.trim();
  const email=document.getElementById('f_email').value.trim();
  const pw=document.getElementById('f_password').value;
  const conf=document.getElementById('f_confirm').value;
  const e=[];
  if(!name)e.push('Full name is required.');
  if(!email||!/\S+@\S+\.\S+/.test(email))e.push('Valid email is required.');
  if(pw.length<8)e.push('Password must be at least 8 characters.');
  if(pw!==conf)e.push('Passwords do not match.');
  return e;
}

function nextStep(from){
  if(from===1){const e=validateStep1();if(e.length){showClientErrors(e);return;}clearClientErrors();}
  goStep(from+1);
  if(from+1===3)fillReview();
}

function fillReview(){
  const safe=v=>v||'<span style="color:var(--text-light)">—</span>';
  const get=id=>document.getElementById(id);
  get('rv_name').innerHTML=safe(get('f_name')?.value.trim());
  get('rv_email').innerHTML=safe(get('f_email')?.value.trim());
  const roleEl=get('f_role');
  get('rv_role').innerHTML=safe(roleEl?.options[roleEl.selectedIndex]?.text);
  get('rv_phone').innerHTML=safe(get('f_phone')?.value.trim());
  get('rv_joining').innerHTML=safe(get('f_joining')?.value);
  const deptEl=get('f_dept');
  const deptText=deptEl?.options[deptEl.selectedIndex]?.text;
  get('rv_dept').innerHTML=safe(deptText==='Select Department'?'':deptText);
  get('rv_desig').innerHTML=safe(get('f_desig')?.value.trim());
  get('rv_address').innerHTML=safe(get('f_address')?.value.trim());
}

function showClientErrors(errs){
  clearClientErrors();
  const box=document.createElement('div');
  box.id='clientErrorBox';box.className='ea-error-box';box.style.marginBottom='16px';
  box.innerHTML='<i class="fas fa-circle-exclamation" style="flex-shrink:0"></i><ul style="margin:0;padding-left:12px">'+errs.map(e=>'<li>'+e+'</li>').join('')+'</ul>';
  document.getElementById('regForm').prepend(box);
  box.scrollIntoView({behavior:'smooth',block:'nearest'});
}
function clearClientErrors(){document.getElementById('clientErrorBox')?.remove();}

// Submit loading state
document.getElementById('regForm')?.addEventListener('submit',function(){
  const btn=document.getElementById('submitBtn');
  if(btn){btn.disabled=true;btn.innerHTML='<i class="fas fa-circle-notch fa-spin"></i> Creating…';}
});

// Copy helper
function copyText(text,el){navigator.clipboard?.writeText(text).then(()=>{const ico=el.querySelector('.ea-cred-copy-btn i');if(ico){ico.className='fas fa-check';setTimeout(()=>ico.className='fas fa-copy',1800);}});}

// If PHP returned errors, stay on step 1 and scroll
<?php if ($registerErrors): ?>goStep(1);<?php endif; ?>
// Init
goStep(1);
</script>
</body>
</html>
