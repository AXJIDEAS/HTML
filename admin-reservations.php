<?php
declare(strict_types=1);

/**
 * ========================================================
 * Hotel Ombara - Panel de Gestión de Reservas (MySQL)
 * ========================================================
 */

require_once __DIR__ . '/db-config.php';

$error = null;
$reservations = [];
$memberships = [];
$stats = [
    'total_reservations' => 0,
    'total_revenue'      => 0.0,
    'active_members'     => 0,
];

try {
    $pdo = getDbConnection();

    // Consultar Reservas
    $resStmt = $pdo->query("SELECT * FROM `reservations` ORDER BY `id` DESC LIMIT 100");
    $reservations = $resStmt->fetchAll();

    // Consultar Membresías
    $memStmt = $pdo->query("SELECT * FROM `memberships` ORDER BY `id` DESC LIMIT 50");
    $memberships = $memStmt->fetchAll();

    // Estadísticas
    $stats['total_reservations'] = count($reservations);
    foreach ($reservations as $r) {
        $stats['total_revenue'] += (float) ($r['total_price'] ?? 0);
    }
    $stats['active_members'] = count($memberships);

} catch (PDOException $e) {
    $error = "Error de conexión con la base de datos MySQL: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Reservas - Hotel Ombara</title>
    <link rel="icon" type="image/png" href="assets/ico.png">
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        body { background-color: #f7f9f8; color: #2c3a2f; }
        .admin-nav { background: #2c3a2f; padding: 1rem 2rem; color: #fff; display: flex; justify-content: space-between; align-items: center; }
        .admin-nav h1 { font-size: 1.3rem; margin: 0; font-family: inherit; letter-spacing: 1px; }
        .stat-card { background: #fff; border-radius: 8px; padding: 1.5rem; border: 1px solid rgba(44,58,47,0.1); box-shadow: 0 4px 15px rgba(0,0,0,0.02); }
        .stat-number { font-size: 1.8rem; font-weight: 700; color: #b59e7d; }
        .table-wrap { background: #fff; border-radius: 8px; padding: 1.5rem; border: 1px solid rgba(44,58,47,0.1); margin-top: 1.5rem; box-shadow: 0 4px 15px rgba(0,0,0,0.02); }
        .badge-confirmed { background: #e8f5e9; color: #2e7d32; font-weight: 600; padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; }
        .badge-pending { background: #fff3e0; color: #ef6c00; font-weight: 600; padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; }
        .badge-code { font-family: monospace; font-size: 0.9rem; background: #eef2f0; padding: 3px 6px; border-radius: 3px; font-weight: bold; color: #2c3a2f; }
    </style>
</head>
<body>

    <nav class="admin-nav">
        <div>
            <img src="assets/logo-white.png" alt="Ombara" height="30" class="me-2 align-middle">
            <span class="align-middle fw-bold">| Panel de Administración de Reservas (MySQL)</span>
        </div>
        <div>
            <a href="reservasi.html" class="btn btn-sm btn-outline-light me-2"><i class="fa-solid fa-plus me-1"></i> Nueva Reserva</a>
            <a href="index_4.html" class="btn btn-sm btn-outline-light"><i class="fa-solid fa-house me-1"></i> Ir a la Web</a>
        </div>
    </nav>

    <div class="container-fluid py-4 px-lg-5">
        <?php if ($error): ?>
            <div class="alert alert-danger" role="alert">
                <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo htmlspecialchars($error); ?>
                <p class="small mt-2 mb-0">Verifica que tu servidor MySQL (XAMPP/MySQL service) esté encendido y que las credenciales en <code>db-config.php</code> sean correctas.</p>
            </div>
        <?php endif; ?>

        <!-- Métricas Rápidas -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <span class="text-muted small text-uppercase">Total de Reservas</span>
                    <div class="stat-number"><?php echo (int) $stats['total_reservations']; ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <span class="text-muted small text-uppercase">Ingresos Estimados</span>
                    <div class="stat-number">$<?php echo number_format($stats['total_revenue'], 2); ?> USD</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <span class="text-muted small text-uppercase">Miembros Inner Circle</span>
                    <div class="stat-number"><?php echo (int) $stats['active_members']; ?></div>
                </div>
            </div>
        </div>

        <!-- Tabla de Reservas -->
        <div class="table-wrap">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4 class="m-0 fw-bold"><i class="fa-solid fa-calendar-check me-2 text-warning"></i> Reservas Recientes</h4>
                <span class="badge bg-secondary"><?php echo count($reservations); ?> registros en MySQL</span>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Localizador</th>
                            <th>Huésped</th>
                            <th>Contacto</th>
                            <th>Habitación</th>
                            <th>Fechas (Check-in &rarr; Out)</th>
                            <th>Noches</th>
                            <th>Huéspedes</th>
                            <th>Total</th>
                            <th>Estado</th>
                            <th>Fecha Registro</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($reservations)): ?>
                            <tr>
                                <td colspan="10" class="text-center py-4 text-muted">
                                    No hay reservas registradas aún. ¡Realiza una prueba desde el formulario de <a href="reservasi.html">reservasi.html</a>!
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($reservations as $row): ?>
                                <tr>
                                    <td><span class="badge-code"><?php echo htmlspecialchars($row['booking_code']); ?></span></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($row['guest_name']); ?></strong>
                                        <div class="small text-muted"><?php echo htmlspecialchars($row['country'] ?? ''); ?></div>
                                    </td>
                                    <td>
                                        <div><i class="fa-regular fa-envelope me-1 text-muted"></i><?php echo htmlspecialchars($row['guest_email']); ?></div>
                                        <div class="small text-muted"><i class="fa-solid fa-phone me-1"></i><?php echo htmlspecialchars($row['guest_phone']); ?></div>
                                    </td>
                                    <td><span class="fw-semibold"><?php echo htmlspecialchars($row['room_type']); ?></span></td>
                                    <td>
                                        <span class="text-nowrap"><?php echo date('d M Y', strtotime($row['check_in'])); ?></span> &rarr;
                                        <span class="text-nowrap"><?php echo date('d M Y', strtotime($row['check_out'])); ?></span>
                                    </td>
                                    <td><?php echo (int) $row['total_nights']; ?> noches</td>
                                    <td><?php echo (int) $row['adults']; ?> Adultos<?php echo $row['children'] > 0 ? ', ' . (int)$row['children'] . ' Niños' : ''; ?></td>
                                    <td><strong class="text-success">$<?php echo number_format((float)$row['total_price'], 2); ?></strong></td>
                                    <td>
                                        <span class="<?php echo $row['status'] === 'confirmed' ? 'badge-confirmed' : 'badge-pending'; ?>">
                                            <?php echo strtoupper($row['status']); ?>
                                        </span>
                                    </td>
                                    <td class="small text-muted"><?php echo date('d/m/Y H:i', strtotime($row['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Tabla de Miembros / Formularios de Registro -->
        <div class="table-wrap mt-4">
            <h4 class="m-0 fw-bold mb-3"><i class="fa-solid fa-users me-2 text-primary"></i> Solicitudes de Membresía Inner Circle</h4>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th># ID</th>
                            <th>Nombre Completo</th>
                            <th>Email</th>
                            <th>Teléfono</th>
                            <th>Página Origen</th>
                            <th>Fecha</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($memberships)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">
                                    No hay solicitudes de membresía aún.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($memberships as $m): ?>
                                <tr>
                                    <td>#<?php echo (int) $m['id']; ?></td>
                                    <td><strong><?php echo htmlspecialchars($m['full_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($m['email']); ?></td>
                                    <td><?php echo htmlspecialchars($m['phone']); ?></td>
                                    <td><span class="badge bg-light text-dark"><?php echo htmlspecialchars($m['source_page']); ?></span></td>
                                    <td class="small text-muted"><?php echo date('d/m/Y H:i', strtotime($m['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</body>
</html>
