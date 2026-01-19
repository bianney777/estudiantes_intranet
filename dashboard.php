<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('Location: ' . appBaseUrl() . '/login.php');
    exit();
}

$user = $_SESSION['user'];
$db = getDB();

$username = (string)($user['username'] ?? $user['codigo'] ?? '');
$username = preg_replace('/[^a-zA-Z0-9_-]/', '', $username);

// Obtener sitios del estudiante
$stmt = $db->prepare("SELECT * FROM sitios_web WHERE estudiante_id = ? ORDER BY ultima_actualizacion DESC, id DESC");
$stmt->execute([(int)$user['id']]);
$sitios = $stmt->fetchAll(PDO::FETCH_ASSOC);
$sitio = !empty($sitios) ? $sitios[0] : null;

function countStudentFiles(string $username): int {
    $username = preg_replace('/[^a-zA-Z0-9_-]/', '', $username);
    if ($username === '') {
        return 0;
    }
    $root = STUDENTS_DIR . $username . DIRECTORY_SEPARATOR;
    if (!is_dir($root)) {
        return 0;
    }
    $count = 0;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                $count++;
                if ($count > 5000) {
                    break;
                }
            }
        }
    } catch (Throwable $e) {
        return 0;
    }
    return $count;
}

$totalVisitas = 0;
$totalSitios = count($sitios);
$totalPaginas = 0;
try {
    $stmt = $db->prepare('SELECT COALESCE(SUM(visitas),0) FROM sitios_web WHERE estudiante_id = ?');
    $stmt->execute([(int)$user['id']]);
    $totalVisitas = (int)$stmt->fetchColumn();

    $stmt = $db->prepare('SELECT COUNT(*) FROM paginas p JOIN sitios_web sw ON sw.id = p.sitio_id WHERE sw.estudiante_id = ?');
    $stmt->execute([(int)$user['id']]);
    $totalPaginas = (int)$stmt->fetchColumn();
} catch (Throwable $e) {
    $totalVisitas = 0;
    $totalPaginas = 0;
}

$stats = [
    'visitas' => $totalVisitas,
    'sitios' => $totalSitios,
    'paginas' => $totalPaginas,
    'archivos' => countStudentFiles($username),
];

$activeSiteId = $sitio ? (int)($sitio['id'] ?? 0) : 0;
$activeSlug = $sitio ? (string)($sitio['url_personalizada'] ?? '') : '';
$activePreview = $activeSlug !== '' ? ('s/' . urlencode($username) . '/' . urlencode($activeSlug)) : ('s/' . urlencode($username));
$activeEditor = $activeSiteId > 0 ? ('editor.php?site_id=' . $activeSiteId) : 'my-website.php?create=1';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Panel - <?php echo SITE_NAME; ?></title>
    <style>
        :root {
            --primary: #667eea;
            --secondary: #764ba2;
            --light: #f8f9fa;
            --dark: #343a40;
            --card: #ffffff;
            --muted: #667085;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fb;
            color: var(--dark);
        }
        
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
            width: 250px;
            background: white;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            padding: 30px 0;
        }
        
        .logo {
            padding: 0 20px 30px;
            border-bottom: 1px solid #eee;
            margin-bottom: 30px;
            font-size: 24px;
            font-weight: 700;
            color: var(--primary);
        }
        
        .nav-item {
            padding: 15px 30px;
            display: flex;
            align-items: center;
            gap: 15px;
            text-decoration: none;
            color: var(--dark);
            transition: all 0.3s;
            border-left: 3px solid transparent;
        }
        
        .nav-item:hover, .nav-item.active {
            background: #f8f9ff;
            color: var(--primary);
            border-left-color: var(--primary);
        }
        
        .nav-item span {
            font-size: 20px;
        }
        
        .user-info {
            position: absolute;
            bottom: 30px;
            left: 0;
            right: 0;
            padding: 0 20px;
        }
        
        .user-card {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: #f8f9ff;
            border-radius: 10px;
        }
        
        .avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: bold;
            overflow: hidden;
        }
        
        .main-content {
            margin-left: 250px;
            padding: 30px;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
        }
        
        .welcome h1 {
            font-size: 28px;
            margin-bottom: 10px;
            color: var(--dark);
        }
        
        .welcome p {
            color: #666;
        }

        .top-actions {
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            justify-content:flex-end;
        }

        .pill-btn {
            display:inline-flex;
            align-items:center;
            gap:10px;
            padding:10px 14px;
            border-radius:999px;
            border:1px solid rgba(0,0,0,0.08);
            background:white;
            color:var(--dark);
            text-decoration:none;
            font-weight:600;
            transition: transform .12s ease, box-shadow .2s ease;
        }

        .pill-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.08);
        }

        .pill-btn.primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border: none;
        }

        .app-footer {
            margin-top: 28px;
            padding: 14px 10px;
            text-align: center;
            color: var(--muted);
            font-size: 12px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            gap: 20px;
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        .stat-icon.visitas { background: #e3f2fd; color: #1976d2; }
        .stat-icon.archivos { background: #f3e5f5; color: #7b1fa2; }
        .stat-icon.sitios { background: #e8f5e9; color: #388e3c; }
        .stat-icon.paginas { background: #fff3cd; color: #856404; }
        
        .stat-info h3 {
            font-size: 32px;
            margin-bottom: 5px;
        }
        
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        
        .action-card {
            background: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }
        
        .action-card h3 {
            margin-bottom: 20px;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .action-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 25px;
            background: var(--primary);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
            margin-top: 10px;
        }
        
        .action-btn:hover {
            background: var(--secondary);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }
        
        .site-status {
            background: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }

        .sites-grid {
            display:grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap:14px;
            margin-top:14px;
        }

        .site-card {
            border: 1px solid rgba(0,0,0,0.06);
            background: #fff;
            border-radius: 14px;
            padding: 16px;
            display:flex;
            flex-direction:column;
            gap:10px;
        }

        .site-card h4 { margin:0; font-size:16px; }
        .site-meta { color: var(--muted); font-size: 13px; display:flex; gap:10px; flex-wrap:wrap; }
        .site-actions { display:flex; gap:10px; flex-wrap:wrap; }
        .mini-btn {
            padding:10px 12px;
            border-radius: 10px;
            border: 1px solid rgba(0,0,0,0.08);
            background: #fff;
            color: var(--dark);
            text-decoration:none;
            font-weight:600;
            font-size:13px;
        }
        .mini-btn.primary { background: var(--primary); border-color: var(--primary); color: #fff; }
        .mini-btn.dark { background: #111827; border-color: #111827; color: #fff; }
        
        .status-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
            margin-left: 10px;
        }
        
        .status-badge.borrador { background: #fff3cd; color: #856404; }
        .status-badge.publicado { background: #d4edda; color: #155724; }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 70px;
                padding: 20px 0;
            }
            
            .logo span:not(:first-child),
            .nav-item span:last-child,
            .user-card span:last-child {
                display: none;
            }
            
            .main-content {
                margin-left: 70px;
                padding: 20px;
            }
            
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 20px;
            }

            .top-actions {
                justify-content:flex-start;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="logo">E-WebLab</div>
        
        <a href="dashboard.php" class="nav-item active">
            <span>🏠</span>
            <span>Inicio</span>
        </a>
        
        <a href="my-website.php" class="nav-item">
            <span>💻</span>
            <span>Mi Sitio Web</span>
        </a>
        
        <a href="<?php echo htmlspecialchars($activeEditor); ?>" class="nav-item">
            <span>✏️</span>
            <span>Editor</span>
        </a>
        
        <a href="upload.php" class="nav-item">
            <span>📤</span>
            <span>Subir Archivos</span>
        </a>
        
        <a href="gallery.php" class="nav-item">
            <span>🖼️</span>
            <span>Galería</span>
        </a>
        
        <a href="profile.php" class="nav-item">
            <span>👤</span>
            <span>Mi Perfil</span>
        </a>
        
        <div class="user-info">
            <div class="user-card">
                <div class="avatar">
                    <?php if (!empty($user['avatar'])): ?>
                        <img src="<?php echo htmlspecialchars($user['avatar']); ?>" alt="Avatar" style="width:50px;height:50px;border-radius:50%;object-fit:cover;display:block;">
                    <?php else: ?>
                        <?php echo strtoupper(substr($user['nombre'], 0, 1)); ?>
                    <?php endif; ?>
                </div>
                <div>
                    <div style="font-weight: 600;"><?php echo $user['nombre']; ?></div>
                    <div style="font-size: 12px; color: #666;">Estudiante</div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <div class="welcome">
                <h1>¡Hola, <?php echo $user['nombre']; ?>! 👋</h1>
                <p>Bienvenido. Aquí gestionas tus sitios y páginas.</p>
            </div>
            <div class="top-actions">
                <a class="pill-btn primary" href="my-website.php?create=1"><span>➕</span> Nuevo sitio</a>
                <?php if ($activeSiteId > 0): ?>
                    <a class="pill-btn" href="<?php echo htmlspecialchars($activeEditor); ?>"><span>✏️</span> Editar</a>
                    <a class="pill-btn" href="<?php echo htmlspecialchars($activePreview); ?>" target="_blank"><span>👁️</span> Preview</a>
                    <a class="pill-btn" href="student-evaluation.php?site_id=<?php echo (int)$activeSiteId; ?>"><span>⭐</span> Evaluación</a>
                <?php endif; ?>
                <a class="pill-btn" href="logout.php"><span>🚪</span> Salir</a>
            </div>
        </div>
        
        <!-- Estadísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon visitas">👁️</div>
                <div class="stat-info">
                    <h3><?php echo $stats['visitas']; ?></h3>
                    <p>Visitas totales (todos tus sitios)</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon archivos">📁</div>
                <div class="stat-info">
                    <h3><?php echo $stats['archivos']; ?></h3>
                    <p>Archivos en tu carpeta</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon sitios">🌐</div>
                <div class="stat-info">
                    <h3><?php echo $stats['sitios']; ?></h3>
                    <p>Sitios creados</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon paginas">📄</div>
                <div class="stat-info">
                    <h3><?php echo $stats['paginas']; ?></h3>
                    <p>Páginas totales</p>
                </div>
            </div>
        </div>
        
        <!-- Acciones Rápidas -->
        <div class="quick-actions">
            <div class="action-card">
                <h3><span>🌐</span> Gestionar Sitios</h3>
                <p>Crea varios sitios independientes (como diferentes dominios) y administra sus páginas.</p>
                <a href="my-website.php" class="action-btn">
                    <span>🧩</span> Ir a Mis Sitios
                </a>
            </div>
            
            <div class="action-card">
                <h3><span>✏️</span> Editar Contenido</h3>
                <p>Edita páginas (HTML) y estilos (CSS/JS) de tu sitio activo.</p>
                <a href="<?php echo htmlspecialchars($activeEditor); ?>" class="action-btn">
                    <span>📝</span> Ir al Editor
                </a>
            </div>

            <div class="action-card">
                <h3><span>👁️</span> Compartir / Preview</h3>
                <p>Abre tu sitio y comparte el enlace con quien quieras.</p>
                <?php if ($activeSiteId > 0): ?>
                    <a href="<?php echo htmlspecialchars($activePreview); ?>" target="_blank" class="action-btn">
                        <span>🔗</span> Abrir Preview
                    </a>
                <?php else: ?>
                    <a href="my-website.php?create=1" class="action-btn">
                        <span>➕</span> Crear primer sitio
                    </a>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Estado del Sitio -->
        <div class="site-status">
            <h3>Tus sitios</h3>
            <?php if (empty($sitios)): ?>
                <div style="margin-top: 20px; padding: 22px; text-align: center; background: #f8f9fa; border-radius: 12px;">
                    <p style="margin-bottom: 12px;">Aún no has creado tu primer sitio.</p>
                    <a href="my-website.php?create=1" class="action-btn">
                        <span>🚀</span> Crear mi primer sitio
                    </a>
                </div>
            <?php else: ?>
                <div class="sites-grid">
                    <?php foreach ($sitios as $s): ?>
                        <?php
                            $sid = (int)($s['id'] ?? 0);
                            $slug = (string)($s['url_personalizada'] ?? '');
                            $preview = $slug !== '' ? ('s/' . urlencode($username) . '/' . urlencode($slug)) : ('s/' . urlencode($username));
                            $editor = $sid > 0 ? ('editor.php?site_id=' . $sid) : 'editor.php';
                            $estado = (string)($s['estado'] ?? 'borrador');
                        ?>
                        <div class="site-card">
                            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
                                <h4><?php echo htmlspecialchars((string)($s['nombre_sitio'] ?? 'Sitio')); ?></h4>
                                <span class="status-badge <?php echo htmlspecialchars($estado); ?>"><?php echo htmlspecialchars(ucfirst($estado)); ?></span>
                            </div>
                            <div class="site-meta">
                                <span>slug: <strong><?php echo htmlspecialchars($slug); ?></strong></span>
                                <span>visitas: <strong><?php echo (int)($s['visitas'] ?? 0); ?></strong></span>
                            </div>
                            <div class="site-actions">
                                <a class="mini-btn primary" href="<?php echo htmlspecialchars($editor); ?>">✏️ Editar</a>
                                <a class="mini-btn" href="<?php echo htmlspecialchars($preview); ?>" target="_blank">👁️ Preview</a>
                                <a class="mini-btn dark" href="my-website.php">⚙️ Gestionar</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="app-footer">Elaborado por Biamney</div>
    </div>
</body>
</html>