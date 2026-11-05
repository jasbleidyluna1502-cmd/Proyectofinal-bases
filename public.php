<?php
declare(strict_types=1);

$publicError = '';
$bookingConfirmation = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $publicAction = $_POST['accion'] ?? '';
    try {
        if ($publicAction === 'buscar_afiliado') {
            $tipoDocumento = filter_var($_POST['id_tipo_doc'] ?? null, FILTER_VALIDATE_INT);
            $numeroDocumento = trim($_POST['num_doc'] ?? '');
            if (!$tipoDocumento || $numeroDocumento === '') throw new RuntimeException('Escribe el tipo y número de documento.');
            $stmtAfiliado = $pdo->prepare('SELECT id_afiliado,nombre1,apellido1 FROM afiliado WHERE id_tipo_doc=? AND num_doc=?');
            $stmtAfiliado->execute([$tipoDocumento,$numeroDocumento]);
            $afiliadoPublico = $stmtAfiliado->fetch();
            if (!$afiliadoPublico) {
                unset($_SESSION['public_afiliado_id']);
                throw new RuntimeException('No encontramos una afiliación con esos datos. Comunícate con administración para registrarte.');
            }
            $_SESSION['public_afiliado_id'] = (int)$afiliadoPublico['id_afiliado'];
        } elseif ($publicAction === 'reiniciar_busqueda') {
            unset($_SESSION['public_afiliado_id']);
        } elseif ($publicAction === 'reservar_publica') {
            $idAfiliadoPublico = filter_var($_SESSION['public_afiliado_id'] ?? null, FILTER_VALIDATE_INT);
            $idServicioPublico = filter_var($_POST['id_servicio'] ?? null, FILTER_VALIDATE_INT);
            $idHorarioPublico = filter_var($_POST['id_horario'] ?? null, FILTER_VALIDATE_INT);
            if (!$idAfiliadoPublico || !$idServicioPublico || !$idHorarioPublico) throw new RuntimeException('Completa el servicio y el horario.');
            $idEstadoProgramada = $pdo->query("SELECT id_estado FROM estado WHERE ambito='cita' AND nombre='Programada'")->fetchColumn();
            if (!$idEstadoProgramada) throw new RuntimeException('No está configurado el estado de cita Programada.');
            $pdo->beginTransaction();
            try {
                $stmtSlot = $pdo->prepare("SELECT h.fecha,h.hora_inicio,s.nombre AS estado,s.ambito FROM horario h JOIN estado s USING(id_estado) WHERE h.id_horario=? FOR UPDATE OF h");
                $stmtSlot->execute([$idHorarioPublico]);
                $slot = $stmtSlot->fetch();
                if (!$slot || $slot['ambito'] !== 'horario' || $slot['estado'] !== 'Disponible') {
                    throw new RuntimeException('Ese horario ya no está disponible. Actualiza la página y selecciona otro.');
                }
                $zonaBogota = new DateTimeZone('America/Bogota');
                $fechaHoraSlot = new DateTimeImmutable($slot['fecha'] . ' ' . $slot['hora_inicio'], $zonaBogota);
                if ($fechaHoraSlot <= new DateTimeImmutable('now', $zonaBogota)) {
                    throw new RuntimeException('Ese horario ya pasó. Actualiza la página y selecciona uno futuro.');
                }
                $stmtNuevaCita = $pdo->prepare('INSERT INTO cita (id_afiliado,id_servicio,id_horario,id_estado) VALUES (?,?,?,?) RETURNING id_cita');
                $stmtNuevaCita->execute([$idAfiliadoPublico,$idServicioPublico,$idHorarioPublico,$idEstadoProgramada]);
                $idCitaNueva = $stmtNuevaCita->fetchColumn();
                $stmtConfirmacion = $pdo->prepare("SELECT a.nombre1 || ' ' || a.apellido1 AS afiliado,s.nombre AS servicio,h.fecha,to_char(h.hora_inicio,'HH24:MI') AS hora,m.nombre1 || ' ' || m.apellido1 AS medico,e.nombre AS especialidad FROM cita c JOIN afiliado a USING(id_afiliado) JOIN servicio s USING(id_servicio) JOIN horario h USING(id_horario) JOIN medico m USING(id_medico) JOIN especialidad e USING(id_especialidad) WHERE c.id_cita=?");
                $stmtConfirmacion->execute([$idCitaNueva]);
                $bookingConfirmation = $stmtConfirmacion->fetch();
                $pdo->commit();
                unset($_SESSION['public_afiliado_id']);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        }
    } catch (Throwable $e) {
        $publicError = $e instanceof PDOException && ($publicAction ?? '') === 'reservar_publica'
            ? 'No se pudo reservar: otra persona tomó ese horario. Actualiza la página y elige otro.'
            : $e->getMessage();
    }
}

$tiposPublicos = $pdo->query('SELECT id_tipo_doc,sigla,nombre FROM tipo_documento ORDER BY sigla')->fetchAll();
$serviciosPublicos = $pdo->query('SELECT id_servicio,nombre,costo_base FROM servicio ORDER BY nombre')->fetchAll();
$horariosPublicos = $pdo->query("SELECT h.id_horario,h.fecha,to_char(h.hora_inicio,'HH24:MI') AS hora_inicio,to_char(h.hora_fin,'HH24:MI') AS hora_fin,m.nombre1 || ' ' || m.apellido1 AS medico,e.nombre AS especialidad FROM horario h JOIN medico m USING(id_medico) JOIN especialidad e USING(id_especialidad) JOIN estado st USING(id_estado) WHERE st.ambito='horario' AND st.nombre='Disponible' AND (h.fecha+h.hora_inicio)>(CURRENT_TIMESTAMP AT TIME ZONE 'America/Bogota') ORDER BY h.fecha,h.hora_inicio")->fetchAll();
$afiliadoActual = null;
if (!empty($_SESSION['public_afiliado_id'])) {
    $stmtActual = $pdo->prepare('SELECT id_afiliado,nombre1,apellido1 FROM afiliado WHERE id_afiliado=?');
    $stmtActual->execute([$_SESSION['public_afiliado_id']]);
    $afiliadoActual = $stmtActual->fetch() ?: null;
    if (!$afiliadoActual) unset($_SESSION['public_afiliado_id']);
}
$modoReserva = isset($_GET['reservar']) || ($_SERVER['REQUEST_METHOD'] === 'POST');
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>EPS Villavicencio | Citas médicas</title>
<style>
:root{--ink:#233746;--muted:#71828c;--teal:#187d78;--dark:#173b40;--mint:#e6f3ef;--line:#e1eae6;--bg:#f5f8f6}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif}header{background:white;border-bottom:1px solid var(--line)}.nav{max-width:1100px;margin:auto;padding:16px 22px;display:flex;justify-content:space-between;align-items:center;gap:18px}.brand{display:flex;align-items:center;gap:12px;color:var(--dark);font-weight:800}.mark{width:44px;height:44px;border-radius:15px;background:var(--mint);color:var(--teal);display:grid;place-items:center;font-size:25px}.links{display:flex;gap:12px;align-items:center}.links a,.button{color:var(--teal);text-decoration:none;font-weight:700}.button{display:inline-flex;background:var(--teal);color:white;padding:11px 17px;border-radius:10px;border:0;font:inherit;font-weight:700;cursor:pointer}.hero{max-width:1100px;margin:46px auto;padding:0 22px;display:grid;grid-template-columns:1.2fr .8fr;gap:24px;align-items:center}.hero-card,.card{background:white;border:1px solid var(--line);border-radius:20px;padding:clamp(22px,4vw,40px);box-shadow:0 12px 32px #153b3010}.eyebrow{color:var(--teal);font-size:12px;font-weight:800;letter-spacing:.12em;text-transform:uppercase}.hero h1{font-size:clamp(34px,5vw,54px);line-height:1.08;letter-spacing:-.04em;margin:12px 0}.hero p,.muted{color:var(--muted)}.hero-art{min-height:270px;background:linear-gradient(140deg,#deefe8,#f1f6ec);border-radius:24px;display:grid;place-items:center;font-size:100px}.wrap{max-width:820px;margin:40px auto;padding:0 20px}.card h1{margin:0 0 6px;font-size:29px}.card h2{margin:24px 0 8px}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:0 14px}label{display:block;font-size:13px;font-weight:700;margin:12px 0 5px}input,select{width:100%;border:1px solid #d4e0dc;border-radius:9px;padding:12px;font:inherit;color:var(--ink);background:white}.actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:20px}.notice{padding:12px 14px;border-radius:10px;background:#edf8f0;color:#24593d;margin:16px 0}.notice.error{background:#fff2ef;color:#833b32}.footer{text-align:center;color:var(--muted);padding:20px;font-size:13px}@media(max-width:700px){.hero{grid-template-columns:1fr;margin:22px auto}.hero-art{min-height:150px;font-size:70px}.form-grid{grid-template-columns:1fr}.links{gap:8px}.nav{padding:12px 16px}}
</style></head><body>
<header><nav class="nav"><a class="brand" href="/" style="text-decoration:none"><span class="mark">+</span><span>EPS VILLAVICENCIO<br><small class="muted">Citas médicas</small></span></a><div class="links"><a href="/?reservar=1">Reservar cita</a><a class="button" href="/?admin=1">Administración</a></div></nav></header>
<?php if ($bookingConfirmation): ?>
<main class="wrap"><section class="card"><div class="eyebrow">Reserva confirmada</div><h1>Tu cita quedó agendada</h1><div class="notice">Guarda estos datos para consultar tu cita con el hospital.</div><p><strong>Paciente:</strong> <?=h($bookingConfirmation['afiliado'])?></p><p><strong>Servicio:</strong> <?=h($bookingConfirmation['servicio'])?></p><p><strong>Fecha:</strong> <?=h($bookingConfirmation['fecha'])?> a las <?=h($bookingConfirmation['hora'])?></p><p><strong>Profesional:</strong> <?=h($bookingConfirmation['medico'])?> · <?=h($bookingConfirmation['especialidad'])?></p><div class="actions"><a class="button" href="/">Volver al inicio</a></div></section></main>
<?php elseif (!$modoReserva): ?>
<main class="hero"><section class="hero-card"><div class="eyebrow">Atención organizada</div><h1>Agenda tu cita médica de forma sencilla.</h1><p>Consulta los horarios disponibles y solicita una cita en los servicios de EPS Villavicencio.</p><div class="actions"><a class="button" href="/?reservar=1">＋ &nbsp;Reservar una cita</a><a href="/?admin=1" class="button" style="background:white;color:var(--teal);border:1px solid var(--line)">Acceso del hospital</a></div></section><div class="hero-art" aria-hidden="true">🩺</div></main>
<?php else: ?>
<main class="wrap"><section class="card"><div class="eyebrow">Reserva en línea</div><h1>Solicitar una cita</h1><p class="muted">Busca tu afiliación con tu documento para ver las opciones de reserva.</p>
<?php if ($publicError): ?><div class="notice error"><?=h($publicError)?></div><?php endif ?>
<?php if (!$afiliadoActual): ?>
<form method="post" action="/?reservar=1"><input type="hidden" name="accion" value="buscar_afiliado"><?=csrfField()?><div class="form-grid"><div><label for="tipo">Tipo de documento</label><select id="tipo" name="id_tipo_doc" required><?php foreach($tiposPublicos as $tipo): ?><option value="<?=h($tipo['id_tipo_doc'])?>"><?=h($tipo['sigla'].' · '.$tipo['nombre'])?></option><?php endforeach ?></select></div><div><label for="documento">Número de documento</label><input id="documento" name="num_doc" required maxlength="20" autocomplete="off"></div></div><div class="actions"><button class="button">Continuar</button><a class="button" style="background:white;color:var(--teal);border:1px solid var(--line)" href="/">Volver</a></div></form>
<?php else: ?>
<div class="notice">Afiliación encontrada: <strong><?=h($afiliadoActual['nombre1'].' '.$afiliadoActual['apellido1'])?></strong></div>
<?php if (!$serviciosPublicos || !$horariosPublicos): ?><div class="notice error">En este momento no hay servicios u horarios disponibles para reservar. Intenta más tarde.</div><?php else: ?>
<form method="post" action="/?reservar=1"><input type="hidden" name="accion" value="reservar_publica"><?=csrfField()?><label for="servicio">Servicio</label><select id="servicio" name="id_servicio" required><?php foreach($serviciosPublicos as $servicio): ?><option value="<?=h($servicio['id_servicio'])?>"><?=h($servicio['nombre'].' · $'.number_format((float)$servicio['costo_base'],0,',','.'))?></option><?php endforeach ?></select><label for="horario">Horario disponible</label><select id="horario" name="id_horario" required><?php foreach($horariosPublicos as $slot): ?><option value="<?=h($slot['id_horario'])?>"><?=h($slot['fecha'].' · '.$slot['hora_inicio'].'–'.$slot['hora_fin'].' · '.$slot['medico'].' · '.$slot['especialidad'])?></option><?php endforeach ?></select><div class="actions"><button class="button">Confirmar cita</button></div></form><form method="post" action="/?reservar=1"><input type="hidden" name="accion" value="reiniciar_busqueda"><?=csrfField()?><div class="actions"><button class="button" style="background:white;color:var(--teal);border:1px solid var(--line)">Cambiar documento</button></div></form>
<?php endif ?>
<?php endif ?></section></main>
<?php endif ?>
<footer class="footer">EPS Villavicencio · Agenda médica</footer></body></html>
