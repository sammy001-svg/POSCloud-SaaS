<?php
require_once __DIR__ . '/../../config/auth.php';
requireRole(ROLE_RESELLER);
require_once __DIR__ . '/_layout.php';

$db  = getDB();
$rid = currentUser()['reseller_id'];
$msg = $err = '';
$currentPage = 'branding';

$stmt = $db->prepare("SELECT * FROM resellers WHERE id=?"); $stmt->execute([$rid]);
$reseller = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $company_name = trim($_POST['company_name'] ?? '');
    $primary      = $_POST['brand_color_primary']   ?? '#10b981';
    $secondary    = $_POST['brand_color_secondary'] ?? '#1e3a8a';
    $sidebar_bg   = $_POST['brand_sidebar_color']   ?? '#111827';
    $text_color   = $_POST['brand_text_color']      ?? '#ffffff';
    $domain       = trim($_POST['custom_domain'] ?? '');
    
    // SMTP
    $smtp_host = trim($_POST['smtp_host'] ?? '');
    $smtp_port = (int)($_POST['smtp_port'] ?? 587);
    $smtp_user = trim($_POST['smtp_user'] ?? '');
    $smtp_pass = trim($_POST['smtp_pass'] ?? '');
    $smtp_from_email = trim($_POST['smtp_from_email'] ?? '');
    $smtp_from_name  = trim($_POST['smtp_from_name'] ?? '');

    // Handle logo upload
    $logo = $reseller['logo_path'];
    if (!empty($_FILES['logo']['name'])) {
        $ext  = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        $allowed = ['png','jpg','jpeg','gif','svg','webp'];
        if (!in_array($ext, $allowed)) {
            $err = 'Invalid file type. Allowed: PNG, JPG, SVG, WebP.';
        } elseif ($_FILES['logo']['size'] > 2 * 1024 * 1024) {
            $err = 'Logo must be under 2MB.';
        } else {
            $filename = 'reseller_' . $rid . '_' . time() . '.' . $ext;
            $dest = LOGO_DIR . $filename;
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $dest)) {
                $logo = $filename;
            } else {
                $err = 'Failed to upload logo. Check directory permissions.';
            }
        }
    }

    if (!$err) {
        $db->prepare("UPDATE resellers SET 
            company_name=?, brand_color_primary=?, brand_color_secondary=?, 
            brand_sidebar_color=?, brand_text_color=?, custom_domain=?, logo_path=?,
            smtp_host=?, smtp_port=?, smtp_user=?, smtp_pass=?, smtp_from_email=?, smtp_from_name=?
            WHERE id=?")
           ->execute([
               $company_name, $primary, $secondary, 
               $sidebar_bg, $text_color, $domain, $logo,
               $smtp_host, $smtp_port, $smtp_user, $smtp_pass, $smtp_from_email, $smtp_from_name,
               $rid
           ]);
        $msg = 'Branding and SMTP settings updated successfully!';
        // Refresh
        $stmt = $db->prepare("SELECT * FROM resellers WHERE id=?"); $stmt->execute([$rid]);
        $reseller = $stmt->fetch();
    }
}

resellerLayout('White-Label Branding', $currentPage);
$primary      = $reseller['brand_color_primary']   ?? '#10b981';
$secondary    = $reseller['brand_color_secondary'] ?? '#1e3a8a';
$sidebar_bg   = $reseller['brand_sidebar_color']   ?? '#111827';
$text_color   = $reseller['brand_text_color']      ?? '#ffffff';
?>

<div class="page-header">
  <div><div class="page-title">Identity & White-Labeling</div><div class="page-subtitle">Fully brand the platform as your own solution</div></div>
</div>

<?php if ($msg): ?><div class="alert alert-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger">⚠️ <?= htmlspecialchars($err) ?></div><?php endif; ?>

<form method="POST" enctype="multipart/form-data">
  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>

  <div class="grid-2">
    <!-- General Branding -->
    <div>
      <div class="card" style="margin-bottom:20px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:20px;color:var(--primary);display:flex;align-items:center;gap:10px;">🏢 Agency Identity</div>
        <div class="form-group">
          <label class="form-label">Agency / Company Name</label>
          <input class="form-control" name="company_name" value="<?= htmlspecialchars($reseller['company_name']) ?>" placeholder="Acme POS Solutions"/>
        </div>
        
        <div style="margin-top:20px;">
          <label class="form-label">Platform Logo</label>
          <?php if ($reseller['logo_path']): ?>
            <div style="margin-bottom:12px;">
              <img src="<?= APP_URL ?>/uploads/logos/<?= htmlspecialchars($reseller['logo_path']) ?>" style="max-height:50px;border-radius:8px;border:1px solid var(--border);padding:8px;background:var(--bg-dark);"/>
            </div>
          <?php endif; ?>
          <input class="form-control" type="file" name="logo" accept="image/*" onchange="previewLogo(this)"/>
          <img id="logoPreview" style="display:none;max-height:50px;border-radius:8px;border:1px solid var(--border);padding:8px;margin-top:8px;" alt="Logo preview"/>
        </div>
      </div>

      <div class="card" style="margin-bottom:20px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:20px;color:var(--primary);">🎨 Advanced UI Customization</div>
        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">Primary Color</label>
            <input type="color" name="brand_color_primary" value="<?= htmlspecialchars($primary) ?>" class="form-control" style="height:45px;padding:5px;" oninput="updatePreview()"/>
          </div>
          <div class="form-group">
            <label class="form-label">Secondary Color</label>
            <input type="color" name="brand_color_secondary" value="<?= htmlspecialchars($secondary) ?>" class="form-control" style="height:45px;padding:5px;" oninput="updatePreview()"/>
          </div>
          <div class="form-group">
            <label class="form-label">Sidebar Background</label>
            <input type="color" name="brand_sidebar_color" value="<?= htmlspecialchars($sidebar_bg) ?>" id="sidebarColor" class="form-control" style="height:45px;padding:5px;" oninput="updatePreview()"/>
          </div>
          <div class="form-group">
            <label class="form-label">Sidebar Text Color</label>
            <input type="color" name="brand_text_color" value="<?= htmlspecialchars($text_color) ?>" id="textColor" class="form-control" style="height:45px;padding:5px;" oninput="updatePreview()"/>
          </div>
        </div>
      </div>

      <div class="card">
        <div style="font-weight:700;font-size:15px;margin-bottom:20px;color:var(--primary);">🌐 Custom Domain Settings</div>
        <div class="form-group">
          <label class="form-label">Your Custom Domain</label>
          <input class="form-control" name="custom_domain" value="<?= htmlspecialchars($reseller['custom_domain'] ?? '') ?>" placeholder="portal.mybrand.com"/>
        </div>
        <div style="background: rgba(16,185,129,0.05); padding: 15px; border-radius: 12px; border: 1px solid rgba(16,185,129,0.1);">
          <div style="font-weight: 800; font-size: 11px; text-transform: uppercase; color: var(--success); margin-bottom: 8px;">DNS Configuration</div>
          <div style="font-size: 13px; line-height: 1.6; color: var(--text-secondary);">
            To use your custom domain, point your <strong>CNAME</strong> or <strong>A Record</strong> to our platform IP:
            <div style="background: var(--bg-dark); padding: 8px; border-radius: 6px; margin: 10px 0; font-family: monospace; color: white; border: 1px solid var(--border);">149.28.242.12</div>
            Once the DNS propagates, your clients will access the platform through your domain.
          </div>
        </div>
      </div>
    </div>

    <!-- SMTP Settings -->
    <div>
      <div class="card" style="margin-bottom:20px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:20px;color:var(--primary);">📧 Custom SMTP (Email Outgoing)</div>
        <p style="font-size:12px; color:var(--text-muted); margin-bottom:20px;">Setup your own email server to send white-labeled notifications to your clients.</p>
        
        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">SMTP Host</label>
            <input class="form-control" name="smtp_host" value="<?= htmlspecialchars($reseller['smtp_host'] ?? '') ?>" placeholder="smtp.gmail.com"/>
          </div>
          <div class="form-group">
            <label class="form-label">SMTP Port</label>
            <input class="form-control" name="smtp_port" value="<?= htmlspecialchars($reseller['smtp_port'] ?? '587') ?>" placeholder="587"/>
          </div>
          <div class="form-group">
            <label class="form-label">SMTP Username</label>
            <input class="form-control" name="smtp_user" value="<?= htmlspecialchars($reseller['smtp_user'] ?? '') ?>" placeholder="user@domain.com"/>
          </div>
          <div class="form-group">
            <label class="form-label">SMTP Password</label>
            <input class="form-control" type="password" name="smtp_pass" value="<?= htmlspecialchars($reseller['smtp_pass'] ?? '') ?>" placeholder="••••••••"/>
          </div>
          <div class="form-group">
            <label class="form-label">From Email</label>
            <input class="form-control" name="smtp_from_email" value="<?= htmlspecialchars($reseller['smtp_from_email'] ?? '') ?>" placeholder="no-reply@domain.com"/>
          </div>
          <div class="form-group">
            <label class="form-label">From Name</label>
            <input class="form-control" name="smtp_from_name" value="<?= htmlspecialchars($reseller['smtp_from_name'] ?? '') ?>" placeholder="Support - Acme POS"/>
          </div>
        </div>
      </div>

      <div class="card">
        <div style="font-weight:700;font-size:15px;margin-bottom:15px;color:var(--primary);">Preview Interface</div>
        <div id="previewCard" style="background:var(--bg-card);border:1px solid var(--border);border-radius:16px;overflow:hidden;">
          <div id="prevSidebar" style="background:<?= $sidebar_bg ?>;padding:16px;display:flex;align-items:center;gap:10px;border-bottom:1px solid var(--border);">
            <div id="prevIcon" style="width:36px;height:36px;border-radius:8px;background:<?= $primary ?>;display:flex;align-items:center;justify-content:center;font-size:18px;">🤝</div>
            <div>
              <div id="prevTitle" style="font-size:14px;font-weight:700;color:<?= $text_color ?>;"><?= htmlspecialchars($reseller['company_name'] ?: 'Your Agency') ?></div>
              <div style="font-size:10px;color:<?= $text_color ?>; opacity: 0.6;">Reseller Panel</div>
            </div>
          </div>
          <div style="padding:20px;">
            <div id="prevBtn" style="background:<?= $primary ?>;color:#fff;border-radius:8px;padding:10px 20px;font-size:13px;font-weight:600;display:inline-block;margin-bottom:16px;">Primary Button</div>
            <div style="display:flex;gap:10px;margin-bottom:16px;">
              <div id="prevBadge" style="background:<?= $primary ?>20;color:<?= $primary ?>;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700;">Active</div>
              <div id="prevBadge2" style="background:<?= $secondary ?>20;color:<?= $secondary ?>;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700;">Premium</div>
            </div>
          </div>
        </div>
        <button type="submit" class="btn btn-primary btn-lg w-full" style="margin-top:20px;">💾 Save All Settings</button>
      </div>
    </div>
  </div>
</form>

<script>
function updatePreview() {
  const p = document.querySelector('[name="brand_color_primary"]').value;
  const s = document.querySelector('[name="brand_color_secondary"]').value;
  const bg = document.querySelector('[name="brand_sidebar_color"]').value;
  const txt = document.querySelector('[name="brand_text_color"]').value;
  
  document.getElementById('prevIcon').style.background    = p;
  document.getElementById('prevBtn').style.background     = p;
  document.getElementById('prevBadge').style.background   = p+'20';
  document.getElementById('prevBadge').style.color        = p;
  document.getElementById('prevBadge2').style.background  = s+'20';
  document.getElementById('prevBadge2').style.color       = s;
  document.getElementById('prevSidebar').style.background = bg;
  document.getElementById('prevTitle').style.color        = txt;
}

function previewLogo(input) {
  if (input.files && input.files[0]) {
    const reader = new FileReader();
    reader.onload = e => {
      const img = document.getElementById('logoPreview');
      img.src = e.target.result;
      img.style.display = 'block';
    };
    reader.readAsDataURL(input.files[0]);
  }
}
</script>

<?php resellerLayoutEnd(); ?>
