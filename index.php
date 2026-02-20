<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

$username = $_SESSION['user']['username'] ?? '';
$full_name = $_SESSION['user']['full_name'] ?? '';
$role = strtolower(trim($_SESSION['user']['role'] ?? ''));

// ==========================
// البحث العام (مفصول عن الصلاحيات)
// ==========================
$search = $_GET['search'] ?? '';

if ($search !== '') {
    $like = "%$search%";

    // فحص هل البحث يطابق جهاز أو سيريل
    $checkDev = $conn->prepare("
        SELECT id 
        FROM devices 
        WHERE device_name LIKE ? 
           OR serial_number LIKE ?
        LIMIT 1
    ");
    $checkDev->bind_param("ss", $like, $like);
    $checkDev->execute();
    $devRes = $checkDev->get_result();

    if ($devRes->num_rows > 0) {
        // إعادة توجيه لصفحة نتائج الأجهزة
        header("Location: search_devices.php?q=" . urlencode($search));
        exit;
    }
}

// ==========================
// جلب الأقسام حسب الصلاحية فقط لتحديد ما يرى المستخدم
// ==========================
if ($role === 'technician') {
    // التقني يرى كل الأقسام
    $sql = "
        SELECT 
            d.id,
            d.name,
            d.head_name,
            d.head_phone,
            COUNT(dev.id) AS devices_count
        FROM departments d
        LEFT JOIN devices dev ON d.id = dev.department_id
        GROUP BY d.id
        ORDER BY d.name ASC
    ";
    $stmt = $conn->prepare($sql);
} elseif ($role === 'supervisor') {
    // المشرف يرى فقط الأقسام التابعة له
    $sql = "
        SELECT 
            d.id,
            d.name,
            d.head_name,
            d.head_phone,
            COUNT(dev.id) AS devices_count
        FROM departments d
        LEFT JOIN devices dev ON d.id = dev.department_id
        WHERE TRIM(d.supervisor) = TRIM(?)
        GROUP BY d.id
        ORDER BY d.name ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username);
} else {
    die('Role not recognized.');
}

$stmt->execute();
$result = $stmt->get_result();
$departments = $result->fetch_all(MYSQLI_ASSOC);

// ==========================
// جلب عدد الطوارئ المفتوحة والإصلاحات
// ==========================
$emergency_stmt = $conn->query("
    SELECT status, COUNT(*) AS cnt 
    FROM emergency_faults 
    WHERE status IN ('open','fixed') 
    GROUP BY status
");
$em_counts = ['open'=>0,'fixed'=>0];
while($row = $emergency_stmt->fetch_assoc()){
    $em_counts[$row['status']] = (int)$row['cnt'];
}
$open_count = $em_counts['open'];
$fixed_count = $em_counts['fixed'];

// ==========================
// تحقق من وجود أقسام لمشرف
// ==========================
if ($role === 'supervisor' && empty($departments)) {
    echo "
    <div style='margin:100px auto;max-width:500px;padding:30px;
         background:#fff;border-radius:15px;text-align:center;
         font-family:Segoe UI;box-shadow:0 0 20px rgba(0,0,0,.15)'>
        <h2 style='color:#b91c1c;'>⚠️ غير مصرح</h2>
        <p>عذرًا، أنت لست مشرفًا على أي قسم</p>
        <a href='logout.php'>تسجيل الخروج</a>
    </div>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>🏥 Department Management</title>
<style>
body { margin:0; font-family:'Segoe UI', Tahoma, Arial, sans-serif; font-weight:600; background: linear-gradient(135deg,#eef2ff,#f8fafc); color:#1e3a8a; }
nav ul { list-style:none; margin:0; padding:12px 25px; display:flex; justify-content:space-between; align-items:center; background: rgba(219,234,254,0.75); backdrop-filter: blur(12px); border-bottom:1px solid rgba(30,64,175,0.2); border-radius:10px; }
nav ul li { display:inline-block; }
nav a { text-decoration:none; color:#1e3a8a; font-weight:600; padding:8px 16px; border-radius:10px; transition: all .3s ease; position:relative; }
nav a:hover { background: rgba(37,99,235,0.15); }

.container { max-width:1200px; margin:40px auto; padding:20px; }
.card { background: rgba(255,255,255,0.75); backdrop-filter: blur(14px); padding:30px; border-radius:20px; box-shadow:0 0 25px rgba(0,0,0,0.08); }
.page-title { font-size:24px; margin-bottom:25px; color:#1e40af; text-align:center; }

.controls { display:flex; flex-wrap:wrap; gap:12px; align-items:center; justify-content:space-between; margin-bottom:25px; }
.controls-left { display:flex; gap:10px; }
.controls .btn { border-radius:10px; padding:6px 14px; font-size:13px; font-weight:bold; border:none; cursor:pointer; text-decoration:none; transition: all .3s ease; color:#1e3a8a; background: linear-gradient(135deg,#ffffff,#dbeafe); }
.controls .btn:hover { background:#c7d2fe; transform:translateY(-1px); }
.search-form { display:flex; gap:6px; }
.search-form input { border-radius:10px; border:1px solid #2563eb; padding:6px 10px; font-size:13px; background: rgba(255,255,255,0.9); color:#1e3a8a; }
.search-form button { border-radius:10px; padding:6px 10px; background:#dbeafe; color:#1e3a8a; border:none; cursor:pointer; }

.table-wrapper { overflow-x:auto; }
table { width:100%; border-collapse:collapse; margin-top:10px; background: rgba(255,255,255,0.75); backdrop-filter: blur(12px); border-radius:14px; overflow:hidden; box-shadow:0 0 18px rgba(0,0,0,0.08); text-align:center; font-size:13px; color:#1e3a8a; }
thead { background: rgba(219,234,254,0.8); color:#1e40af; }
th, td { padding:10px; border-bottom:1px solid rgba(30,64,175,0.2); text-align:left; }
tbody tr:nth-child(even) { background: rgba(219,234,254,0.2);}
tbody tr:hover { background: rgba(219,234,254,0.35); }

.ops { display:flex; flex-wrap:wrap; gap:6px; justify-content:center; }
.ops a { padding:5px 10px; border-radius:8px; font-size:12px; font-weight:bold; text-decoration:none; transition: background .3s; margin:0 2px; display:flex; flex-direction:column; align-items:center; }
.ops a span { font-size:10px; font-weight:400; color:#1e40af; margin-top:2px; }
.ops .btn-edit { background:#fef9c3; color:#92400e; }
.ops .btn-edit:hover { background:#fef08a; }
.ops .btn-delete { background:#fee2e2; color:#7f1d1d; }
.ops .btn-delete:hover { background:#fecaca; }
.ops .btn-devices { background:#dbeafe; color:#1e3a8a; }
.ops .btn-devices:hover { background:#93c5fd; }

/* ===== جرس الطوارئ شفاف ===== */
.emergency-bell {
    display:inline-block;
    position:relative;
    font-size:22px;   /* حجم الجرس */
    background:none;
    width:auto;
    height:auto;
    line-height:normal;
    border-radius:0;
    padding:0;
}
.emergency-bell span {
    position:absolute;
    top:-8px;
    right:-10px;
    font-size:12px;
    font-weight:bold;
}

.open-count { color:red; }
.fixed-count { color:orange; }

@media(max-width:768px){
    .controls{flex-direction:column;align-items:stretch;} 
    .controls-left{justify-content:center;} 
    .search-form{justify-content:center;}
}
</style>
</head>
<body>

<nav>
<ul>
<li><strong>👤 <?= htmlspecialchars($full_name ?? '') ?></strong></li>
<li><a href="index.php">🏠 Home</a></li>
<?php if ($role === 'technician'): ?>
<li><a href="reports.php">📊 Reports</a></li>
<li><a href="backup.php" class="btn" target="_blank" onclick="alert('🔹 Backup will start downloading shortly!');">💾 Backup</a></li>
<?php endif; ?>

<li>
<a href="emergency_list.php?filter=open" class="emergency-bell">🔔
    <span class="open-count"><?= $open_count ?></span>
</a>
</li>
<li>
<a href="emergency_list.php?filter=repair" class="emergency-bell">🔔
    <span class="fixed-count"><?= $fixed_count ?></span>
</a>
</li>

<li><a href="logout.php">🚪 Log Out</a></li>
</ul>
</nav>

<div class="container">
<div class="card">
<h1 class="page-title">🏥 Department Management</h1>

<div class="controls">
<?php if ($role === 'technician'): ?>
<div class="controls-left">
    <a href="add_department.php" class="btn">➕ Add Department</a>
</div>
<div style="display:flex; align-items:center; gap:6px;">
    <form action="import_departments_excel.php" method="post" enctype="multipart/form-data" style="display:flex; align-items:center; gap:6px;">
        <strong style="color:#1e40af; font-size:13px;">📥 Import Excel</strong>
        <input type="file" name="excel_file" accept=".xls,.xlsx" required style="padding:4px 6px; border-radius:8px; border:1px solid #2563eb; background:#fff;">
        <button type="submit" name="import" class="btn" style="padding:6px 10px;">⬆️ Upload</button>
    </form>
</div>
<?php endif; ?>

<form method="get" class="search-form" dir="ltr">
    <input type="text" name="search" placeholder="Search anything" value="<?= htmlspecialchars($search ?? '') ?>">
    <button type="submit" class="btn">🔎 Search</button>
</form>
</div>

<div class="table-wrapper">
<table dir="ltr">
<thead>
<tr>
<th>Department Name</th>
<th>Supervisor</th>
<th>Supervisor Phone</th>
<th>Devices Count</th>
<th>Operations</th>
</tr>
</thead>
<tbody>
<?php if(empty($departments)): ?>
<tr><td colspan="5" style="text-align:center;">No departments found</td></tr>
<?php else: ?>
<?php foreach($departments as $dept): ?>
<tr>
<td><?= htmlspecialchars($dept['name'] ?? '') ?></td>
<td><?= htmlspecialchars($dept['head_name'] ?? '') ?></td>
<td><?= htmlspecialchars($dept['head_phone'] ?? '') ?></td>
<td><?= (int)$dept['devices_count'] ?></td>
<td class="ops">
<a href="department_devices.php?id=<?= $dept['id'] ?>" class="btn-devices">📋<span>devices</span></a>
<?php if ($role === 'technician'): ?>
<a href="edit_department.php?id=<?= $dept['id'] ?>" class="btn-edit">✏️<span>edit</span></a>
<a href="delete_department.php?id=<?= $dept['id'] ?>" class="btn-delete" onclick="return confirm('Are you sure?')">🗑<span>delete</span></a>
<?php elseif ($role === 'supervisor'): ?>
<a href="edit_department.php?id=<?= $dept['id'] ?>" class="btn-edit">✏️<span>edit</span></a>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
</div>

<script>
// لتحديث العدادات كل 20 ثانية
async function updateCounts(){
    const res = await fetch('ajax_emergencies.php');
    if(res.ok){
        const data = await res.json();
        document.querySelector('.open-count').innerText = data.open_count;
        document.querySelector('.fixed-count').innerText = data.fixed_count;
    }
}
setInterval(updateCounts,20000);
updateCounts();
</script>

</body>
</html>
