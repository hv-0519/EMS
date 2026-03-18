<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pageTitle   = 'Privacy Policy';
$currentPage = 'privacy';
$pdo         = getDB();
$company     = 'Your Company';
try { $s = $pdo->query("SELECT from_name FROM smtp_config WHERE id=1"); $r=$s->fetch(); if($r && $r['from_name']) $company = $r['from_name']; } catch(\Throwable $e){}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
  <div>
    <h1><i class="fas fa-shield-halved" style="color:var(--primary);margin-right:8px"></i>Privacy &amp; Data Policy</h1>
    <p style="color:var(--text-light);font-size:13px;margin-top:4px">EmpAxis Employee Management System · Last reviewed <?= date('F Y') ?></p>
  </div>
  <div class="actions">
    <button class="btn btn-outline btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
  </div>
</div>

<!-- Hero banner -->
<div style="background:linear-gradient(135deg,var(--primary) 0%,#C0392B 100%);border-radius:14px;padding:24px 28px;margin-bottom:24px;display:flex;align-items:center;gap:20px;position:relative;overflow:hidden">
  <div style="position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.04) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.04) 1px,transparent 1px);background-size:28px 28px;pointer-events:none"></div>
  <div style="position:relative;z-index:1;width:60px;height:60px;background:rgba(255,255,255,.15);border-radius:14px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
    <i class="fas fa-lock" style="font-size:26px;color:#fff"></i>
  </div>
  <div style="position:relative;z-index:1">
    <div style="font-size:18px;font-weight:700;color:#fff;margin-bottom:4px">Employee Data Privacy &amp; Acceptable Use Policy</div>
    <div style="font-size:13px;color:rgba(255,255,255,.75)">This policy governs how EmpAxis collects, stores, uses and protects employee data. All employees are required to read and comply.</div>
  </div>
</div>

<div style="display:grid;grid-template-columns:220px 1fr;gap:24px;align-items:start">

  <!-- Sticky TOC -->
  <div class="card" style="margin:0;position:sticky;top:80px">
    <div style="padding:16px 18px">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-light);margin-bottom:12px">Contents</div>
      <?php
      $sections = [
        ['s1',  'fas fa-database',    'Data We Collect'],
        ['s2',  'fas fa-clock',       'Attendance & Hours'],
        ['s3',  'fas fa-rupee-sign',  'Hourly Payroll'],
        ['s4',  'fas fa-lock',        'Authentication'],
        ['s5',  'fas fa-eye',         'How We Use Data'],
        ['s6',  'fas fa-shield-alt',  'Data Security'],
        ['s7',  'fas fa-user-check',  'Your Rights'],
        ['s8',  'fas fa-bullhorn',    'Announcements'],
        ['s9',  'fas fa-gavel',       'Disciplinary Action'],
        ['s10', 'fas fa-robot',       'Future Biometrics'],
      ];
      foreach ($sections as [$id,$icon,$title]):
      ?>
      <a href="#<?= $id ?>" style="display:flex;align-items:center;gap:8px;padding:7px 8px;border-radius:8px;text-decoration:none;color:var(--text-mid);font-size:12.5px;transition:all .15s" onmouseover="this.style.background='var(--secondary)'" onmouseout="this.style.background='transparent'">
        <i class="<?= $icon ?>" style="color:var(--primary);width:14px;text-align:center;font-size:11px"></i>
        <?= $title ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Content -->
  <div>

    <div class="card" style="margin:0 0 16px" id="s1">
      <div style="padding:22px 24px">
        <h3 style="font-size:16px;font-weight:700;margin-bottom:14px;display:flex;align-items:center;gap:10px">
          <span style="width:32px;height:32px;background:var(--primary-light);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="fas fa-database" style="color:var(--primary);font-size:13px"></i></span>
          1. Data We Collect
        </h3>
        <p style="font-size:13.5px;line-height:1.8;color:var(--text-mid);margin-bottom:12px">We collect and process the following employee information within EmpAxis:</p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
          <?php foreach([
            ['fas fa-id-card','Personal details','Name, email, phone, date of birth, gender'],
            ['fas fa-briefcase','Employment info','Designation, department, joining date, salary'],
            ['fas fa-calendar-check','Attendance data','Check-in/out timestamps, session durations, daily totals'],
            ['fas fa-plane','Leave records','Requests, approvals, balances by leave type'],
            ['fas fa-wallet','Payroll data','Salary, bonuses, deductions, payslip history'],
            ['fas fa-globe','Login metadata','Timestamps, IP addresses, session identifiers'],
          ] as [$ic,$title,$desc]): ?>
          <div style="background:var(--secondary);border-radius:10px;padding:12px 14px;display:flex;gap:10px">
            <i class="<?= $ic ?>" style="color:var(--primary);margin-top:2px;flex-shrink:0"></i>
            <div><div style="font-size:13px;font-weight:600;color:var(--text-dark)"><?= $title ?></div><div style="font-size:12px;color:var(--text-light);margin-top:2px"><?= $desc ?></div></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="card" style="margin:0 0 16px" id="s2">
      <div style="padding:22px 24px">
        <h3 style="font-size:16px;font-weight:700;margin-bottom:14px;display:flex;align-items:center;gap:10px">
          <span style="width:32px;height:32px;background:var(--primary-light);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="fas fa-clock" style="color:var(--primary);font-size:13px"></i></span>
          2. Attendance &amp; Working Hours
        </h3>
        <p style="font-size:13.5px;line-height:1.8;color:var(--text-mid);margin-bottom:12px">
          EmpAxis supports <strong>multiple check-in/check-out sessions per day</strong>. Each time an employee logs in, a new work session begins. Each time they log out, that session ends. This is by design and enables accurate tracking even when employees take breaks or step out during the day.
        </p>
        <div style="background:#EFF6FF;border-left:4px solid #3B82F6;border-radius:0 8px 8px 0;padding:14px 16px;font-size:13px;color:#1E40AF;line-height:1.7">
          <strong>Example:</strong> Employee logs in at 08:10 → logs out at 08:20 (10 min). Logs back in at 09:00 → logs out at 17:30 (8h 30m). Total recorded: <strong>8h 40m</strong>. Both sessions count toward hourly pay.
        </div>
      </div>
    </div>

    <div class="card" style="margin:0 0 16px;border-left:4px solid #F59E0B" id="s3">
      <div style="padding:22px 24px">
        <h3 style="font-size:16px;font-weight:700;margin-bottom:14px;display:flex;align-items:center;gap:10px;color:#D97706">
          <span style="width:32px;height:32px;background:#FEF3C7;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="fas fa-rupee-sign" style="color:#D97706;font-size:13px"></i></span>
          3. Hourly Payroll Calculation
        </h3>
        <p style="font-size:13.5px;line-height:1.8;color:var(--text-mid);margin-bottom:12px">
          Employee compensation is calculated based on <strong>actual verified working hours</strong> as recorded in the system. The formula is:
        </p>
        <div style="background:#FFFBEB;border:1px solid #FDE68A;border-radius:10px;padding:16px 18px;font-size:14px;color:#92400E;margin-bottom:14px">
          <strong>Net Pay = Hourly Rate × Total Hours Worked This Month</strong><br>
          <span style="font-size:12px;color:#B45309;margin-top:4px;display:block">Hourly rate is configured per employee. Bonuses and deductions may be applied separately.</span>
        </div>
        <p style="font-size:13px;line-height:1.7;color:var(--text-mid)">
          If an employee is logged out for any reason — intentional, accidental, session timeout, browser close, or system restart — the session ends at that point. The next login starts a new session. <strong>All sessions are cumulated.</strong> No working time is lost due to accidental logout.
        </p>
      </div>
    </div>

    <div class="card" style="margin:0 0 16px" id="s4">
      <div style="padding:22px 24px">
        <h3 style="font-size:16px;font-weight:700;margin-bottom:14px;display:flex;align-items:center;gap:10px">
          <span style="width:32px;height:32px;background:var(--primary-light);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="fas fa-lock" style="color:var(--primary);font-size:13px"></i></span>
          4. Authentication &amp; Account Security
        </h3>
        <p style="font-size:13.5px;line-height:1.8;color:var(--text-mid)">
          All employee accounts are <strong>created and provisioned by HR or an Administrator</strong>. Employees do not self-register. On first login, employees are required to set their own personal password — HR does not have access to this password. Passwords are stored as bcrypt hashes and are never stored or transmitted in plain text after the initial welcome email.
        </p>
      </div>
    </div>

    <div class="card" style="margin:0 0 16px" id="s5">
      <div style="padding:22px 24px">
        <h3 style="font-size:16px;font-weight:700;margin-bottom:14px;display:flex;align-items:center;gap:10px">
          <span style="width:32px;height:32px;background:var(--primary-light);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="fas fa-eye" style="color:var(--primary);font-size:13px"></i></span>
          5. How We Use Your Data
        </h3>
        <ul style="font-size:13.5px;line-height:1.8;color:var(--text-mid);padding-left:20px">
          <li>Processing monthly salary and payroll based on recorded attendance sessions</li>
          <li>Generating attendance and leave reports for HR and management</li>
          <li>Verifying compliance with company working hours policies</li>
          <li>Sending in-app notifications, announcements and alerts</li>
          <li>Maintaining an audit trail of administrative actions</li>
          <li>Identifying unusual patterns for HR review</li>
        </ul>
      </div>
    </div>

    <div class="card" style="margin:0 0 16px" id="s6">
      <div style="padding:22px 24px">
        <h3 style="font-size:16px;font-weight:700;margin-bottom:14px;display:flex;align-items:center;gap:10px">
          <span style="width:32px;height:32px;background:var(--primary-light);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="fas fa-shield-alt" style="color:var(--primary);font-size:13px"></i></span>
          6. Data Security
        </h3>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px">
          <?php foreach([
            ['fas fa-key',        'bcrypt Passwords',      'One-way hashed, never stored in plain text'],
            ['fas fa-user-shield','Role-Based Access',     'Employees only see their own data'],
            ['fas fa-code',       'Prepared Statements',   'All DB queries use PDO to prevent SQL injection'],
          ] as [$ic,$t,$d]): ?>
          <div style="background:var(--secondary);border-radius:10px;padding:12px 14px;text-align:center">
            <i class="<?= $ic ?>" style="color:var(--primary);font-size:20px;margin-bottom:8px;display:block"></i>
            <div style="font-size:12.5px;font-weight:700;color:var(--text-dark)"><?= $t ?></div>
            <div style="font-size:11.5px;color:var(--text-light);margin-top:4px"><?= $d ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="card" style="margin:0 0 16px" id="s7">
      <div style="padding:22px 24px">
        <h3 style="font-size:16px;font-weight:700;margin-bottom:14px;display:flex;align-items:center;gap:10px">
          <span style="width:32px;height:32px;background:var(--primary-light);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="fas fa-user-check" style="color:var(--primary);font-size:13px"></i></span>
          7. Your Rights as an Employee
        </h3>
        <ul style="font-size:13.5px;line-height:1.8;color:var(--text-mid);padding-left:20px">
          <li>View your own attendance, payroll, and leave records at any time</li>
          <li>Request correction of inaccurate attendance data via HR</li>
          <li>Change your account password at any time from your profile</li>
          <li>Request clarification about how your data is being used</li>
        </ul>
      </div>
    </div>

    <div class="card" style="margin:0 0 16px" id="s8">
      <div style="padding:22px 24px">
        <h3 style="font-size:16px;font-weight:700;margin-bottom:14px;display:flex;align-items:center;gap:10px">
          <span style="width:32px;height:32px;background:var(--primary-light);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="fas fa-bullhorn" style="color:var(--primary);font-size:13px"></i></span>
          8. Announcements &amp; Communications
        </h3>
        <p style="font-size:13.5px;line-height:1.8;color:var(--text-mid)">
          HR and Administrators may send company-wide or targeted announcements through EmpAxis. These may be sent to all employees, a specific department, or an individual. Announcements are stored in your notification feed. You cannot opt out of system-critical announcements.
        </p>
      </div>
    </div>

    <div class="card" style="margin:0 0 16px;border-left:4px solid var(--danger)" id="s9">
      <div style="padding:22px 24px">
        <h3 style="font-size:16px;font-weight:700;margin-bottom:14px;display:flex;align-items:center;gap:10px;color:var(--danger)">
          <span style="width:32px;height:32px;background:#FEF2F2;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="fas fa-gavel" style="color:var(--danger);font-size:13px"></i></span>
          9. Disciplinary Action
        </h3>
        <p style="font-size:13.5px;line-height:1.8;color:var(--text-mid)">
          The company reserves the right to investigate and take action if attendance data reveals patterns that appear suspicious, fraudulent, or in violation of company policy. This includes but is not limited to: falsifying attendance, sharing login credentials, or manipulating session records.
        </p>
        <div style="background:#FEF2F2;border-radius:8px;padding:12px 16px;font-size:13px;color:#991B1B;margin-top:12px">
          <i class="fas fa-exclamation-triangle"></i> By using EmpAxis, all employees acknowledge and consent to the collection and processing of data as described in this policy.
        </div>
      </div>
    </div>

    <div class="card" style="margin:0 0 16px" id="s10">
      <div style="padding:22px 24px">
        <h3 style="font-size:16px;font-weight:700;margin-bottom:14px;display:flex;align-items:center;gap:10px">
          <span style="width:32px;height:32px;background:var(--primary-light);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="fas fa-robot" style="color:var(--primary);font-size:13px"></i></span>
          10. Future Biometric Systems (Planned)
        </h3>
        <p style="font-size:13.5px;line-height:1.8;color:var(--text-mid)">
          The company may in the future deploy NFC/RFID card readers, fingerprint scanners, or facial recognition for attendance. When deployed, separate policies and consent procedures will apply. The database schema already supports <code style="background:var(--secondary);padding:2px 6px;border-radius:4px">card_tap</code> entry method and <code style="background:var(--secondary);padding:2px 6px;border-radius:4px">card_uid</code> field.
        </p>
      </div>
    </div>

    <div style="background:var(--secondary);border-radius:12px;padding:16px 20px;font-size:12.5px;color:var(--text-light);margin-bottom:24px">
      <i class="fas fa-info-circle" style="color:var(--primary)"></i>
      For questions about this policy, contact your HR department or system administrator.
      This policy was last reviewed in <?= date('F Y') ?> and is subject to change with appropriate notice.
    </div>

  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
