<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

$auth = new Auth();
$isLogged = $auth->isLoggedIn();
$user = $_SESSION['user'] ?? null;

$db = null;
try {
    $db = getDB();
} catch (Throwable $e) {
    $db = null;
}

$studentCount = null;
$siteCount = null;
$pageCount = null;
$featuredSites = [];

if ($db instanceof PDO) {
    try {
        $studentCount = (int)$db->query('SELECT COUNT(*) FROM estudiantes')->fetchColumn();
        $siteCount = (int)$db->query('SELECT COUNT(*) FROM sitios_web')->fetchColumn();
        $pageCount = (int)$db->query('SELECT COUNT(*) FROM paginas')->fetchColumn();

        $stmt = $db->query(
            "SELECT sw.id, sw.nombre_sitio, sw.url_personalizada, sw.visitas, sw.ultima_actualizacion,
                    e.codigo, e.nombre, e.apellido, e.carrera
             FROM sitios_web sw
             JOIN estudiantes e ON e.id = sw.estudiante_id
             ORDER BY sw.ultima_actualizacion DESC, sw.id DESC
             LIMIT 6"
        );
        $featuredSites = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        $featuredSites = [];
    }
}

$ejemplos = [
    ['title' => 'Mi Portafolio Digital', 'student' => 'Ana García', 'career' => 'Diseño Gráfico', 'views' => '1,245'],
    ['title' => 'Blog de Tecnología', 'student' => 'Carlos López', 'career' => 'Ing. Sistemas', 'views' => '2,103'],
    ['title' => 'Galería Fotográfica', 'student' => 'María Rodríguez', 'career' => 'Comunicación', 'views' => '3,458'],
    ['title' => 'Tienda Online Artesanal', 'student' => 'Pedro Martínez', 'career' => 'Administración', 'views' => '1,876'],
    ['title' => 'Revista Digital Universitaria', 'student' => 'Lucía Fernández', 'career' => 'Periodismo', 'views' => '4,210'],
    ['title' => 'Plataforma de Tutorías', 'student' => 'Diego Ramírez', 'career' => 'Pedagogía', 'views' => '3,112']
];

function fmtCompact(?int $n): string {
    if ($n === null) return '—';
    if ($n >= 1000000) return round($n / 1000000, 1) . 'M+';
    if ($n >= 1000) return round($n / 1000, 1) . 'K+';
    return (string)$n;
}

function monthsSince(?string $iso): string {
    if (!$iso) return '';
    try {
        $dt = new DateTime($iso);
        $now = new DateTime('now');
        $diff = $dt->diff($now);
        $months = ((int)$diff->y * 12) + (int)$diff->m;
        if ($months <= 0) return 'reciente';
        return $months . ' mes' . ($months === 1 ? '' : 'es');
    } catch (Throwable $e) {
        return '';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - Plataforma para estudiantes</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #667eea;
            --primary-dark: #5a67d8;
            --secondary: #764ba2;
            --accent: #ff6b6b;
            --light: #f8f9fa;
            --dark: #2d3748;
            --success: #10b981;
            --card-bg: rgba(255, 255, 255, 0.95);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            background-attachment: fixed;
            min-height: 100vh;
            color: var(--dark);
            line-height: 1.6;
        }

        .topbar {
            position: sticky;
            top: 0;
            z-index: 50;
            background: rgba(10, 14, 30, 0.35);
            backdrop-filter: blur(14px);
            border-bottom: 1px solid rgba(255,255,255,0.12);
        }

        .topbar-inner {
            max-width: 1400px;
            margin: 0 auto;
            padding: 12px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            color: white;
            text-decoration: none;
            font-weight: 800;
            letter-spacing: -0.5px;
        }

        .brand-badge {
            width: 34px;
            height: 34px;
            border-radius: 12px;
            background: rgba(255,255,255,0.14);
            display: grid;
            place-items: center;
        }

        .navlinks {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .navlinks a {
            color: rgba(255,255,255,0.92);
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            padding: 10px 12px;
            border-radius: 999px;
            border: 1px solid rgba(255,255,255,0.18);
            background: rgba(255,255,255,0.08);
            transition: transform .12s ease, background .2s ease;
        }

        .navlinks a:hover {
            background: rgba(255,255,255,0.14);
            transform: translateY(-1px);
        }
        
        .hero {
            max-width: 1400px;
            margin: 0 auto;
            padding: 80px 20px 60px;
        }
        
        .logo-container {
            text-align: center;
            margin-bottom: 40px;
        }
        
        .logo {
            font-size: 4.5rem;
            font-weight: 800;
            margin-bottom: 10px;
            display: inline-block;
            background: linear-gradient(90deg, #ff6b6b, #4ecdc4, #45b7d1, #96ceb4, #ffeaa7, #ff9ff3);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            letter-spacing: -2px;
            position: relative;
        }
        
        .logo-subtitle {
            font-size: 1.4rem;
            color: white;
            opacity: 0.9;
            margin-top: 10px;
        }
        
        .tagline {
            text-align: center;
            font-size: 1.8rem;
            color: white;
            margin-bottom: 50px;
            padding: 0 20px;
            font-weight: 300;
        }
        
        .stats {
            display: flex;
            justify-content: center;
            gap: 30px;
            flex-wrap: wrap;
            margin-bottom: 60px;
        }
        
        .stat-item {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 25px 35px;
            text-align: center;
            min-width: 180px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: transform 0.3s, background 0.3s;
        }
        
        .stat-item:hover {
            transform: translateY(-5px);
            background: rgba(255, 255, 255, 0.25);
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: white;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 1rem;
            color: rgba(255, 255, 255, 0.9);
        }
        
        .features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 30px;
            margin: 60px 0;
        }
        
        .feature {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 35px 30px;
            text-align: center;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
        }
        
        .feature::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
        }
        
        .feature:hover {
            transform: translateY(-15px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }
        
        .feature-icon {
            font-size: 3rem;
            margin-bottom: 25px;
            display: inline-block;
            width: 80px;
            height: 80px;
            line-height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
        }
        
        .feature h3 {
            font-size: 1.5rem;
            margin-bottom: 15px;
            color: var(--dark);
        }
        
        .feature p {
            color: #666;
            font-size: 1rem;
        }
        
        .cta-buttons {
            display: flex;
            gap: 20px;
            justify-content: center;
            margin: 60px 0;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 18px 45px;
            border-radius: 50px;
            font-size: 1.1rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 12px;
            border: none;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }
        
        .btn::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 5px;
            height: 5px;
            background: rgba(255, 255, 255, 0.5);
            opacity: 0;
            border-radius: 100%;
            transform: scale(1, 1) translate(-50%);
            transform-origin: 50% 50%;
        }
        
        .btn:focus:not(:active)::after {
            animation: ripple 1s ease-out;
        }
        
        .btn-primary {
            background: white;
            color: var(--primary-dark);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }
        
        .btn-primary:hover {
            background: #f8f9fa;
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.5);
        }
        
        .btn-secondary {
            background: transparent;
            color: white;
            border: 2px solid white;
            backdrop-filter: blur(5px);
        }
        
        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        }

        .btn-ghost {
            background: rgba(255,255,255,0.10);
            color: white;
            border: 1px solid rgba(255,255,255,0.25);
        }

        .btn-ghost:hover {
            background: rgba(255,255,255,0.16);
            transform: translateY(-3px);
        }
        
        .gallery-preview {
            margin-top: 80px;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 30px;
            padding: 50px 40px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.1);
        }
        
        .section-title {
            text-align: center;
            font-size: 2.2rem;
            color: var(--dark);
            margin-bottom: 40px;
            position: relative;
        }
        
        .section-title::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 50%;
            transform: translateX(-50%);
            width: 80px;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            border-radius: 2px;
        }
        
        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 30px;
            margin-top: 40px;
        }
        
        .site-card {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            position: relative;
        }
        
        .site-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }
        
        .site-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
            z-index: 1;
        }
        
        .site-preview {
            height: 200px;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--dark);
            font-size: 1.1rem;
            position: relative;
            overflow: hidden;
        }
        
        .site-preview::before {
            content: '';
            position: absolute;
            width: 200%;
            height: 200%;
            background: linear-gradient(
                45deg,
                transparent 30%,
                rgba(255, 255, 255, 0.1) 50%,
                transparent 70%
            );
            animation: shine 3s infinite linear;
        }
        
        .site-info {
            padding: 25px;
        }
        
        .site-info h4 {
            margin-bottom: 10px;
            color: var(--dark);
            font-size: 1.3rem;
        }
        
        .student-name {
            color: var(--primary);
            font-weight: 600;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .site-meta {
            display: flex;
            justify-content: space-between;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }
        
        .meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
            color: #666;
            font-size: 0.9rem;
        }
        
        .view-all {
            text-align: center;
            margin-top: 40px;
        }
        
        .view-all-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 30px;
            background: var(--primary);
            color: white;
            text-decoration: none;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .view-all-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        
        footer {
            text-align: center;
            padding: 40px 20px;
            color: white;
            margin-top: 80px;
            font-size: 0.9rem;
            opacity: 0.8;
        }
        
        @keyframes shine {
            0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
            100% { transform: translateX(100%) translateY(100%) rotate(45deg); }
        }
        
        @keyframes ripple {
            0% { transform: scale(0, 0); opacity: 1; }
            20% { transform: scale(25, 25); opacity: 1; }
            100% { opacity: 0; transform: scale(40, 40); }
        }
        
        @media (max-width: 768px) {
            .logo { font-size: 3rem; }
            .tagline { font-size: 1.4rem; }
            .cta-buttons { flex-direction: column; align-items: center; }
            .btn { width: 100%; max-width: 300px; justify-content: center; }
            .stats { gap: 15px; }
            .stat-item { min-width: 140px; padding: 20px; }
            .stat-number { font-size: 2rem; }
            .gallery-grid { grid-template-columns: 1fr; }
            .gallery-preview { padding: 30px 20px; }
        }
        
        @media (max-width: 480px) {
            .hero { padding: 60px 15px 40px; }
            .logo { font-size: 2.5rem; }
            .tagline { font-size: 1.2rem; }
            .features { grid-template-columns: 1fr; }
            .stat-item { min-width: 120px; padding: 15px; }
        }
    </style>
</head>
<body>
    <div class="topbar">
        <div class="topbar-inner">
            <a class="brand" href="index.php">
                <span class="brand-badge"><i class="fas fa-code" style="color:white"></i></span>
                <span>EWEBLAB</span>
            </a>
            <div class="navlinks">
                <a href="#features"><i class="fas fa-wand-magic-sparkles"></i> Funciones</a>
                <a href="#projects"><i class="fas fa-images"></i> Proyectos</a>
                    <a href="teacher-login.php"><i class="fas fa-chalkboard-user"></i> Acceso docente</a>
                <?php if ($isLogged): ?>
                    <a href="dashboard.php"><i class="fas fa-gauge"></i> Panel</a>
                    <a href="my-website.php"><i class="fas fa-globe"></i> Mis Sitios</a>
                <?php else: ?>
                    <a href="login.php"><i class="fas fa-sign-in-alt"></i> Entrar</a>
                    <a href="register.php"><i class="fas fa-user-plus"></i> Crear cuenta</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="hero">
        <div class="logo-container">
            <div class="logo">EWEBLAB</div>
            <div class="logo-subtitle">Plataforma de desarrollo web para estudiantes</div>
        </div>
        
        <h1 class="tagline">Crea, diseña y publica tu sitio web como estudiante</h1>
        
        <div class="stats">
            <div class="stat-item">
                <div class="stat-number" data-value="<?php echo htmlspecialchars((string)($studentCount ?? '')); ?>"><?php echo htmlspecialchars(fmtCompact($studentCount)); ?></div>
                <div class="stat-label">Estudiantes activos</div>
            </div>
            <div class="stat-item">
                <div class="stat-number" data-value="<?php echo htmlspecialchars((string)($siteCount ?? '')); ?>"><?php echo htmlspecialchars(fmtCompact($siteCount)); ?></div>
                <div class="stat-label">Sitios creados</div>
            </div>
            <div class="stat-item">
                <div class="stat-number" data-value="<?php echo htmlspecialchars((string)($pageCount ?? '')); ?>"><?php echo htmlspecialchars(fmtCompact($pageCount)); ?></div>
                <div class="stat-label">Páginas publicadas</div>
            </div>
        </div>
        
        <div id="features" class="features">
            <div class="feature">
                <div class="feature-icon"><i class="fas fa-laptop-code"></i></div>
                <h3>Editor Online</h3>
                <p>Edita tu código HTML, CSS y JavaScript directamente desde el navegador con resaltado de sintaxis y auto-completado</p>
            </div>
            
            <div class="feature">
                <div class="feature-icon"><i class="fas fa-rocket"></i></div>
                <h3>Publicación Instantánea</h3>
                <p>Publica tu sitio con un solo clic y compártelo con tus compañeros mediante un enlace único</p>
            </div>
            
            <div class="feature">
                <div class="feature-icon"><i class="fas fa-palette"></i></div>
                <h3>Plantillas Profesionales</h3>
                <p>Comienza con plantillas diseñadas para portafolios, blogs, tiendas online y más</p>
            </div>
            
            <div class="feature">
                <div class="feature-icon"><i class="fas fa-users"></i></div>
                <h3>Comunidad Estudiantil</h3>
                <p>Comparte y recibe feedback de otros estudiantes y profesores en nuestra plataforma colaborativa</p>
            </div>
        </div>
        
        <div class="cta-buttons">
            <?php if ($isLogged): ?>
                <a href="dashboard.php" class="btn btn-primary">
                    <i class="fas fa-gauge"></i> Ir a mi panel
                </a>
                <a href="my-website.php" class="btn btn-secondary">
                    <i class="fas fa-globe"></i> Crear / gestionar sitios
                </a>
                <a href="editor.php" class="btn btn-ghost">
                    <i class="fas fa-pen-to-square"></i> Abrir editor
                </a>
            <?php else: ?>
                <a href="register.php" class="btn btn-primary">
                    <i class="fas fa-user-plus"></i> Crear Cuenta Gratis
                </a>
                <a href="login.php" class="btn btn-secondary">
                    <i class="fas fa-sign-in-alt"></i> Iniciar Sesión
                </a>
                <a href="templates/portfolio/index.html" class="btn btn-ghost" target="_blank">
                    <i class="fas fa-layer-group"></i> Ver plantillas
                </a>
            <?php endif; ?>
        </div>
        
        <div class="gallery-preview">
            <h2 id="projects" class="section-title">Sitios creados por estudiantes</h2>
            <div class="gallery-grid">
                <?php if (!empty($featuredSites)): ?>
                    <?php foreach ($featuredSites as $sitio): ?>
                    <?php
                        $codigo = (string)($sitio['codigo'] ?? '');
                        $slug = (string)($sitio['url_personalizada'] ?? '');
                        $link = 's/' . rawurlencode($codigo);
                        if ($slug !== '') {
                            $link .= '/' . rawurlencode($slug);
                        }
                        $studentName = trim((string)($sitio['nombre'] ?? '') . ' ' . (string)($sitio['apellido'] ?? ''));
                        if ($studentName === '') {
                            $studentName = $codigo;
                        }
                    ?>
                    <a class="site-card" href="<?php echo htmlspecialchars($link); ?>" target="_blank" style="text-decoration:none;color:inherit;">
                    <div class="site-preview">
                        <i class="fas fa-laptop" style="font-size: 3rem; color: var(--primary); margin-bottom: 10px;"></i>
                        <div style="text-align: center;">
                            <div style="font-weight: 600; margin-bottom: 5px;"><?php echo htmlspecialchars((string)$sitio['nombre_sitio']); ?></div>
                            <div style="font-size: 0.9rem;">Abrir sitio en nueva pestaña</div>
                        </div>
                    </div>
                    <div class="site-info">
                        <h4><?php echo htmlspecialchars((string)$sitio['nombre_sitio']); ?></h4>
                        <div class="student-name">
                            <i class="fas fa-user-graduate"></i> por <?php echo htmlspecialchars($studentName); ?>
                        </div>
                        <div style="color: #666; font-size: 0.9rem; margin-bottom: 10px;">
                            <i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars((string)($sitio['carrera'] ?? '')); ?>
                        </div>
                        <div class="site-meta">
                            <div class="meta-item">
                                <i class="fas fa-eye"></i> <?php echo number_format((int)($sitio['visitas'] ?? 0)); ?> visitas
                            </div>
                            <div class="meta-item">
                                <i class="far fa-calendar-alt"></i> <?php echo htmlspecialchars(monthsSince((string)($sitio['ultima_actualizacion'] ?? ''))); ?>
                            </div>
                        </div>
                    </div>
                    </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?php foreach ($ejemplos as $sitio): ?>
                    <div class="site-card">
                        <div class="site-preview">
                            <i class="fas fa-laptop" style="font-size: 3rem; color: var(--primary); margin-bottom: 10px;"></i>
                            <div style="text-align: center;">
                                <div style="font-weight: 600; margin-bottom: 5px;"><?php echo htmlspecialchars($sitio['title']); ?></div>
                                <div style="font-size: 0.9rem;">Vista previa del sitio</div>
                            </div>
                        </div>
                        <div class="site-info">
                            <h4><?php echo htmlspecialchars($sitio['title']); ?></h4>
                            <div class="student-name">
                                <i class="fas fa-user-graduate"></i> por <?php echo htmlspecialchars($sitio['student']); ?>
                            </div>
                            <div style="color: #666; font-size: 0.9rem; margin-bottom: 10px;">
                                <i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($sitio['career']); ?>
                            </div>
                            <div class="site-meta">
                                <div class="meta-item">
                                    <i class="fas fa-eye"></i> <?php echo htmlspecialchars($sitio['views']); ?> visitas
                                </div>
                                <div class="meta-item">
                                    <i class="far fa-calendar-alt"></i> ejemplo
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <div class="view-all">
                <a href="gallery.php" class="view-all-btn">
                    <i class="fas fa-images"></i> Ver todos los proyectos
                </a>
            </div>
        </div>
    </div>
    
    <footer>
        <p>&copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?>. Todos los derechos reservados.</p>
        <p style="margin-top: 8px; font-size: 0.9rem; opacity: 0.95;">Elaborado por Biamney</p>
        <p style="margin-top: 10px; font-size: 0.8rem;">
            <a href="#" style="color: white; margin: 0 10px;">Términos de uso</a> | 
            <a href="#" style="color: white; margin: 0 10px;">Privacidad</a> | 
            <a href="#" style="color: white; margin: 0 10px;">Contacto</a>
        </p>
    </footer>
    
    <script>
        // Efecto de animación al hacer scroll
        document.addEventListener('DOMContentLoaded', function() {
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };
            
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                    }
                });
            }, observerOptions);
            
            // Animar elementos al aparecer
            const animatedElements = document.querySelectorAll('.feature, .site-card, .stat-item');
            animatedElements.forEach(el => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(20px)';
                el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                observer.observe(el);
            });
            
            // Contador robusto (usa data-value si existe)
            const statNumbers = document.querySelectorAll('.stat-number');
            const fmt = (n) => {
                try {
                    return new Intl.NumberFormat('es', { notation: 'compact', compactDisplay: 'short' }).format(n) + '+';
                } catch (e) {
                    return n.toString();
                }
            };

            statNumbers.forEach(stat => {
                const raw = (stat.getAttribute('data-value') || '').trim();
                if (!raw || isNaN(Number(raw))) {
                    return;
                }
                const target = Math.max(0, Math.floor(Number(raw)));
                let current = 0;
                const steps = 40;
                const inc = Math.max(1, Math.ceil(target / steps));
                const timer = setInterval(() => {
                    current += inc;
                    if (current >= target) {
                        current = target;
                        clearInterval(timer);
                    }
                    stat.textContent = fmt(current);
                }, 25);
            });
        });
    </script>
</body>
</html>