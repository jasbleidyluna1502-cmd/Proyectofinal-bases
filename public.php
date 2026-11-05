<?php
declare(strict_types=1);

$publicError = '';
$bookingConfirmation = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'reservar_publica') {
    try {
        $idAfiliadoPublico = filter_var($_SESSION['public_afiliado_id'] ?? null, FILTER_VALIDATE_INT);
        $idServicioPublico = filter_var($_POST['id_servicio'] ?? null, FILTER_VALIDATE_INT);
        $idHorarioPublico = filter_var($_POST['id_horario'] ?? null, FILTER_VALIDATE_INT);
        if (!$idAfiliadoPublico || !$idServicioPublico || !$idHorarioPublico) throw new RuntimeException('Selecciona un servicio y un horario disponible antes de continuar.');
        $idEstadoProgramada = $pdo->query("SELECT id_estado FROM estado WHERE ambito='cita' AND nombre='Programada'")->fetchColumn();
        if (!$idEstadoProgramada) throw new RuntimeException('No se puede reservar porque falta configurar el estado de la cita. Contacta a administración.');
        $pdo->beginTransaction();
        try {
            $stmtSlot = $pdo->prepare("SELECT h.fecha,h.hora_inicio,s.nombre AS estado,s.ambito FROM horario h JOIN estado s USING(id_estado) WHERE h.id_horario=? FOR UPDATE OF h");
            $stmtSlot->execute([$idHorarioPublico]);
            $slot = $stmtSlot->fetch();
            if (!$slot || $slot['ambito'] !== 'horario' || $slot['estado'] !== 'Disponible') {
                throw new RuntimeException('Ese horario ya fue ocupado o dejó de estar disponible. Actualiza la página y elige otro horario.');
            }
            $zonaBogota = new DateTimeZone('America/Bogota');
            $fechaHoraSlot = new DateTimeImmutable($slot['fecha'] . ' ' . $slot['hora_inicio'], $zonaBogota);
            if ($fechaHoraSlot <= new DateTimeImmutable('now', $zonaBogota)) {
                throw new RuntimeException('La fecha y hora seleccionadas ya pasaron. Actualiza la página y elige un horario futuro.');
            }
            $stmtNuevaCita = $pdo->prepare('INSERT INTO cita (id_afiliado,id_servicio,id_horario,id_estado) VALUES (?,?,?,?) RETURNING id_cita');
            $stmtNuevaCita->execute([$idAfiliadoPublico,$idServicioPublico,$idHorarioPublico,$idEstadoProgramada]);
            $idCitaNueva = $stmtNuevaCita->fetchColumn();
            $stmtConfirmacion = $pdo->prepare("SELECT a.nombre1 || ' ' || a.apellido1 AS afiliado,s.nombre AS servicio,h.fecha,to_char(h.hora_inicio,'HH24:MI') AS hora,m.nombre1 || ' ' || m.apellido1 AS medico,e.nombre AS especialidad FROM cita c JOIN afiliado a USING(id_afiliado) JOIN servicio s USING(id_servicio) JOIN horario h USING(id_horario) JOIN medico m USING(id_medico) JOIN especialidad e USING(id_especialidad) WHERE c.id_cita=?");
            $stmtConfirmacion->execute([$idCitaNueva]);
            $bookingConfirmation = $stmtConfirmacion->fetch();
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    } catch (Throwable $e) {
        if ($e instanceof PDOException) {
            $codigoSql = $e->errorInfo[0] ?? (string)$e->getCode();
            $publicError = in_array($codigoSql, ['23505', 'P0001'], true)
                ? 'Ese horario acaba de ser ocupado. Actualiza la página y selecciona otro.'
                : 'No se pudo guardar la cita por un problema de conexión o configuración. Intenta de nuevo y, si continúa, contacta a la EPS.';
        } else {
            $publicError = $e->getMessage();
        }
    }
}

$serviciosPublicos = $pdo->query('SELECT id_servicio,nombre,costo_base FROM servicio ORDER BY nombre')->fetchAll();
$horariosPublicos = $pdo->query("SELECT h.id_horario,h.fecha,to_char(h.hora_inicio,'HH24:MI') AS hora_inicio,to_char(h.hora_fin,'HH24:MI') AS hora_fin,m.nombre1 || ' ' || m.apellido1 AS medico,e.nombre AS especialidad FROM horario h JOIN medico m USING(id_medico) JOIN especialidad e USING(id_especialidad) JOIN estado st USING(id_estado) WHERE st.ambito='horario' AND st.nombre='Disponible' AND (h.fecha+h.hora_inicio)>(CURRENT_TIMESTAMP AT TIME ZONE 'America/Bogota') ORDER BY h.fecha,h.hora_inicio")->fetchAll();
$stmtAfiliadoActual = $pdo->prepare("SELECT concat_ws(' ',NULLIF(trim(nombre1),''),NULLIF(trim(nombre2),''),NULLIF(trim(apellido1),''),NULLIF(trim(apellido2),'')) AS nombre_completo FROM afiliado WHERE id_afiliado=?");
$stmtAfiliadoActual->execute([$_SESSION['public_afiliado_id']]);
$nombreAfiliadoActual = $stmtAfiliadoActual->fetchColumn();
if (!$nombreAfiliadoActual) {
    unset($_SESSION['public_afiliado_id']);
    header('Location: /');
    exit;
}
$stmtCitasPendientes = $pdo->prepare("SELECT c.id_cita,s.nombre AS servicio,h.fecha,to_char(h.hora_inicio,'HH24:MI') AS hora,m.nombre1 || ' ' || m.apellido1 AS medico,e.nombre AS especialidad FROM cita c JOIN servicio s USING(id_servicio) JOIN horario h USING(id_horario) JOIN medico m USING(id_medico) JOIN especialidad e USING(id_especialidad) JOIN estado ec ON ec.id_estado=c.id_estado WHERE c.id_afiliado=? AND ec.ambito='cita' AND ec.nombre='Programada' AND (h.fecha+h.hora_inicio)>(CURRENT_TIMESTAMP AT TIME ZONE 'America/Bogota') ORDER BY h.fecha,h.hora_inicio");
$stmtCitasPendientes->execute([$_SESSION['public_afiliado_id']]);
$citasPendientes = $stmtCitasPendientes->fetchAll();
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Mis citas | EPS Villavicencio</title>
<style>
:root{--blue:#087db4;--blue-dark:#075987;--sky:#eaf6fc;--ink:#243b4a;--muted:#6b7c88;--line:#dbe7ed;--bg:#f5fbff}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif}header{background:#fff;border-bottom:1px solid var(--line)}.nav{max-width:1120px;margin:auto;padding:14px 22px;display:flex;align-items:center;justify-content:space-between;gap:14px}.brand{display:flex;align-items:center;gap:11px;color:var(--blue-dark);font-weight:800;text-decoration:none}.mark{width:43px;height:43px;border-radius:14px;background:var(--sky);color:var(--blue);display:grid;place-items:center;font-size:25px}.nav small{display:block;color:var(--muted);font-size:12px;font-weight:500}.logout{color:var(--blue);font-weight:700;text-decoration:none}.layout{width:min(960px,calc(100% - 32px));margin:36px auto 60px}.welcome{padding:26px 30px;background:linear-gradient(120deg,#e4f4fb,#f6fbfe);border:1px solid var(--line);border-radius:17px;margin-bottom:18px}.eyebrow{color:var(--blue);font-size:12px;font-weight:800;letter-spacing:.12em;text-transform:uppercase}.welcome h1{font-size:clamp(25px,4vw,35px);line-height:1.15;margin:7px 0}.muted{color:var(--muted)}.card{background:white;border:1px solid var(--line);border-radius:16px;padding:clamp(20px,4vw,32px);box-shadow:0 10px 28px #144d6810}.card h2{margin:0;font-size:22px;color:var(--blue-dark)}.help{margin:5px 0 18px;color:var(--muted);font-size:14px}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:0 15px}label{display:block;margin:13px 0 5px;font-size:13px;font-weight:700}input,select{width:100%;height:45px;padding:10px 12px;border:1px solid #cbd9e1;border-radius:8px;background:white;color:var(--ink);font:inherit}input:focus,select:focus{outline:3px solid #d9f1fb;border-color:var(--blue)}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:20px}.button{display:inline-flex;justify-content:center;align-items:center;min-height:45px;padding:10px 19px;border:0;border-radius:99px;background:var(--blue);color:white;font:inherit;font-weight:750;text-decoration:none;cursor:pointer}.button:hover{background:var(--blue-dark)}.notice{margin:16px 0;padding:12px 14px;border-radius:9px;background:#eaf7fc;color:#24516b}.notice.error{background:#fff0ef;color:#923c34}.confirmation{border-left:4px solid var(--blue);padding-left:15px}.footer{text-align:center;padding:15px;color:var(--muted);font-size:12px}@media(max-width:650px){.layout{margin:20px auto 35px}.welcome{padding:21px}.form-grid{grid-template-columns:1fr}.actions .button{width:100%}}
.appointment-list{display:grid;gap:12px;margin-top:18px}.appointment{border:1px solid var(--line);border-left:4px solid var(--blue);border-radius:10px;padding:14px 16px;background:#fbfdff}.appointment p{margin:4px 0}.appointment-date{font-weight:800;color:var(--blue-dark)}
</style></head><body>
<header><nav class="nav"><a class="brand" href="/?reservar=1"><span class="mark">+</span><span>EPS VILLAVICENCIO<small>Portal de citas médicas</small></span></a><a class="logout" href="/?salir=1">Cerrar sesión</a></nav></header>
<main class="layout">
<section class="welcome"><div class="eyebrow">Oficina virtual</div><h1>Hola, <?=h($nombreAfiliadoActual)?></h1><p class="muted">Aquí puedes consultar tus próximas citas y solicitar otra.</p></section>
<?php if ($bookingConfirmation): ?><section class="card confirmation"><h2>Tu cita quedó confirmada</h2><p>Guarda los detalles de tu reserva.</p><p><strong>Servicio:</strong> <?=h($bookingConfirmation['servicio'])?></p><p><strong>Fecha y hora:</strong> <?=h($bookingConfirmation['fecha'])?> · <?=h($bookingConfirmation['hora'])?></p><p><strong>Profesional:</strong> <?=h($bookingConfirmation['medico'])?> · <?=h($bookingConfirmation['especialidad'])?></p></section><?php endif ?>
<section class="card"><h2>Recordatorio de próximas citas</h2><p class="help">Estas son tus citas programadas que aún no han ocurrido.</p><?php if (!$citasPendientes): ?><div class="notice">No tienes citas próximas pendientes.</div><?php else: ?><div class="appointment-list"><?php foreach ($citasPendientes as $citaPendiente): ?><article class="appointment"><p class="appointment-date"><?=h(date('d/m/Y', strtotime((string)$citaPendiente['fecha'])))?> · <?=h($citaPendiente['hora'])?></p><p><strong>Servicio:</strong> <?=h($citaPendiente['servicio'])?></p><p><strong>Profesional:</strong> <?=h($citaPendiente['medico'])?> · <?=h($citaPendiente['especialidad'])?></p><p><strong>Estado:</strong> Programada</p></article><?php endforeach ?></div><?php endif ?></section>
<section class="card"><h2>Solicitar una cita</h2><p class="help">Selecciona el servicio y el horario que prefieras.</p>
<?php if ($publicError): ?><div class="notice error"><?=h($publicError)?></div><?php endif ?>
<?php if (!$serviciosPublicos): ?><div class="notice">En este momento no hay servicios médicos configurados. Contacta a la EPS para que los habilite.</div><?php elseif (!$horariosPublicos): ?><div class="notice">Por ahora no hay horarios futuros disponibles. Vuelve a consultar más tarde.</div><?php else: ?>
<form method="post" action="/?reservar=1"><input type="hidden" name="accion" value="reservar_publica"><?=csrfField()?><label for="servicio">Servicio médico</label><select id="servicio" name="id_servicio" required><?php foreach($serviciosPublicos as $servicio): ?><option value="<?=h($servicio['id_servicio'])?>"><?=h($servicio['nombre'].' · $'.number_format((float)$servicio['costo_base'],0,',','.'))?></option><?php endforeach ?></select><label for="horario">Horario disponible</label><select id="horario" name="id_horario" required><?php foreach($horariosPublicos as $slot): ?><option value="<?=h($slot['id_horario'])?>"><?=h($slot['fecha'].' · '.$slot['hora_inicio'].'–'.$slot['hora_fin'].' · '.$slot['medico'].' · '.$slot['especialidad'])?></option><?php endforeach ?></select><div class="actions"><button class="button">Confirmar cita</button></div></form>
<?php endif ?></section>
</main><footer class="footer">EPS Villavicencio · Portal de citas</footer></body></html>
