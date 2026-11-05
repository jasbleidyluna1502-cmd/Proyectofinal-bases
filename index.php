<?php
declare(strict_types=1);
$secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secureCookie, 'httponly' => true, 'samesite' => 'Lax']);
session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') . '">';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('La sesión del formulario venció. Recarga la página e inténtalo de nuevo.');
}
$publicPassword = getenv('APP_ACCESS_PASSWORD') ?: '';
$adminRoute = isset($_GET['admin']);
if (isset($_GET['salir'])) {
    unset($_SESSION['eps_admin'], $_SESSION['public_afiliado_id']);
    session_regenerate_id(true);
    header('Location: /');
    exit;
}
try {
    require_once __DIR__ . '/Database.php';
    $pdo = Database::getInstance();
} catch (PDOException $e) {
    http_response_code(500);
    exit('No se pudo conectar con PostgreSQL. Revise config.php y que el servidor esté activo.');
}
function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function nombreNormalizado(string $nombre): string {
    $nombre = preg_replace('/\s+/u', ' ', trim($nombre)) ?? trim($nombre);
    if (function_exists('iconv')) $nombre = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nombre) ?: $nombre;
    return function_exists('mb_strtolower') ? mb_strtolower($nombre, 'UTF-8') : strtolower($nombre);
}
$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'login_unificado') {
    $tipoLogin = filter_var($_POST['id_tipo_doc'] ?? null, FILTER_VALIDATE_INT);
    $documentoLogin = trim($_POST['num_doc'] ?? '');
    $nombreLogin = trim($_POST['nombre_completo'] ?? '');
    $claveLogin = (string)($_POST['clave'] ?? '');
    $stmtTipoLogin = $pdo->prepare('SELECT sigla FROM tipo_documento WHERE id_tipo_doc=?');
    $stmtTipoLogin->execute([$tipoLogin ?: 0]);
    $siglaLogin = $stmtTipoLogin->fetchColumn();
    $adminDocumento = trim(getenv('APP_ADMIN_DOCUMENTO') ?: '');
    $adminTipo = strtoupper(trim(getenv('APP_ADMIN_TIPO_DOC') ?: ''));
    $adminNombre = getenv('APP_ADMIN_NOMBRE') ?: '';
    $esAdministrador = $siglaLogin && $adminTipo !== '' && $adminDocumento !== '' && $adminNombre !== ''
        && strtoupper((string)$siglaLogin) === $adminTipo
        && hash_equals($adminDocumento, $documentoLogin)
        && nombreNormalizado($adminNombre) === nombreNormalizado($nombreLogin);
    if ($esAdministrador) {
        if ($publicPassword !== '' && hash_equals($publicPassword, $claveLogin)) {
            session_regenerate_id(true);
            unset($_SESSION['public_afiliado_id']);
            $_SESSION['eps_admin'] = true;
            header('Location: /?admin=1');
            exit;
        }
        $loginError = $publicPassword === '' ? 'La clave de administración no está configurada.' : 'La clave del hospital no coincide.';
    } else {
        $stmtAfiliadoLogin = $pdo->prepare('SELECT id_afiliado,nombre1 FROM afiliado WHERE id_tipo_doc=? AND num_doc=?');
        $stmtAfiliadoLogin->execute([$tipoLogin ?: 0,$documentoLogin]);
        $afiliadoLogin = $stmtAfiliadoLogin->fetch();
        if ($afiliadoLogin && nombreNormalizado((string)$afiliadoLogin['nombre1']) === nombreNormalizado($nombreLogin)) {
            session_regenerate_id(true);
            unset($_SESSION['eps_admin']);
            $_SESSION['public_afiliado_id'] = (int)$afiliadoLogin['id_afiliado'];
            header('Location: /?reservar=1');
            exit;
        }
        $loginError = 'No se pudo validar el documento y nombre. Verifica los datos o comunícate con la EPS.';
    }
}
if (empty($_SESSION['eps_admin']) && empty($_SESSION['public_afiliado_id'])) {
    $tiposLogin = $pdo->query('SELECT id_tipo_doc,sigla,nombre FROM tipo_documento ORDER BY sigla')->fetchAll();
    ?>
    <!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Ingresar | EPS Villavicencio</title>
    <style>
    :root{--blue:#087db4;--blue-dark:#075987;--sky:#eaf6fc;--ink:#243b4a;--muted:#6b7c88;--line:#dbe7ed}*{box-sizing:border-box}body{margin:0;min-height:100vh;background:linear-gradient(135deg,#f5fbff,#eaf5fa);color:var(--ink);font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}.page{min-height:100vh;display:grid;place-items:center;padding:30px}.login-layout{width:min(960px,100%);display:grid;grid-template-columns:1fr 1fr;background:#fff;border:1px solid var(--line);border-radius:22px;overflow:hidden;box-shadow:0 22px 60px #164d6817}.intro{padding:clamp(28px,5vw,58px);background:linear-gradient(145deg,#e9f7fc,#f8fcfe);display:flex;flex-direction:column;justify-content:center}.brand{display:flex;align-items:center;gap:13px;color:var(--blue-dark);font-weight:800;font-size:20px}.brand-mark{width:52px;height:52px;display:grid;place-items:center;border-radius:17px;background:#d8eff9;color:var(--blue);font-size:31px}.intro h1{font-size:clamp(27px,4vw,39px);line-height:1.12;margin:38px 0 12px;letter-spacing:-.035em}.intro p{color:var(--muted);max-width:350px}.pill{margin-top:24px;display:inline-flex;align-items:center;gap:9px;color:var(--blue-dark);font-weight:700}.login-card{padding:clamp(28px,5vw,54px);align-self:center}.login-card h2{font-size:26px;margin:0 0 6px;color:var(--blue-dark)}.help{margin:0 0 20px;color:var(--muted);font-size:14px}label{display:block;margin:14px 0 5px;font-weight:700;font-size:13px}input,select{display:block;width:100%;height:45px;padding:10px 12px;border:1px solid #cbd9e1;border-radius:8px;background:white;color:var(--ink);font:inherit}input:focus,select:focus{outline:3px solid #d9f1fb;border-color:var(--blue)}.button{width:100%;height:46px;margin-top:22px;border:0;border-radius:99px;background:var(--blue);color:#fff;font:inherit;font-weight:750;cursor:pointer}.button:hover{background:var(--blue-dark)}.error{margin-top:14px;padding:11px 13px;border-radius:9px;background:#fff0ef;color:#923c34;font-size:13px}.security{margin-top:18px;color:var(--muted);font-size:12px}.bottom{margin-top:24px;color:var(--muted);font-size:12px}@media(max-width:700px){.page{padding:15px}.login-layout{grid-template-columns:1fr}.intro{padding:24px 25px 18px}.intro h1{font-size:27px;margin:23px 0 5px}.intro p{margin:0}.pill{margin-top:13px}.login-card{padding:22px 25px 28px}}
    </style></head><body><main class="page"><div class="login-layout"><section class="intro"><div class="brand"><span class="brand-mark">+</span><span>EPS VILLAVICENCIO</span></div><h1>Tu salud, más cerca.</h1><p>Ingresa para solicitar una cita médica o acceder a la gestión de la EPS.</p><div class="pill">✚ &nbsp;Agenda médica en línea</div></section><section class="login-card"><h2>Iniciar sesión</h2><p class="help">Ingresa tu tipo y número de documento. Afiliados: escribe tu primer nombre. Administración: escribe el nombre completo configurado.</p><form method="post" action="/"><input type="hidden" name="accion" value="login_unificado"><?=csrfField()?><label for="tipoDocumento">Tipo de documento</label><select id="tipoDocumento" name="id_tipo_doc" required><?php foreach($tiposLogin as $tipo): ?><option value="<?=h($tipo['id_tipo_doc'])?>"><?=h($tipo['sigla'].' · '.$tipo['nombre'])?></option><?php endforeach ?></select><label for="numeroDocumento">Número de documento</label><input id="numeroDocumento" name="num_doc" required maxlength="20" autocomplete="username"><label for="nombreCompleto">Nombre</label><input id="nombreCompleto" name="nombre_completo" required maxlength="200" autocomplete="name"><label for="claveHospital">Clave del hospital <span style="font-weight:400;color:var(--muted)">(solo administración)</span></label><input id="claveHospital" name="clave" type="password" autocomplete="current-password"><button class="button">Continuar</button></form><?php if($loginError): ?><div class="error"><?=h($loginError)?></div><?php endif ?><p class="security">Los afiliados dejan la clave vacía. El acceso administrativo requiere la identidad autorizada y la clave del hospital.</p><div class="bottom">EPS Villavicencio · Portal de citas</div></section></div></main></body></html>
    <?php
    exit;
}
if (!empty($_SESSION['eps_admin']) && !$adminRoute) {
    header('Location: /?admin=1');
    exit;
}
if (empty($_SESSION['eps_admin'])) {
    require __DIR__ . '/public.php';
    exit;
}
$apiName = $_GET['api'] ?? '';
if ($apiName !== '') {
    if (empty($_SESSION['eps_admin'])) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Acceso no autorizado.']);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    $apiId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    $apiQueries = [
        'afiliado' => 'SELECT id_tipo_doc,num_doc,nombre1,nombre2,apellido1,apellido2,sexo,fecha_nacimiento,telefono,correo,direccion,ingreso_mensual,id_tarifa FROM afiliado WHERE id_afiliado=?',
        'medico' => 'SELECT id_tipo_doc,num_documento,nombre1,nombre2,apellido1,apellido2,correo,telefono,id_especialidad,id_consultorio FROM medico WHERE id_medico=?',
        'horario' => "SELECT h.id_medico,h.fecha,to_char(h.hora_inicio,'HH24:MI') AS hora_inicio,to_char(h.hora_fin,'HH24:MI') AS hora_fin,s.nombre AS estado FROM horario h JOIN estado s USING(id_estado) WHERE h.id_horario=?",
        'cita' => "SELECT c.id_afiliado,c.id_servicio,c.id_horario,e.nombre AS estado,c.valor_cobrado,c.fecha_solicitud,h.fecha,to_char(h.hora_inicio,'HH24:MI') AS hora_inicio,h.id_medico,m.nombre1 || ' ' || m.apellido1 AS nombre_medico FROM cita c JOIN estado e USING(id_estado) JOIN horario h USING(id_horario) JOIN medico m USING(id_medico) WHERE c.id_cita=?",
    ];
    if (!isset($apiQueries[$apiName]) || !$apiId) {
        http_response_code(400);
        echo json_encode(['error' => 'Solicitud no válida.']);
        exit;
    }
    $stmtApi = $pdo->prepare($apiQueries[$apiName]);
    $stmtApi->execute([$apiId]);
    $registroApi = $stmtApi->fetch();
    if (!$registroApi) {
        http_response_code(404);
        echo json_encode(['error' => 'No se encontró un registro con ese ID.']);
        exit;
    }
    echo json_encode($registroApi, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
$mensaje = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $accion = $_POST['accion'] ?? '';
        if ($accion === 'crear_afiliado' || $accion === 'actualizar_afiliado') {
            $id = filter_var($_POST['id_afiliado'] ?? null, FILTER_VALIDATE_INT);
            $tipo = filter_var($_POST['id_tipo_doc'] ?? null, FILTER_VALIDATE_INT);
            $numero = trim($_POST['num_doc'] ?? '');
            $nombre = trim($_POST['nombre1'] ?? '');
            $apellido = trim($_POST['apellido1'] ?? '');
            $nacimiento = $_POST['fecha_nacimiento'] ?? '';
            $ingreso = filter_var($_POST['ingreso_mensual'] ?? null, FILTER_VALIDATE_FLOAT);
            $tarifa = filter_var($_POST['id_tarifa'] ?? null, FILTER_VALIDATE_INT);
            if (!$tipo || !$numero || !$nombre || !$apellido || !$nacimiento || $ingreso === false || $ingreso < 0 || !$tarifa) {
                throw new RuntimeException('Complete los campos obligatorios. El ingreso debe ser un número igual o mayor que cero.');
            }
            $tipoDocumento = $pdo->prepare('SELECT sigla FROM tipo_documento WHERE id_tipo_doc = ?');
            $tipoDocumento->execute([$tipo]);
            $siglaDocumento = $tipoDocumento->fetchColumn();
            if (!$siglaDocumento) throw new RuntimeException('Seleccione un tipo de documento válido.');
            $documentoNumerico = in_array($siglaDocumento, ['CC', 'TI', 'RC'], true);
            $patronDocumento = $documentoNumerico ? '/^[0-9]+$/' : '/^[A-Za-z0-9]+$/';
            if (!preg_match($patronDocumento, $numero)) {
                throw new RuntimeException($documentoNumerico
                    ? 'CC, TI y RC deben contener solo números.'
                    : 'El documento solo puede contener letras y números.');
            }
            if ($documentoNumerico && strlen($numero) > 10) {
                throw new RuntimeException('El documento no puede superar los 10 dígitos.');
            }
            foreach (['nombre1' => $nombre, 'apellido1' => $apellido, 'nombre2' => trim($_POST['nombre2'] ?? ''), 'apellido2' => trim($_POST['apellido2'] ?? '')] as $etiqueta => $valorNombre) {
                if ($valorNombre !== '' && !preg_match('/^[\p{L}\p{M} .\'-]+$/u', $valorNombre)) {
                    throw new RuntimeException('Los nombres y apellidos solo pueden contener letras, espacios, apóstrofes y guiones.');
                }
            }
            $telefono = trim($_POST['telefono'] ?? '');
            if ($telefono !== '' && !preg_match('/^[0-9+() .-]+$/', $telefono)) {
                throw new RuntimeException('El teléfono solo puede contener números y los signos +, paréntesis, espacios o guiones.');
            }
            if (strtotime($nacimiento) > strtotime('today')) throw new RuntimeException('La fecha de nacimiento no puede ser futura.');
            $datos = [
                'id_tipo_doc' => $tipo, 'num_doc' => $numero, 'nombre1' => $nombre,
                'nombre2' => trim($_POST['nombre2'] ?? '') ?: null, 'apellido1' => $apellido,
                'apellido2' => trim($_POST['apellido2'] ?? '') ?: null, 'sexo' => trim($_POST['sexo'] ?? '') ?: null,
                'fecha_nacimiento' => $nacimiento, 'telefono' => $telefono ?: null,
                'correo' => trim($_POST['correo'] ?? '') ?: null, 'direccion' => trim($_POST['direccion'] ?? '') ?: null,
                'ingreso_mensual' => $ingreso, 'id_tarifa' => $tarifa,
            ];
            if ($accion === 'crear_afiliado') {
                $sql = 'INSERT INTO afiliado (id_tipo_doc,num_doc,nombre1,nombre2,apellido1,apellido2,sexo,fecha_nacimiento,telefono,correo,direccion,ingreso_mensual,id_tarifa) VALUES (:id_tipo_doc,:num_doc,:nombre1,:nombre2,:apellido1,:apellido2,:sexo,:fecha_nacimiento,:telefono,:correo,:direccion,:ingreso_mensual,:id_tarifa)';
                $mensaje = 'Afiliado registrado.';
            } else {
                if (!$id) throw new RuntimeException('Indique un identificador de afiliado válido.');
                $datos['id_afiliado'] = $id;
                $sql = 'UPDATE afiliado SET id_tipo_doc=:id_tipo_doc,num_doc=:num_doc,nombre1=:nombre1,nombre2=:nombre2,apellido1=:apellido1,apellido2=:apellido2,sexo=:sexo,fecha_nacimiento=:fecha_nacimiento,telefono=:telefono,correo=:correo,direccion=:direccion,ingreso_mensual=:ingreso_mensual,id_tarifa=:id_tarifa WHERE id_afiliado=:id_afiliado';
                $mensaje = 'Afiliado actualizado.';
            }
            $stmtAfiliado = $pdo->prepare($sql);
            $stmtAfiliado->execute($datos);
            if ($accion === 'actualizar_afiliado' && $stmtAfiliado->rowCount() === 0) throw new RuntimeException('No se encontró un afiliado con ese ID.');
        } elseif (in_array($accion, ['crear_medico', 'actualizar_medico'], true)) {
            $idMedico = filter_var($_POST['id_medico'] ?? null, FILTER_VALIDATE_INT);
            $tipoMedico = filter_var($_POST['id_tipo_doc'] ?? null, FILTER_VALIDATE_INT);
            $docMedico = trim($_POST['num_documento'] ?? '');
            $nombreMedico = trim($_POST['nombre1'] ?? '');
            $apellidoMedico = trim($_POST['apellido1'] ?? '');
            $especialidadMedico = filter_var($_POST['id_especialidad'] ?? null, FILTER_VALIDATE_INT);
            $consultorioRaw = trim($_POST['id_consultorio'] ?? '');
            $consultorioMedico = $consultorioRaw === '' ? null : filter_var($consultorioRaw, FILTER_VALIDATE_INT);
            $correoMedico = trim($_POST['correo'] ?? '');
            $telefonoMedico = trim($_POST['telefono'] ?? '');
            if (!$tipoMedico || !$docMedico || !$nombreMedico || !$apellidoMedico || !$especialidadMedico || ($consultorioRaw !== '' && !$consultorioMedico)) {
                throw new RuntimeException('Complete los campos obligatorios del médico.');
            }
            $stmtTipo = $pdo->prepare('SELECT sigla FROM tipo_documento WHERE id_tipo_doc = ?');
            $stmtTipo->execute([$tipoMedico]);
            $siglaMedico = $stmtTipo->fetchColumn();
            if (!$siglaMedico) throw new RuntimeException('Seleccione un tipo de documento válido para el médico.');
            $docNumericoMedico = in_array($siglaMedico, ['CC', 'TI', 'RC'], true);
            if (!preg_match($docNumericoMedico ? '/^[0-9]+$/' : '/^[A-Za-z0-9]+$/', $docMedico)) {
                throw new RuntimeException($docNumericoMedico ? 'El documento del médico solo puede contener números.' : 'El documento del médico solo puede contener letras y números.');
            }
            if ($docNumericoMedico && strlen($docMedico) > 10) throw new RuntimeException('El documento del médico no puede superar los 10 dígitos.');
            foreach ([$nombreMedico, $apellidoMedico, trim($_POST['nombre2'] ?? ''), trim($_POST['apellido2'] ?? '')] as $nombreValidar) {
                if ($nombreValidar !== '' && !preg_match('/^[\p{L}\p{M} .\'-]+$/u', $nombreValidar)) throw new RuntimeException('Los nombres del médico solo pueden contener letras, espacios, apóstrofes y guiones.');
            }
            if ($correoMedico !== '' && !filter_var($correoMedico, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Ingrese un correo válido para el médico.');
            if ($telefonoMedico !== '' && !preg_match('/^[0-9+() .-]+$/', $telefonoMedico)) throw new RuntimeException('El teléfono del médico solo puede contener números y signos telefónicos usuales.');
            $datosMedico = [
                'id_tipo_doc' => $tipoMedico, 'num_documento' => $docMedico,
                'nombre1' => $nombreMedico, 'nombre2' => trim($_POST['nombre2'] ?? '') ?: null,
                'apellido1' => $apellidoMedico, 'apellido2' => trim($_POST['apellido2'] ?? '') ?: null,
                'correo' => $correoMedico ?: null, 'telefono' => $telefonoMedico ?: null,
                'id_especialidad' => $especialidadMedico, 'id_consultorio' => $consultorioMedico ?: null,
            ];
            if ($accion === 'crear_medico') {
                $pdo->prepare('INSERT INTO medico (id_tipo_doc,num_documento,nombre1,nombre2,apellido1,apellido2,correo,telefono,id_especialidad,id_consultorio) VALUES (:id_tipo_doc,:num_documento,:nombre1,:nombre2,:apellido1,:apellido2,:correo,:telefono,:id_especialidad,:id_consultorio)')->execute($datosMedico);
                $mensaje = 'Médico registrado.';
            } else {
                if (!$idMedico) throw new RuntimeException('Indique el ID del médico que desea actualizar.');
                $datosMedico['id_medico'] = $idMedico;
                $stmtMedico = $pdo->prepare('UPDATE medico SET id_tipo_doc=:id_tipo_doc,num_documento=:num_documento,nombre1=:nombre1,nombre2=:nombre2,apellido1=:apellido1,apellido2=:apellido2,correo=:correo,telefono=:telefono,id_especialidad=:id_especialidad,id_consultorio=:id_consultorio WHERE id_medico=:id_medico');
                $stmtMedico->execute($datosMedico);
                if ($stmtMedico->rowCount() === 0) throw new RuntimeException('No se encontró un médico con ese ID o no hubo cambios.');
                $mensaje = 'Médico actualizado.';
            }
        } elseif (in_array($accion, ['crear_consultorio', 'actualizar_consultorio'], true)) {
            $idConsultorio = filter_var($_POST['id_consultorio'] ?? null, FILTER_VALIDATE_INT);
            $nombreConsultorio = trim($_POST['nombre_consultorio'] ?? '');
            $ubicacionConsultorio = trim($_POST['ubicacion'] ?? '');
            $capacidadConsultorio = filter_var($_POST['capacidad'] ?? null, FILTER_VALIDATE_INT);
            if (!$nombreConsultorio || !$ubicacionConsultorio || $capacidadConsultorio === false || $capacidadConsultorio < 0) {
                throw new RuntimeException('Complete el nombre, la ubicación y una capacidad igual o mayor que cero.');
            }
            if ($accion === 'crear_consultorio') {
                $estadoDisponible = $pdo->query("SELECT id_estado FROM estado WHERE ambito='consultorio' AND nombre='Disponible'")->fetchColumn();
                if (!$estadoDisponible) throw new RuntimeException('No existe el estado Disponible para consultorios.');
                $pdo->prepare('INSERT INTO consultorio (nombre,ubicacion,capacidad,id_estado) VALUES (?,?,?,?)')->execute([$nombreConsultorio,$ubicacionConsultorio,$capacidadConsultorio,$estadoDisponible]);
                $mensaje = 'Consultorio registrado.';
            } else {
                if (!$idConsultorio) throw new RuntimeException('Indique el ID del consultorio que desea actualizar.');
                $stmtConsultorio = $pdo->prepare('UPDATE consultorio SET nombre=?,ubicacion=?,capacidad=? WHERE id_consultorio=?');
                $stmtConsultorio->execute([$nombreConsultorio,$ubicacionConsultorio,$capacidadConsultorio,$idConsultorio]);
                if ($stmtConsultorio->rowCount() === 0) throw new RuntimeException('No se encontró un consultorio con ese ID o no hubo cambios.');
                $mensaje = 'Consultorio actualizado.';
            }
        } elseif (in_array($accion, ['crear_horario', 'actualizar_horario'], true)) {
            $idHorario = filter_var($_POST['id_horario'] ?? null, FILTER_VALIDATE_INT);
            $medicoHorario = filter_var($_POST['id_medico'] ?? null, FILTER_VALIDATE_INT);
            $fechaHorario = trim($_POST['fecha'] ?? '');
            $horaInicio = trim($_POST['hora_inicio'] ?? '');
            $horaFin = trim($_POST['hora_fin'] ?? '');
            $fechaValida = DateTimeImmutable::createFromFormat('!Y-m-d', $fechaHorario);
            if (!$medicoHorario || !$fechaValida || $fechaValida->format('Y-m-d') !== $fechaHorario || $fechaHorario < date('Y-m-d')) {
                throw new RuntimeException('Seleccione un médico y una fecha válida que no haya pasado.');
            }
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $horaInicio) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $horaFin) || $horaFin <= $horaInicio) {
                throw new RuntimeException('La hora final debe ser posterior a la hora inicial.');
            }
            $zonaBogotaHorario = new DateTimeZone('America/Bogota');
            $fechaHoraInicioHorario = new DateTimeImmutable($fechaHorario . ' ' . $horaInicio, $zonaBogotaHorario);
            if ($fechaHoraInicioHorario <= new DateTimeImmutable('now', $zonaBogotaHorario)) throw new RuntimeException('El horario debe comenzar en una fecha y hora futuras.');
            $estadoDisponible = $pdo->query("SELECT id_estado FROM estado WHERE ambito='horario' AND nombre='Disponible'")->fetchColumn();
            if (!$estadoDisponible) throw new RuntimeException('No existe el estado Disponible para horarios.');
            if ($accion === 'crear_horario') {
                $pdo->prepare('INSERT INTO horario (id_medico,fecha,hora_inicio,hora_fin,id_estado) VALUES (?,?,?,?,?)')->execute([$medicoHorario,$fechaHorario,$horaInicio,$horaFin,$estadoDisponible]);
                $mensaje = 'Horario agregado.';
            } else {
                if (!$idHorario) throw new RuntimeException('Indique el ID del horario que desea actualizar.');
                $stmtHorario = $pdo->prepare("UPDATE horario SET id_medico=?,fecha=?,hora_inicio=?,hora_fin=? WHERE id_horario=? AND id_estado=?");
                $stmtHorario->execute([$medicoHorario,$fechaHorario,$horaInicio,$horaFin,$idHorario,$estadoDisponible]);
                if ($stmtHorario->rowCount() === 0) throw new RuntimeException('Solo se pueden modificar horarios disponibles.');
                $mensaje = 'Horario actualizado.';
            }
        } elseif ($accion === 'crear_cita') {
            $afiliado = filter_var($_POST['id_afiliado'] ?? null, FILTER_VALIDATE_INT);
            $servicio = filter_var($_POST['id_servicio'] ?? null, FILTER_VALIDATE_INT);
            $horario = filter_var($_POST['id_horario'] ?? null, FILTER_VALIDATE_INT);
            $estado = $pdo->query("SELECT id_estado FROM estado WHERE ambito='cita' AND nombre='Programada'")->fetchColumn();
            if (!$afiliado || !$servicio || !$horario || !$estado) throw new RuntimeException('Seleccione afiliado, servicio y horario.');
            $pdo->beginTransaction();
            try {
                $stmtHorarioDisponible = $pdo->prepare("SELECT h.fecha,h.hora_inicio,s.nombre AS estado_horario,s.ambito FROM horario h JOIN estado s ON s.id_estado=h.id_estado WHERE h.id_horario=? FOR UPDATE OF h");
                $stmtHorarioDisponible->execute([$horario]);
                $datosHorario = $stmtHorarioDisponible->fetch();
                if (!$datosHorario) throw new RuntimeException('El horario seleccionado ya no existe. Actualice la página y elija otro.');
                if ($datosHorario['ambito'] !== 'horario' || $datosHorario['estado_horario'] !== 'Disponible') {
                    throw new RuntimeException('Ese horario ya no está disponible. Actualice la página y elija otra hora.');
                }
                $zonaBogota = new DateTimeZone('America/Bogota');
                $fechaHoraCita = new DateTimeImmutable($datosHorario['fecha'] . ' ' . $datosHorario['hora_inicio'], $zonaBogota);
                if ($fechaHoraCita <= new DateTimeImmutable('now', $zonaBogota)) {
                    throw new RuntimeException('No se pueden reservar citas en una fecha u hora que ya pasó. Actualice la página y elija otro horario.');
                }
                $pdo->prepare('INSERT INTO cita (id_afiliado,id_servicio,id_horario,id_estado) VALUES (?,?,?,?)')->execute([$afiliado,$servicio,$horario,$estado]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            $mensaje = 'Cita reservada. El valor se calculó automáticamente y el horario quedó ocupado.';
        } elseif ($accion === 'actualizar_cita') {
            $idCita = filter_var($_POST['id_cita'] ?? null, FILTER_VALIDATE_INT);
            $idAfiliadoCita = filter_var($_POST['id_afiliado'] ?? null, FILTER_VALIDATE_INT);
            $idServicioCita = filter_var($_POST['id_servicio'] ?? null, FILTER_VALIDATE_INT);
            $nuevoHorarioCita = filter_var($_POST['id_horario'] ?? null, FILTER_VALIDATE_INT);
            if (!$idCita || !$idAfiliadoCita || !$idServicioCita || !$nuevoHorarioCita) throw new RuntimeException('Carga la cita por su ID y completa afiliado, servicio y horario.');
            $pdo->beginTransaction();
            try {
                $stmtCitaActual = $pdo->prepare("SELECT c.id_horario,e.nombre AS estado FROM cita c JOIN estado e USING(id_estado) WHERE c.id_cita=? FOR UPDATE OF c");
                $stmtCitaActual->execute([$idCita]);
                $citaActual = $stmtCitaActual->fetch();
                if (!$citaActual) throw new RuntimeException('No se encontró una cita con ese ID.');
                if ($citaActual['estado'] !== 'Programada') throw new RuntimeException('Solo se pueden actualizar citas que siguen programadas.');
                $horarioAnterior = (int)$citaActual['id_horario'];
                $stmtHorarioNuevo = $pdo->prepare("SELECT h.fecha,h.hora_inicio,s.nombre AS estado,s.ambito FROM horario h JOIN estado s USING(id_estado) WHERE h.id_horario=? FOR UPDATE OF h");
                $stmtHorarioNuevo->execute([$nuevoHorarioCita]);
                $slotNuevo = $stmtHorarioNuevo->fetch();
                if (!$slotNuevo || $slotNuevo['ambito'] !== 'horario' || ($nuevoHorarioCita !== $horarioAnterior && $slotNuevo['estado'] !== 'Disponible')) {
                    throw new RuntimeException('El nuevo horario ya no está disponible. Actualiza la página y elige otro.');
                }
                $zonaBogota = new DateTimeZone('America/Bogota');
                $fechaHoraNueva = new DateTimeImmutable($slotNuevo['fecha'] . ' ' . $slotNuevo['hora_inicio'], $zonaBogota);
                if ($fechaHoraNueva <= new DateTimeImmutable('now', $zonaBogota)) throw new RuntimeException('La cita debe quedar en una fecha y hora futuras.');
                $stmtActualizarCita = $pdo->prepare('UPDATE cita SET id_afiliado=?,id_servicio=?,id_horario=? WHERE id_cita=?');
                $stmtActualizarCita->execute([$idAfiliadoCita,$idServicioCita,$nuevoHorarioCita,$idCita]);
                if ($nuevoHorarioCita !== $horarioAnterior) {
                    $idDisponible = $pdo->query("SELECT id_estado FROM estado WHERE ambito='horario' AND nombre='Disponible'")->fetchColumn();
                    $idOcupado = $pdo->query("SELECT id_estado FROM estado WHERE ambito='horario' AND nombre='Ocupado'")->fetchColumn();
                    if (!$idDisponible || !$idOcupado) throw new RuntimeException('Faltan estados de horario en la base de datos.');
                    $pdo->prepare('UPDATE horario SET id_estado=? WHERE id_horario=?')->execute([$idDisponible,$horarioAnterior]);
                    $pdo->prepare('UPDATE horario SET id_estado=? WHERE id_horario=?')->execute([$idOcupado,$nuevoHorarioCita]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            $mensaje = 'Cita actualizada. El identificador se conservó.';
        } elseif ($accion === 'atender_cita') {
            $id = filter_var($_POST['id_cita'] ?? null, FILTER_VALIDATE_INT);
            $pdo->prepare("UPDATE cita SET id_estado=(SELECT id_estado FROM estado WHERE ambito='cita' AND nombre='Atendida') WHERE id_cita=?")->execute([$id]);
            $mensaje = 'Cita marcada como atendida.';
        } elseif ($accion === 'eliminar_cita') {
            $id = filter_var($_POST['id_cita'] ?? null, FILTER_VALIDATE_INT);
            $pdo->prepare('DELETE FROM cita WHERE id_cita=?')->execute([$id]);
            $mensaje = 'Cita eliminada y horario liberado.';
        }
    } catch (Throwable $e) {
        $error = $e instanceof PDOException
            ? (in_array(($accion ?? ''), ['crear_cita','actualizar_cita'], true) ? 'No se guardó la cita: el horario dejó de estar disponible o alguno de los registros ya no existe. Actualiza la página y revisa la selección.' : 'No se guardó el cambio. Verifique que el documento no esté repetido y que los registros seleccionados existan.')
            : $e->getMessage();
    }
}
$tipos = $pdo->query('SELECT id_tipo_doc,sigla FROM tipo_documento ORDER BY sigla')->fetchAll();
$tarifas = $pdo->query('SELECT id_tarifa,nombre,ingreso_min,ingreso_max,porcentaje_cobro FROM tarifa ORDER BY id_tarifa')->fetchAll();
$afiliados = $pdo->query("SELECT a.id_afiliado,a.num_doc,a.nombre1,a.apellido1,a.telefono,a.correo,t.sigla,ta.nombre AS tarifa FROM afiliado a JOIN tipo_documento t USING(id_tipo_doc) JOIN tarifa ta USING(id_tarifa) ORDER BY a.apellido1,a.nombre1")->fetchAll();
$servicios = $pdo->query('SELECT id_servicio,nombre,costo_base FROM servicio ORDER BY nombre')->fetchAll();
$especialidades = $pdo->query('SELECT id_especialidad,nombre FROM especialidad ORDER BY nombre')->fetchAll();
$consultorios = $pdo->query("SELECT c.id_consultorio,c.nombre,c.ubicacion,c.capacidad,e.nombre AS estado FROM consultorio c JOIN estado e USING(id_estado) ORDER BY c.nombre")->fetchAll();
$medicos = $pdo->query("SELECT m.id_medico,m.id_tipo_doc,m.num_documento,m.nombre1,m.nombre2,m.apellido1,m.apellido2,m.correo,m.telefono,m.id_especialidad,m.id_consultorio,t.sigla,e.nombre AS especialidad,COALESCE(c.nombre,'Sin asignar') AS consultorio FROM medico m JOIN tipo_documento t USING(id_tipo_doc) JOIN especialidad e USING(id_especialidad) LEFT JOIN consultorio c USING(id_consultorio) ORDER BY m.apellido1,m.nombre1")->fetchAll();
$agendaHorarios = $pdo->query("SELECT h.id_horario,h.id_medico,h.fecha,h.hora_inicio,h.hora_fin,e.nombre AS estado,m.nombre1 || ' ' || m.apellido1 AS medico FROM horario h JOIN medico m USING(id_medico) JOIN estado e USING(id_estado) WHERE h.fecha >= CURRENT_DATE ORDER BY h.fecha,h.hora_inicio LIMIT 100")->fetchAll();
$horarios = $pdo->query("SELECT h.id_horario,h.id_medico,h.fecha,h.hora_inicio,h.hora_fin,m.nombre1 || ' ' || m.apellido1 AS medico,e.nombre AS especialidad FROM horario h JOIN medico m USING(id_medico) JOIN especialidad e USING(id_especialidad) JOIN estado s USING(id_estado) WHERE s.ambito='horario' AND s.nombre='Disponible' AND (h.fecha + h.hora_inicio) > (CURRENT_TIMESTAMP AT TIME ZONE 'America/Bogota') ORDER BY h.fecha,h.hora_inicio")->fetchAll();
$medicosHorario = [];
foreach ($horarios as $slot) $medicosHorario[$slot['id_medico']] = $slot['medico'] . ' · ' . $slot['especialidad'];
$citas = $pdo->query("SELECT c.id_cita,a.nombre1 || ' ' || a.apellido1 AS afiliado,s.nombre AS servicio,h.fecha,h.hora_inicio,m.nombre1 || ' ' || m.apellido1 AS medico,c.valor_cobrado,e.nombre AS estado FROM cita c JOIN afiliado a USING(id_afiliado) JOIN servicio s USING(id_servicio) JOIN horario h USING(id_horario) JOIN medico m USING(id_medico) JOIN estado e ON e.id_estado = c.id_estado ORDER BY h.fecha,h.hora_inicio")->fetchAll();
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Agenda médica | EPS Villavicencio</title>
<style>
:root{--ink:#233746;--muted:#71828c;--line:#e3e9e8;--paper:#fff;--canvas:#f5f7f4;--teal:#187d78;--teal-dark:#126660;--mint:#e6f3ef;--gold:#f2c36b;--nav:#173b40;--red:#a8483e;--shadow:0 10px 30px rgba(27,62,57,.07)}*{box-sizing:border-box}body{margin:0;background:var(--canvas);color:var(--ink);font:15px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}.shell{min-height:100vh;display:grid;grid-template-columns:248px 1fr}.sidebar{background:var(--nav);color:#dceae7;padding:26px 17px;display:flex;flex-direction:column;gap:32px}.brand{display:flex;align-items:center;gap:12px;padding:3px 9px}.brand-mark{width:40px;height:40px;border-radius:14px;background:#d6eee8;color:var(--teal-dark);display:grid;place-items:center;font-size:22px;font-weight:800}.brand strong{display:block;color:white;font-size:15px;letter-spacing:.02em}.brand small{display:block;color:#a8c0bc;font-size:11px;margin-top:2px}.nav-label{color:#89aaa4;text-transform:uppercase;font-size:10px;font-weight:750;letter-spacing:.13em;padding:0 12px;margin-bottom:7px}.nav-list{display:grid;gap:5px}.nav-link{width:100%;display:flex;align-items:center;gap:12px;border:0;color:#c6d8d4;background:transparent;padding:11px 12px;border-radius:11px;text-align:left;font:inherit;cursor:pointer;transition:.18s}.nav-link:hover,.nav-link.active{background:#285650;color:white}.nav-link .icon{width:22px;text-align:center;font-size:17px}.side-note{margin-top:auto;padding:15px;border:1px solid #3b625d;border-radius:14px;color:#c6d8d4;font-size:12px}.side-note strong{display:block;color:white;margin-bottom:4px}.main{min-width:0;padding:28px clamp(18px,4vw,52px) 50px}.topbar{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:28px}.eyebrow{color:var(--teal);text-transform:uppercase;letter-spacing:.13em;font-weight:750;font-size:11px}.topbar h1{margin:3px 0 0;font-size:26px;letter-spacing:-.035em}.today{background:#fff;border:1px solid var(--line);border-radius:99px;padding:8px 13px;color:#51666c;font-size:13px}.notice{border:1px solid #c7e5d6;background:#edf8f0;color:#24593d;border-radius:12px;padding:12px 15px;margin:0 0 18px}.notice.error{border-color:#f1d0c8;background:#fff2ef;color:#833b32}.view{display:none;animation:fade .22s ease}.view.active{display:block}@keyframes fade{from{opacity:.35;transform:translateY(4px)}to{opacity:1;transform:none}}.welcome{background:linear-gradient(115deg,#e0f1eb,#eff5e9);border:1px solid #d7e9df;border-radius:20px;padding:27px 30px;display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:20px}.welcome h2{font-size:24px;line-height:1.2;margin:4px 0 8px;letter-spacing:-.03em}.welcome p{color:#5d7474;margin:0}.welcome-art{width:92px;height:92px;flex:none;border-radius:28px;background:#fff9;display:grid;place-items:center;font-size:42px}.button{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:0;border-radius:10px;background:var(--teal);color:white;padding:10px 15px;font:inherit;font-weight:600;font-size:14px;cursor:pointer;text-decoration:none;transition:.16s}.button:hover{background:var(--teal-dark);transform:translateY(-1px)}.button.light{background:white;color:var(--teal-dark);border:1px solid #cde1db}.button.muted{background:#e8efed;color:#385a59}.button.danger{background:#a8483e}.button.small{padding:7px 10px;font-size:12px}.stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:18px 0 22px}.stat,.panel,.card{background:var(--paper);border:1px solid var(--line);border-radius:16px;box-shadow:var(--shadow)}.stat{padding:17px 19px;display:flex;align-items:center;gap:14px}.stat-icon{height:43px;width:43px;border-radius:13px;background:var(--mint);display:grid;place-items:center;font-size:20px}.stat small{color:var(--muted);display:block;font-size:12px}.stat strong{font-size:23px;line-height:1.2}.panel{padding:20px 21px;margin-bottom:17px}.panel-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:15px}.panel h2,.card h2{font-size:17px;margin:0;letter-spacing:-.015em}.panel-head p{color:var(--muted);font-size:12px;margin:2px 0 0}.columns{display:grid;grid-template-columns:1.1fr .9fr;gap:16px}.card{padding:20px;margin-bottom:16px}.card p.help{margin:5px 0 14px;color:var(--muted);font-size:13px}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 13px}.field.full{grid-column:1/-1}label{display:block;font-size:12px;font-weight:700;color:#50636b;margin:10px 0 5px}input,select{width:100%;border:1px solid #d9e2df;border-radius:9px;background:#fff;padding:10px 11px;color:var(--ink);font:inherit;font-size:14px;outline:0;transition:.15s}input:focus,select:focus{border-color:#68aaa0;box-shadow:0 0 0 3px #d9efeb}input::placeholder{color:#a7b3b3}.form-actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:13px}.table-wrap{overflow:auto;border:1px solid var(--line);border-radius:12px}table{border-collapse:collapse;width:100%;min-width:660px;font-size:13px}th,td{text-align:left;border-bottom:1px solid var(--line);padding:11px 12px;vertical-align:middle}th{background:#f6f9f7;color:#607177;font-size:11px;text-transform:uppercase;letter-spacing:.06em;white-space:nowrap}tbody tr:last-child td{border-bottom:0}tbody tr:hover{background:#fbfdfc}.person{font-weight:650}.subtext{display:block;font-size:11px;color:var(--muted)}.badge{display:inline-flex;background:#e5f4ec;color:#2b704a;border-radius:99px;padding:4px 9px;font-size:11px;font-weight:700}.badge.done{background:#e9eef4;color:#526579}.toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}.search{max-width:300px}.empty{padding:22px;text-align:center;color:var(--muted)}.intro{margin:-6px 0 18px;color:var(--muted)}.mobile-toggle{display:none}.footer-note{font-size:12px;color:#77878a;text-align:center;padding:12px}
@media(max-width:900px){.shell{grid-template-columns:205px 1fr}.sidebar{padding:22px 12px}.main{padding:24px 22px}.columns{grid-template-columns:1fr}}
@media(max-width:680px){.shell{display:block}.sidebar{padding:12px;gap:10px;position:sticky;top:0;z-index:5}.brand{padding:0 5px}.brand-mark{width:34px;height:34px}.sidebar .nav-label,.side-note{display:none}.nav-list{display:flex;overflow:auto}.nav-link{justify-content:center;white-space:nowrap;padding:8px 10px;font-size:12px}.nav-link .icon{display:none}.main{padding:19px 14px 35px}.topbar{margin-bottom:17px}.topbar h1{font-size:22px}.today{font-size:11px}.welcome{padding:20px;border-radius:16px}.welcome h2{font-size:21px}.welcome-art{width:62px;height:62px;border-radius:20px;font-size:30px}.stats{gap:8px}.stat{padding:12px;display:block}.stat-icon{width:35px;height:35px;margin-bottom:8px}.stat strong{font-size:19px}.stat small{font-size:10px}.form-grid{grid-template-columns:1fr}.field.full{grid-column:auto}.toolbar{align-items:stretch;flex-direction:column}.search{max-width:none}.panel,.card{padding:15px}}

#view-afiliados .columns{grid-template-columns:minmax(0,1fr);max-width:980px;margin:0 auto 20px;gap:14px}
.brand-mark{width:46px;height:46px;flex:0 0 46px;font-size:25px}
@media(max-width:680px){.brand-mark{width:38px;height:38px;flex-basis:38px;font-size:22px}}
#view-afiliados .card{padding:clamp(18px,2.4vw,28px)}
.field-help{display:block;margin:6px 0 4px;color:#61777b;font-size:12px;line-height:1.5}
.update-card{padding:0!important;overflow:hidden}
.update-card summary{display:flex;align-items:center;justify-content:space-between;gap:14px;list-style:none;cursor:pointer;padding:19px 22px}
.update-card summary::-webkit-details-marker{display:none}
.update-card summary strong{font-size:16px;color:#203d42}
.update-card summary span{font-size:13px;color:#61777b}
.update-card summary::after{content:'＋';display:grid;place-items:center;width:32px;height:32px;border-radius:10px;background:#e6f3ef;color:#16766e;font-size:20px;flex:none}
.update-card[open] summary::after{content:'−'}
.update-card[open]>:not(summary){margin-left:22px;margin-right:22px}
.update-card[open]>form{margin-bottom:22px}
@media(max-width:680px){.update-card summary{align-items:flex-start;padding:16px}.update-card summary span{display:none}.update-card[open]>:not(summary){margin-left:16px;margin-right:16px}.update-card[open]>form{margin-bottom:16px}}
/* Ajustes visuales y de facilidad de uso */
:root{--ink:#18343b;--muted:#61777b;--line:#dce8e4;--paper:#fff;--canvas:#f2f7f4;--teal:#16766e;--teal-dark:#105e58;--mint:#e5f3ed;--nav:#123d3b;--shadow:0 12px 34px rgba(20,61,54,.075)}
body{background:var(--canvas);color:var(--ink)}
.shell{grid-template-columns:260px minmax(0,1fr)}
.sidebar{position:sticky;top:0;height:100vh;min-height:620px;padding:30px 18px;background:linear-gradient(165deg,#123d3b,#173b40 62%,#1c4843)}
.brand{padding:5px 10px 14px}.brand-mark{box-shadow:0 6px 18px #061f1e55}
.nav-list{gap:7px}.nav-link{min-height:46px;border:1px solid transparent;color:#d2e3df}.nav-link:hover{background:#ffffff12}.nav-link.active{background:#e6f3ef;color:#155f59;border-color:#d6ebe3;font-weight:700;box-shadow:0 5px 16px #071f1d22}
.main{width:100%;max-width:1580px;margin:0 auto;padding:32px clamp(20px,4vw,58px) 54px}
.topbar{margin-bottom:25px}.topbar h1{font-size:clamp(23px,2.1vw,30px);color:#18343b}.today{padding:9px 14px;background:#fff;color:#496267;font-weight:600}
.view.active{animation:fade .18s ease}.welcome{min-height:220px;padding:32px 36px;border-radius:22px;background:radial-gradient(circle at 92% 15%,#ffffffa8 0 8%,transparent 8.3%),linear-gradient(115deg,#dcefe8,#f0f5e9);box-shadow:inset 0 0 0 1px #d8e9e1}
.welcome h2{font-size:clamp(25px,2.5vw,34px);max-width:540px}.welcome-art{width:104px;height:104px;font-size:46px;color:var(--teal);box-shadow:0 8px 24px #245e5012}
.stats{gap:16px;margin:20px 0 24px}.stat{min-height:100px;padding:19px 21px;transition:transform .18s,box-shadow .18s}.stat:hover,.card:hover,.panel:hover{box-shadow:0 15px 36px rgba(27,62,57,.10)}.stat-icon{color:var(--teal-dark)}.stat strong{color:#18343b}
.panel,.card{border-radius:18px;box-shadow:var(--shadow)}.panel{padding:23px}.card{padding:22px}.columns{align-items:start;gap:18px}.panel-head{margin-bottom:19px}.panel-head h2,.panel h2,.card h2{color:#203d42}.panel h2,.card h2{font-size:18px}.intro{font-size:15px;line-height:1.65;margin:0 0 18px;max-width:850px}.help{line-height:1.55}
label{font-size:13px;color:#3f5c60;margin:13px 0 6px}input,select{min-height:43px;border-color:#d5e2de;border-radius:10px;padding:10px 12px;background-color:#fff}input:hover,select:hover{border-color:#a8c7bd}input:focus,select:focus{border-color:#4a9b8e;box-shadow:0 0 0 4px #d9efeb}input:focus-visible,select:focus-visible,button:focus-visible,a:focus-visible{outline:3px solid #79bdb0;outline-offset:2px}
.form-grid{column-gap:16px}.field{min-width:0}.field.full{grid-column:1/-1}.form-actions{gap:10px;margin-top:17px}.button{min-height:42px;padding:10px 16px;box-shadow:0 3px 8px #164f4812}.button.light{box-shadow:none}.button:disabled{opacity:.48;cursor:not-allowed;transform:none}.button.danger:hover{background:#863a32}
.notice{border-radius:12px;padding:13px 16px;box-shadow:0 4px 16px #1d55430c}.notice.error{color:#7c3028}
.table-wrap{border-color:#e1eae6}table{font-size:13px}th{position:sticky;top:0;z-index:1;background:#f1f7f4;color:#496166}td{line-height:1.45}tbody tr:hover{background:#f4faf7}.badge{white-space:nowrap}.toolbar{margin-bottom:17px}.search{max-width:340px}.empty{padding:30px 20px;background:#f8fbf9;border-radius:12px}
@media(max-width:900px){.shell{grid-template-columns:220px minmax(0,1fr)}.sidebar{padding:25px 13px}.main{padding:25px 20px 42px}.columns{grid-template-columns:1fr}}
@media(max-width:680px){.sidebar{height:auto;min-height:0;padding:10px 12px;gap:9px;background:#123d3b}.brand{padding:0 4px 2px}.nav-list{gap:6px;padding-bottom:2px}.nav-link{min-height:40px;border-radius:10px}.nav-link.active{background:#e6f3ef;color:#155f59}.main{padding:18px 14px 34px}.topbar{align-items:flex-start;margin-bottom:17px}.topbar h1{font-size:22px}.today{padding:7px 10px}.welcome{min-height:0;padding:22px;border-radius:17px}.welcome h2{font-size:23px}.welcome-art{width:60px;height:60px;border-radius:19px;font-size:29px}.stats{gap:9px;margin:14px 0 18px}.stat{min-height:0;padding:12px;border-radius:13px}.stat-icon{margin-bottom:7px}.panel,.card{padding:16px;border-radius:15px}.columns{gap:12px}.panel-head{align-items:flex-start}.form-grid{grid-template-columns:1fr;gap:0}.field.full{grid-column:auto}.form-actions .button{flex:1}.toolbar{gap:9px}}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{scroll-behavior:auto!important;animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important}}



.admin-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;align-items:start}
.admin-card{min-width:0}.admin-card>h2{margin-bottom:3px}.admin-edit{margin:18px 0;padding:12px 14px;border:1px solid var(--line);border-radius:12px;background:#f9fcfa}.admin-edit summary{cursor:pointer;font-weight:700;color:var(--teal-dark)}.admin-edit form{padding-top:9px}.compact-table{min-width:520px;font-size:12px}
@media(max-width:950px){.admin-grid{grid-template-columns:1fr}}
:root{--ink:#243b4a;--muted:#6b7c88;--line:#dbe7ed;--paper:#fff;--canvas:#f5fbff;--teal:#087db4;--teal-dark:#075987;--mint:#eaf6fc;--nav:#064d73;--shadow:0 12px 34px rgba(13,74,111,.08)}
body{background:var(--canvas);color:var(--ink)}
.sidebar{background:linear-gradient(165deg,#064d73,#075987 62%,#0a668c)}
.brand-mark{background:#d8eff9;color:var(--teal-dark)}.brand strong{color:#fff}.brand small{color:#c3deea}
.nav-label{color:#b4d5e4}.nav-link{color:#d7e9f1}.nav-link:hover{background:#ffffff18}.nav-link.active,.nav-link.active:hover{background:#eaf6fc;color:#075987;border-color:#d6eaf4;box-shadow:0 5px 16px #052e4828}
.side-note{border-color:#4c7f98;color:#d4e8f0}.side-note strong{color:#fff}
.eyebrow{color:var(--teal)}.topbar h1,.welcome h2,.stat strong{color:var(--ink)}.today{border-color:var(--line);color:#496273}
.welcome{background:radial-gradient(circle at 92% 15%,#ffffffb8 0 8%,transparent 8.3%),linear-gradient(115deg,#dceff9,#f1f8fc);box-shadow:inset 0 0 0 1px #d5e9f3}.welcome p{color:#5d7482}.welcome-art{color:var(--teal);box-shadow:0 8px 24px #175d7d16}
.stat-icon{background:var(--mint);color:var(--teal-dark)}.panel h2,.card h2,.panel-head h2{color:#23475d}
label{color:#496273}input,select{border-color:#d5e2e9}input:hover,select:hover{border-color:#9dc8dc}input:focus,select:focus{border-color:#1686b7;box-shadow:0 0 0 4px #d9f1fb}input:focus-visible,select:focus-visible,button:focus-visible,a:focus-visible{outline-color:#63b8dc}
.button{box-shadow:0 3px 8px #07598718}.button.light{color:var(--teal-dark);border-color:#cfe2eb}.button.muted{background:#e8f1f6;color:#31566c}
.notice{border-color:#c7e2ef;background:#edf8fd;color:#24566e}.notice.error{border-color:#f1d0c8;background:#fff2ef;color:#7c3028}
th{background:#f0f7fb;color:#496273}tbody tr:hover{background:#f4faff}.empty{background:#f7fbfe}.admin-edit{background:#f8fcfe}.admin-edit summary{color:var(--teal-dark)}
.update-card summary::after{background:#eaf6fc;color:var(--teal-dark)}
@media(max-width:680px){.sidebar{background:linear-gradient(165deg,#064d73,#075987)}}
</style></head><body>
<div class="shell">
<aside class="sidebar"><div class="brand"><div class="brand-mark">+</div><div><strong>EPS VILLAVICENCIO</strong><small>Administración</small></div></div><div><div class="nav-label">Menú principal</div><nav class="nav-list"><button class="nav-link active" data-view="inicio"><span class="icon">⌂</span>Resumen</button><button class="nav-link" data-view="afiliados"><span class="icon">♙</span>Afiliados</button><button class="nav-link" data-view="gestion"><span class="icon">⚙</span>Médicos y salas</button><button class="nav-link" data-view="agendar"><span class="icon">＋</span>Agendar cita</button><button class="nav-link" data-view="citas"><span class="icon">▦</span>Citas</button></nav></div><div class="side-note"><strong>Atención organizada</strong>Consulta la agenda y administra las citas desde un solo lugar.</div></aside>
<main class="main"><header class="topbar"><div><div class="eyebrow">Sistema de atención</div><h1 id="pageTitle">Resumen general</h1></div><div style="display:flex;align-items:center;gap:10px"><div class="today" id="todayLabel"></div><?php if($publicPassword!==''): ?><a class="button light small" href="/?salir=1">Cerrar sesión</a><?php endif ?></div></header>
<?php if($mensaje): ?><div class="notice">✓ &nbsp;<?=h($mensaje)?></div><?php endif ?><?php if($error): ?><div class="notice error">! &nbsp;<?=h($error)?></div><?php endif ?>
<section class="view active" id="view-inicio"><div class="welcome"><div><div class="eyebrow">Bienvenido al sistema</div><h2>Tu agenda médica,<br>más clara y organizada.</h2><p>Administra afiliados y reserva citas en pocos pasos.</p><div class="form-actions"><button class="button" data-go="agendar">＋ Agendar una cita</button><button class="button light" data-go="afiliados">Ver afiliados</button></div></div><div class="welcome-art">🩺</div></div>
<div class="stats"><div class="stat"><div class="stat-icon">♙</div><div><small>Afiliados registrados</small><strong><?=count($afiliados)?></strong></div></div><div class="stat"><div class="stat-icon">▦</div><div><small>Citas en el sistema</small><strong><?=count($citas)?></strong></div></div><div class="stat"><div class="stat-icon">◷</div><div><small>Horarios disponibles</small><strong><?=count($horarios)?></strong></div></div></div>
<div class="panel"><div class="panel-head"><div><h2>Próximas citas</h2><p>Agenda actual de atención</p></div><button class="button light small" data-go="citas">Ver todas</button></div><?php if(!$citas): ?><div class="empty">Aún no hay citas registradas. Puedes agendar la primera.</div><?php else: ?><div class="table-wrap"><table><thead><tr><th>Afiliado</th><th>Servicio</th><th>Fecha y hora</th><th>Profesional</th><th>Estado</th></tr></thead><tbody><?php foreach(array_slice($citas,0,5) as $x): ?><tr><td class="person"><?=h($x['afiliado'])?></td><td><?=h($x['servicio'])?></td><td><?=h($x['fecha'].' · '.substr((string)$x['hora_inicio'],0,5))?></td><td><?=h($x['medico'])?></td><td><span class="badge <?=($x['estado']==='Atendida'?'done':'')?>"><?=h($x['estado'])?></span></td></tr><?php endforeach ?></tbody></table></div><?php endif ?></div></section>
<section class="view" id="view-afiliados"><p class="intro">Registra nuevos afiliados, actualiza su información y consulta el directorio.</p><div class="columns"><div class="card"><h2>Registrar afiliado</h2><p class="help">Los campos con * son obligatorios.</p><form method="post"><input type="hidden" name="accion" value="crear_afiliado"><?=csrfField()?><div class="form-grid"><div class="field"><label>Tipo de documento *</label><select name="id_tipo_doc" required data-document-type><?php foreach($tipos as $x): ?><option value="<?=$x['id_tipo_doc']?>"><?=h($x['sigla'])?></option><?php endforeach ?></select></div><div class="field"><label>Número de documento *</label><input name="num_doc" required maxlength="20" inputmode="numeric" data-document-number pattern="[0-9]+" title="Para CC, TI y RC ingresa solo números" placeholder="Ej. 1000000000"></div><div class="field"><label>Primer nombre *</label><input name="nombre1" required maxlength="50"></div><div class="field"><label>Segundo nombre</label><input name="nombre2" maxlength="50"></div><div class="field"><label>Primer apellido *</label><input name="apellido1" required maxlength="50"></div><div class="field"><label>Segundo apellido</label><input name="apellido2" maxlength="50"></div><div class="field"><label>Fecha de nacimiento *</label><input type="date" name="fecha_nacimiento" max="<?=date('Y-m-d')?>" required></div><div class="field"><label>Ingreso mensual (COP) *</label><input type="number" name="ingreso_mensual" min="0" step="0.01" required><small class="field-help">Escribe cuánto recibe al mes en pesos colombianos. Usa este valor para elegir la categoría de tarifa.</small></div><div class="field full"><label>Tarifa *</label><select name="id_tarifa" required><?php foreach($tarifas as $x): ?><option value="<?=$x['id_tarifa']?>"><?=h($x['nombre'])?> · $<?=number_format((float)$x['ingreso_min'],0,',','.')?> a $<?=number_format((float)$x['ingreso_max'],0,',','.')?> COP · <?=number_format((float)$x['porcentaje_cobro'],0,',','.')?>% del costo</option><?php endforeach ?></select></div><div class="field"><label>Teléfono</label><input type="tel" name="telefono" maxlength="20" pattern="[0-9+() .-]+" title="Usa números, espacios o los signos +, paréntesis y guion"></div><div class="field"><label>Correo</label><input type="email" name="correo" maxlength="100"></div><div class="field"><label>Sexo</label><select name="sexo"><option value="">Prefiero no especificar</option><option value="M">Masculino (M)</option><option value="F">Femenino (F)</option><option value="NB">No binario (NB)</option><option value="T">Transgénero (T)</option><option value="I">Intersexual (I)</option><option value="O">Otro (O)</option><option value="PND">Prefiero no decir</option></select></div><div class="field"><label>Dirección</label><input name="direccion" maxlength="150"></div></div><div class="form-actions"><button class="button">Guardar afiliado</button></div></form></div>
<details class="card update-card"><summary><strong>Actualizar afiliado</strong><span>Abre esta sección para editar un registro existente.</span></summary><p class="help">Ingresa el ID visible en el directorio y los datos actualizados.</p><form method="post"><input type="hidden" name="accion" value="actualizar_afiliado"><?=csrfField()?><div class="form-grid"><div class="field full"><label>ID afiliado *</label><input type="number" name="id_afiliado" min="1" required placeholder="ID del directorio"></div><div class="field"><label>Tipo documento *</label><select name="id_tipo_doc" data-document-type><?php foreach($tipos as $x): ?><option value="<?=$x['id_tipo_doc']?>"><?=h($x['sigla'])?></option><?php endforeach ?></select></div><div class="field"><label>Número documento *</label><input name="num_doc" required maxlength="20" inputmode="numeric" data-document-number pattern="[0-9]+" title="Para CC, TI y RC ingresa solo números"></div><div class="field"><label>Primer nombre *</label><input name="nombre1" required></div><div class="field"><label>Primer apellido *</label><input name="apellido1" required></div><div class="field"><label>Fecha nacimiento *</label><input type="date" name="fecha_nacimiento" max="<?=date('Y-m-d')?>" required></div><div class="field"><label>Ingreso mensual (COP) *</label><input type="number" name="ingreso_mensual" min="0" step="0.01" required><small class="field-help">Escribe cuánto recibe al mes en pesos colombianos. Usa este valor para elegir la categoría de tarifa.</small></div><div class="field full"><label>Tarifa *</label><select name="id_tarifa"><?php foreach($tarifas as $x): ?><option value="<?=$x['id_tarifa']?>"><?=h($x['nombre'])?> · $<?=number_format((float)$x['ingreso_min'],0,',','.')?> a $<?=number_format((float)$x['ingreso_max'],0,',','.')?> COP · <?=number_format((float)$x['porcentaje_cobro'],0,',','.')?>% del costo</option><?php endforeach ?></select></div><div class="field"><label>Segundo nombre</label><input name="nombre2"></div><div class="field"><label>Segundo apellido</label><input name="apellido2"></div><div class="field"><label>Teléfono</label><input type="tel" name="telefono" maxlength="20" pattern="[0-9+() .-]+" title="Usa números, espacios o los signos +, paréntesis y guion"></div><div class="field"><label>Correo</label><input type="email" name="correo"></div><div class="field full"><label>Dirección</label><input name="direccion"></div><div class="field full"><label>Sexo</label><select name="sexo"><option value="">Prefiero no especificar</option><option value="M">Masculino (M)</option><option value="F">Femenino (F)</option><option value="NB">No binario (NB)</option><option value="T">Transgénero (T)</option><option value="I">Intersexual (I)</option><option value="O">Otro (O)</option><option value="PND">Prefiero no decir</option></select></div></div><div class="form-actions"><button class="button muted">Actualizar datos</button></div></form></details></div>
<div class="panel"><div class="toolbar"><div><h2>Directorio de afiliados</h2><p class="help">Afiliados registrados en la base de datos</p></div><input class="search" type="search" placeholder="Buscar nombre o documento…" data-filter="affiliates"></div><div class="table-wrap"><table id="affiliatesTable"><thead><tr><th>ID</th><th>Afiliado</th><th>Documento</th><th>Teléfono</th><th>Tarifa</th></tr></thead><tbody><?php foreach($afiliados as $x): ?><tr><td><?=h($x['id_afiliado'])?></td><td class="person"><?=h($x['nombre1'].' '.$x['apellido1'])?></td><td><?=h($x['sigla'].' '.$x['num_doc'])?></td><td><?=h($x['telefono'])?></td><td><?=h($x['tarifa'])?></td></tr><?php endforeach ?></tbody></table></div></div></section>
<section class="view" id="view-gestion">
<p class="intro">Registra y actualiza médicos, consultorios y franjas disponibles. Los horarios ocupados no se pueden editar.</p>
<div class="admin-grid">
<article class="card admin-card"><h2>Médicos</h2><p class="help">Agrega profesionales y asigna especialidad y consultorio.</p>
<form method="post"><input type="hidden" name="accion" value="crear_medico"><?=csrfField()?>
<div class="form-grid">
<div class="field"><label>Tipo de documento *</label><select name="id_tipo_doc" required data-document-type><?php foreach($tipos as $x): ?><option value="<?=$x['id_tipo_doc']?>"><?=h($x['sigla'])?></option><?php endforeach ?></select></div>
<div class="field"><label>Número de documento *</label><input name="num_documento" required maxlength="20" data-document-number pattern="[0-9]+" inputmode="numeric"></div>
<div class="field"><label>Nombre *</label><input name="nombre1" required maxlength="50"></div><div class="field"><label>Segundo nombre</label><input name="nombre2" maxlength="50"></div>
<div class="field"><label>Apellido *</label><input name="apellido1" required maxlength="50"></div><div class="field"><label>Segundo apellido</label><input name="apellido2" maxlength="50"></div>
<div class="field"><label>Especialidad *</label><select name="id_especialidad" required><?php foreach($especialidades as $x): ?><option value="<?=$x['id_especialidad']?>"><?=h($x['nombre'])?></option><?php endforeach ?></select></div>
<div class="field"><label>Consultorio</label><select name="id_consultorio"><option value="">Sin asignar</option><?php foreach($consultorios as $x): ?><option value="<?=$x['id_consultorio']?>"><?=h($x['nombre'])?></option><?php endforeach ?></select></div>
<div class="field"><label>Teléfono</label><input type="tel" name="telefono" maxlength="20" pattern="[0-9+() .-]+"></div><div class="field"><label>Correo</label><input type="email" name="correo" maxlength="100"></div>
</div><div class="form-actions"><button class="button">Registrar médico</button></div></form>
<details class="admin-edit"><summary>Actualizar médico</summary><form method="post"><input type="hidden" name="accion" value="actualizar_medico"><?=csrfField()?>
<label>ID del médico *</label><input type="number" name="id_medico" min="1" required>
<div class="form-grid"><div class="field"><label>Tipo de documento *</label><select name="id_tipo_doc" required data-document-type><?php foreach($tipos as $x): ?><option value="<?=$x['id_tipo_doc']?>"><?=h($x['sigla'])?></option><?php endforeach ?></select></div><div class="field"><label>Número de documento *</label><input name="num_documento" required maxlength="20" data-document-number pattern="[0-9]+" inputmode="numeric"></div><div class="field"><label>Nombre *</label><input name="nombre1" required maxlength="50"></div><div class="field"><label>Segundo nombre</label><input name="nombre2" maxlength="50"></div><div class="field"><label>Apellido *</label><input name="apellido1" required maxlength="50"></div><div class="field"><label>Segundo apellido</label><input name="apellido2" maxlength="50"></div><div class="field"><label>Especialidad *</label><select name="id_especialidad" required><?php foreach($especialidades as $x): ?><option value="<?=$x['id_especialidad']?>"><?=h($x['nombre'])?></option><?php endforeach ?></select></div><div class="field"><label>Consultorio</label><select name="id_consultorio"><option value="">Sin asignar</option><?php foreach($consultorios as $x): ?><option value="<?=$x['id_consultorio']?>"><?=h($x['nombre'])?></option><?php endforeach ?></select></div><div class="field"><label>Teléfono</label><input type="tel" name="telefono" maxlength="20" pattern="[0-9+() .-]+"></div><div class="field"><label>Correo</label><input type="email" name="correo" maxlength="100"></div></div><div class="form-actions"><button class="button muted">Guardar cambios</button></div></form></details>
<div class="table-wrap"><table class="compact-table"><thead><tr><th>ID</th><th>Médico</th><th>Documento</th><th>Especialidad</th><th>Consultorio</th></tr></thead><tbody><?php foreach($medicos as $x): ?><tr><td><?=h($x['id_medico'])?></td><td><?=h($x['nombre1'].' '.$x['apellido1'])?></td><td><?=h($x['sigla'].' '.$x['num_documento'])?></td><td><?=h($x['especialidad'])?></td><td><?=h($x['consultorio'])?></td></tr><?php endforeach ?></tbody></table></div></article>
<article class="card admin-card"><h2>Consultorios y salas</h2><p class="help">Administra el nombre, la ubicación y la capacidad.</p>
<form method="post"><input type="hidden" name="accion" value="crear_consultorio"><?=csrfField()?>
<label>Nombre del consultorio o sala *</label><input name="nombre_consultorio" required maxlength="50"><label>Ubicación *</label><input name="ubicacion" required maxlength="100" placeholder="Ej. Piso 2, sede principal"><label>Capacidad *</label><input type="number" name="capacidad" min="0" step="1" required><div class="form-actions"><button class="button">Registrar consultorio</button></div></form>
<details class="admin-edit"><summary>Actualizar consultorio</summary><form method="post"><input type="hidden" name="accion" value="actualizar_consultorio"><?=csrfField()?>
<label>ID del consultorio *</label><input type="number" name="id_consultorio" min="1" required><label>Nombre *</label><input name="nombre_consultorio" required maxlength="50"><label>Ubicación *</label><input name="ubicacion" required maxlength="100"><label>Capacidad *</label><input type="number" name="capacidad" min="0" step="1" required><div class="form-actions"><button class="button muted">Guardar cambios</button></div></form></details>
<div class="table-wrap"><table class="compact-table"><thead><tr><th>ID</th><th>Consultorio</th><th>Ubicación</th><th>Capacidad</th><th>Estado</th></tr></thead><tbody><?php foreach($consultorios as $x): ?><tr><td><?=h($x['id_consultorio'])?></td><td><?=h($x['nombre'])?></td><td><?=h($x['ubicacion'])?></td><td><?=h($x['capacidad'])?></td><td><?=h($x['estado'])?></td></tr><?php endforeach ?></tbody></table></div></article>
<article class="card admin-card"><h2>Horarios</h2><p class="help">Agrega franjas para médicos o modifica una franja que siga disponible. El sistema evita cruces de horario.</p>
<form method="post"><input type="hidden" name="accion" value="crear_horario"><?=csrfField()?>
<label>Médico *</label><select name="id_medico" required><?php foreach($medicos as $x): ?><option value="<?=$x['id_medico']?>"><?=h($x['nombre1'].' '.$x['apellido1'].' · '.$x['especialidad'])?></option><?php endforeach ?></select><label>Fecha *</label><input type="date" name="fecha" min="<?=date('Y-m-d')?>" required><div class="form-grid"><div class="field"><label>Hora de inicio *</label><input type="time" name="hora_inicio" required></div><div class="field"><label>Hora de fin *</label><input type="time" name="hora_fin" required></div></div><div class="form-actions"><button class="button" <?=(!$medicos?'disabled':'')?>>Agregar horario</button></div></form>
<details class="admin-edit"><summary>Actualizar horario disponible</summary><form method="post"><input type="hidden" name="accion" value="actualizar_horario"><?=csrfField()?>
<label>ID del horario *</label><input type="number" name="id_horario" min="1" required><label>Médico *</label><select name="id_medico" required><?php foreach($medicos as $x): ?><option value="<?=$x['id_medico']?>"><?=h($x['nombre1'].' '.$x['apellido1'])?></option><?php endforeach ?></select><label>Fecha *</label><input type="date" name="fecha" min="<?=date('Y-m-d')?>" required><div class="form-grid"><div class="field"><label>Hora de inicio *</label><input type="time" name="hora_inicio" required></div><div class="field"><label>Hora de fin *</label><input type="time" name="hora_fin" required></div></div><div class="form-actions"><button class="button muted">Guardar cambios</button></div></form></details>
<div class="table-wrap"><table class="compact-table"><thead><tr><th>ID</th><th>Fecha</th><th>Horario</th><th>Médico</th><th>Estado</th></tr></thead><tbody><?php foreach($agendaHorarios as $x): ?><tr><td><?=h($x['id_horario'])?></td><td><?=h($x['fecha'])?></td><td><?=h(substr((string)$x['hora_inicio'],0,5).'–'.substr((string)$x['hora_fin'],0,5))?></td><td><?=h($x['medico'])?></td><td><?=h($x['estado'])?></td></tr><?php endforeach ?></tbody></table></div></article>
</div></section>
<section class="view" id="view-agendar"><p class="intro">Selecciona afiliado, servicio y una franja disponible. El sistema calcula el valor y ocupa el horario.</p><div class="columns"><div class="card"><h2>Nueva cita</h2><p class="help">Completa los datos para confirmar la reserva.</p><form method="post"><input type="hidden" name="accion" value="crear_cita"><?=csrfField()?><label>Afiliado *</label><select name="id_afiliado" required><?php foreach($afiliados as $x): ?><option value="<?=$x['id_afiliado']?>"><?=h($x['nombre1'].' '.$x['apellido1'].' · '.$x['num_doc'])?></option><?php endforeach ?></select><label>Servicio médico *</label><select name="id_servicio" required><?php foreach($servicios as $x): ?><option value="<?=$x['id_servicio']?>"><?=h($x['nombre'].' · $'.number_format((float)$x['costo_base'],0,',','.'))?></option><?php endforeach ?></select><p class="help">Selecciona primero el médico, después la fecha y por último la hora.</p><label for="medicoHorario">Médico *</label><select id="medicoHorario" required <?=(!$horarios?'disabled':'')?>><option value="">Selecciona un médico</option><?php foreach($medicosHorario as $idMedico => $nombreMedico): ?><option value="<?=h($idMedico)?>"><?=h($nombreMedico)?></option><?php endforeach ?></select><label for="fechaHorario">Fecha *</label><select id="fechaHorario" required disabled><option value="">Selecciona primero un médico</option></select><label for="horaHorario">Hora disponible *</label><select id="horaHorario" required disabled><option value="">Selecciona primero una fecha</option></select><input type="hidden" name="id_horario" id="idHorarioSeleccionado"><?php if(!$horarios): ?><p class="help">No hay horarios disponibles. Agrega horarios desde los datos de la base de datos.</p><?php endif ?><div class="form-actions"><button class="button" <?=(!$horarios||!$afiliados||!$servicios)?'disabled':''?>>Confirmar cita</button><button class="button light" type="reset">Limpiar</button></div></form></div><div class="card"><h2>Horarios disponibles</h2><p class="help">Franjas futuras habilitadas para reservar.</p><?php if(!$horarios): ?><div class="empty">No hay horarios disponibles por ahora.</div><?php else: ?><div class="table-wrap"><table><thead><tr><th>Fecha y hora</th><th>Médico</th><th>Especialidad</th></tr></thead><tbody><?php foreach(array_slice($horarios,0,12) as $x): ?><tr><td><?=h($x['fecha'].' · '.substr((string)$x['hora_inicio'],0,5))?></td><td><?=h($x['medico'])?></td><td><?=h($x['especialidad'])?></td></tr><?php endforeach ?></tbody></table></div><?php endif ?></div></div></section>
<section class="view" id="view-citas"><p class="intro">Consulta las reservas, marca las atenciones realizadas o elimina una cita para liberar el horario.</p><div class="panel"><div class="toolbar"><div><h2>Agenda de citas</h2><p class="help">Citas registradas y su estado actual</p></div><input class="search" type="search" placeholder="Buscar afiliado, servicio o médico…" data-filter="appointments"></div><?php if(!$citas): ?><div class="empty">Todavía no hay citas registradas.</div><?php else: ?><div class="table-wrap"><table id="appointmentsTable"><thead><tr><th>ID</th><th>Afiliado</th><th>Servicio</th><th>Fecha y hora</th><th>Médico</th><th>Valor</th><th>Estado</th><th>Acciones</th></tr></thead><tbody><?php foreach($citas as $x): ?><tr><td><?=h($x['id_cita'])?></td><td class="person"><?=h($x['afiliado'])?></td><td><?=h($x['servicio'])?></td><td><?=h($x['fecha'].' · '.substr((string)$x['hora_inicio'],0,5))?></td><td><?=h($x['medico'])?></td><td>$<?=number_format((float)$x['valor_cobrado'],0,',','.')?></td><td><span class="badge <?=($x['estado']==='Atendida'?'done':'')?>"><?=h($x['estado'])?></span></td><td><?php if($x['estado']==='Programada'): ?><div class="form-actions"><form method="post"><input type="hidden" name="accion" value="atender_cita"><?=csrfField()?><input type="hidden" name="id_cita" value="<?=$x['id_cita']?>"><button class="button muted small">Atendida</button></form><form method="post" onsubmit="return confirm('¿Eliminar esta cita y liberar el horario?')"><input type="hidden" name="accion" value="eliminar_cita"><?=csrfField()?><input type="hidden" name="id_cita" value="<?=$x['id_cita']?>"><button class="button danger small">Eliminar</button></form></div><?php endif ?></td></tr><?php endforeach ?></tbody></table></div><?php endif ?></div></section>
<div class="footer-note">EPS Villavicencio · Agenda médica</div></main></div>
<dialog id="citaUpdateDialog" style="border:0;border-radius:16px;padding:24px;width:min(620px,calc(100% - 28px));box-shadow:0 20px 70px #123d3b44"><form id="citaUpdateForm" method="post" action="/?admin=1"><input type="hidden" name="accion" value="actualizar_cita"><?=csrfField()?><input type="hidden" name="id_cita"><h2>Actualizar cita programada</h2><p class="help">Escribe el ID y carga la cita. El identificador se conserva y no se modifica.</p><label for="citaIdLookup">ID de la cita</label><div class="form-actions"><input id="citaIdLookup" type="number" min="1" required><button class="button light" type="button" id="loadCitaRecord">Cargar cita</button></div><p id="citaLoadStatus" class="help" aria-live="polite"></p><div class="form-grid"><div><label>Estado actual</label><input name="estado" readonly></div><div><label>Fecha de solicitud</label><input name="fecha_solicitud" readonly></div><div><label>Valor calculado</label><input name="valor_cobrado" readonly></div><div><label>Fecha actual</label><input name="fecha" readonly></div><div><label>Hora actual</label><input name="hora_inicio" readonly></div><div><label>Médico actual</label><input name="nombre_medico" readonly></div></div><label>Afiliado</label><select name="id_afiliado" required><?php foreach($afiliados as $x): ?><option value="<?=h($x['id_afiliado'])?>"><?=h($x['nombre1'].' '.$x['apellido1'].' · '.$x['num_doc'])?></option><?php endforeach ?></select><label>Servicio</label><select name="id_servicio" required><?php foreach($servicios as $x): ?><option value="<?=h($x['id_servicio'])?>"><?=h($x['nombre'])?></option><?php endforeach ?></select><label>Horario</label><select name="id_horario" required><?php foreach($horarios as $x): ?><option value="<?=h($x['id_horario'])?>"><?=h($x['fecha'].' · '.substr((string)$x['hora_inicio'],0,5).' · '.$x['medico'])?></option><?php endforeach ?></select><div class="form-actions"><button class="button" type="submit" disabled>Guardar cambios</button><button class="button muted" type="button" id="closeCitaDialog">Cerrar</button></div></form></dialog>
<script>
const titles={inicio:'Resumen general',afiliados:'Gestión de afiliados',gestion:'Médicos y salas',agendar:'Agendar una cita',citas:'Agenda de citas'};
function showView(name){document.querySelectorAll('.view').forEach(v=>v.classList.toggle('active',v.id==='view-'+name));document.querySelectorAll('.nav-link').forEach(b=>b.classList.toggle('active',b.dataset.view===name));document.getElementById('pageTitle').textContent=titles[name]||titles.inicio;history.replaceState(null,'','#'+name);window.scrollTo({top:0,behavior:'smooth'});}
document.querySelectorAll('[data-view]').forEach(b=>b.addEventListener('click',()=>showView(b.dataset.view)));
document.querySelectorAll('[data-go]').forEach(b=>b.addEventListener('click',()=>showView(b.dataset.go)));
const initial=location.hash.replace('#','');if(titles[initial])showView(initial);
const now=new Date();document.getElementById('todayLabel').textContent=new Intl.DateTimeFormat('es-CO',{weekday:'short',day:'numeric',month:'short',year:'numeric'}).format(now);
const loaderConfig={actualizar_afiliado:{type:'afiliado',id:'id_afiliado'},actualizar_medico:{type:'medico',id:'id_medico'},actualizar_horario:{type:'horario',id:'id_horario'}};
async function loadAdminRecord(type,id,form,idField,status){
 if(!id){status.textContent='Escribe un ID válido para buscar.';return false;}
 status.textContent='Buscando…';
 const url=new URL(location.href);url.search='';url.hash='';url.searchParams.set('admin','1');url.searchParams.set('api',type);url.searchParams.set('id',id);
 try{
  const response=await fetch(url.toString(),{headers:{'Accept':'application/json'}});const data=await response.json();
  if(!response.ok)throw new Error(data.error||'No se encontró el registro.');
  Object.entries(data).forEach(([key,value])=>{
   const field=form.elements.namedItem(key);if(!field||key===idField)return;
   if(field.tagName==='SELECT'&&key==='id_horario'&&![...field.options].some(option=>option.value===String(value))){field.add(new Option('Horario actual · '+data.fecha+' · '+data.hora_inicio+' · médico '+data.id_medico,String(value)));}
   field.value=value??'';
   field.dispatchEvent(new Event('change',{bubbles:true}));
  });
  if(type==='cita')form.elements.namedItem('id_cita').value=data.id_cita;
  const submitButton=form.querySelector('button[type="submit"]');
  const bloqueadaCita=type==='cita'&&data.estado!=='Programada';
  const bloqueadoHorario=type==='horario'&&data.estado!=='Disponible';
  if(submitButton)submitButton.disabled=bloqueadaCita||bloqueadoHorario;
  status.textContent=bloqueadaCita?'La cita se cargó, pero solo se pueden modificar citas programadas.':bloqueadoHorario?'Ese horario no está disponible para actualizar.':'Datos cargados. Revisa el formulario antes de guardar.';
  return !(bloqueadaCita||bloqueadoHorario);
 }catch(error){status.textContent=error.message;return false;}
}
Object.entries(loaderConfig).forEach(([action,config])=>document.querySelectorAll('form').forEach(form=>{
 if(form.querySelector('[name="accion"]')?.value!==action)return;
 const idInput=form.elements.namedItem(config.id);if(!idInput)return;
 const lookup=document.createElement('button');lookup.type='button';lookup.className='button light small';lookup.textContent='Cargar datos';
 const status=document.createElement('span');status.className='subtext';status.setAttribute('aria-live','polite');
 idInput.insertAdjacentElement('afterend',lookup);lookup.insertAdjacentElement('afterend',status);
 const submitButton=form.querySelector('button[type="submit"]');if(submitButton)submitButton.disabled=true;
 const load=async()=>{const requestedId=String(idInput.value);const ok=await loadAdminRecord(config.type,requestedId,form,config.id,status);const currentId=String(idInput.value);form.dataset.loadedId=ok&&currentId===requestedId?requestedId:'';if(submitButton)submitButton.disabled=!ok||currentId!==requestedId;};
 idInput.addEventListener('input',()=>{form.dataset.loadedId='';if(submitButton)submitButton.disabled=true;status.textContent='Carga los datos del ID antes de guardar.';});
 form.addEventListener('submit',event=>{if(form.dataset.loadedId!==idInput.value){event.preventDefault();status.textContent='Carga los datos del ID antes de guardar.';}});
 lookup.addEventListener('click',load);idInput.addEventListener('change',load);idInput.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();load();}});
}));
const citaDialog=document.getElementById('citaUpdateDialog');
const citaToolbar=document.querySelector('#view-citas .toolbar > div');
if(citaDialog&&citaToolbar){const openButton=document.createElement('button');openButton.type='button';openButton.className='button light small';openButton.textContent='Actualizar cita por ID';citaToolbar.append(openButton);openButton.addEventListener('click',()=>citaDialog.showModal());}
document.getElementById('closeCitaDialog')?.addEventListener('click',()=>citaDialog.close());
document.getElementById('loadCitaRecord')?.addEventListener('click',async()=>{const form=document.getElementById('citaUpdateForm');const lookup=document.getElementById('citaIdLookup');const requestedId=String(lookup.value);const submitButton=form.querySelector('button[type="submit"]');const ok=await loadAdminRecord('cita',requestedId,form,'id_cita',document.getElementById('citaLoadStatus'));const currentId=String(lookup.value);form.dataset.loadedId=ok&&currentId===requestedId?requestedId:'';if(submitButton)submitButton.disabled=!ok||currentId!==requestedId||form.elements.namedItem('estado').value!=='Programada';});
document.getElementById('citaIdLookup')?.addEventListener('input',()=>{const form=document.getElementById('citaUpdateForm');form.elements.namedItem('id_cita').value='';form.dataset.loadedId='';form.querySelector('button[type="submit"]').disabled=true;document.getElementById('citaLoadStatus').textContent='Carga la cita antes de guardar.';});
document.getElementById('citaIdLookup')?.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();document.getElementById('loadCitaRecord').click();}});
document.getElementById('citaUpdateForm')?.addEventListener('submit',event=>{const form=document.getElementById('citaUpdateForm');if(!form.elements.namedItem('id_cita').value||form.dataset.loadedId!==document.getElementById('citaIdLookup').value){event.preventDefault();document.getElementById('citaLoadStatus').textContent='Primero carga la cita por su ID.';}});
document.querySelectorAll('form').forEach(form=>{
 const type=form.querySelector('[data-document-type]');
 const number=form.querySelector('[data-document-number]');
 if(!type||!number)return;
 const updateDocumentRule=()=>{
  const code=type.options[type.selectedIndex]?.text.trim().toUpperCase();
  const numeric=['CC','TI','RC'].includes(code);
  number.inputMode=numeric?'numeric':'text';
  number.maxLength=numeric?10:20;
  number.pattern=numeric?'[0-9]+':'[A-Za-z0-9]+';
  number.title=numeric?'Para '+code+' ingresa solo números (máximo 10 dígitos)':'Ingresa solo letras y números (máximo 20 caracteres)';
 };
 type.addEventListener('change',updateDocumentRule);
 updateDocumentRule();
 number.addEventListener('input',()=>{
  const code=type.options[type.selectedIndex]?.text.trim().toUpperCase();
  const numeric=['CC','TI','RC'].includes(code);
  const cleaned=number.value.replace(numeric?/[^0-9]/g:/[^A-Za-z0-9]/g,'');
  if(cleaned!==number.value) number.value=cleaned;
 });
});

document.querySelectorAll('input[name="telefono"]').forEach(input=>{
 input.addEventListener('input',()=>{
  const cleaned=input.value.replace(/[^0-9+() .-]/g,'');
  if(cleaned!==input.value) input.value=cleaned;
 });
});

document.querySelectorAll('input[name^="nombre"],input[name^="apellido"]').forEach(input=>{
 input.title='Escribe solo letras; este campo no admite números.';
 let avisoTimer;
 const avisarNumero=()=>{
  input.setCustomValidity('Este campo solo admite letras. No puedes ingresar números.');
  input.reportValidity();
  clearTimeout(avisoTimer);
  avisoTimer=setTimeout(()=>input.setCustomValidity(''),2200);
 };
 input.addEventListener('beforeinput',event=>{
  if(event.inputType.startsWith('insert')&&event.data&&/[0-9]/.test(event.data)){
   event.preventDefault();
   avisarNumero();
  }
 });
 input.addEventListener('input',()=>{
  const limpio=input.value.replace(/[0-9]/g,'');
  if(limpio!==input.value){input.value=limpio;avisarNumero();}
  else input.setCustomValidity('');
 });
});

const citaForm=document.querySelector('form input[name="accion"][value="crear_cita"]')?.closest('form');
if(citaForm){
 const slots=<?=json_encode($horarios, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)?>;
 const medicoSelect=document.getElementById('medicoHorario');
 const fechaSelect=document.getElementById('fechaHorario');
 const horaSelect=document.getElementById('horaHorario');
 const horarioInput=document.getElementById('idHorarioSeleccionado');
 const submitButton=citaForm.querySelector('.form-actions button:not([type="reset"])');
 const labelFecha=value=>{const [y,m,d]=value.split('-').map(Number);return new Date(y,m-1,d).toLocaleDateString('es-CO',{weekday:'long',day:'numeric',month:'long',year:'numeric'});};
 const placeholder=(select,text)=>select.replaceChildren(new Option(text,''));
 function validarBoton(){if(submitButton)submitButton.disabled=!horarioInput.value||!citaForm.querySelector('[name="id_afiliado"]').value||!citaForm.querySelector('[name="id_servicio"]').value;}
 function cargarFechas(){horarioInput.value='';placeholder(fechaSelect,medicoSelect.value?'Selecciona una fecha':'Selecciona primero un médico');placeholder(horaSelect,'Selecciona primero una fecha');fechaSelect.disabled=!medicoSelect.value;horaSelect.disabled=true;if(medicoSelect.value){[...new Set(slots.filter(s=>String(s.id_medico)===medicoSelect.value).map(s=>String(s.fecha)))].forEach(f=>fechaSelect.add(new Option(labelFecha(f),f)));}validarBoton();}
 function cargarHoras(){horarioInput.value='';placeholder(horaSelect,fechaSelect.value?'Selecciona una hora':'Selecciona primero una fecha');horaSelect.disabled=!fechaSelect.value;if(fechaSelect.value){slots.filter(s=>String(s.id_medico)===medicoSelect.value&&String(s.fecha)===fechaSelect.value).forEach(s=>horaSelect.add(new Option(String(s.hora_inicio).slice(0,5)+' - '+String(s.hora_fin).slice(0,5),String(s.id_horario))));}validarBoton();}
 medicoSelect.addEventListener('change',cargarFechas);fechaSelect.addEventListener('change',cargarHoras);horaSelect.addEventListener('change',()=>{horarioInput.value=horaSelect.value;validarBoton();});
 citaForm.addEventListener('reset',()=>setTimeout(()=>{horarioInput.value='';cargarFechas();},0));
 citaForm.addEventListener('submit',event=>{if(!horarioInput.value){event.preventDefault();horaSelect.focus();}});
 cargarFechas();
}
document.querySelectorAll('[data-filter]').forEach(input=>input.addEventListener('input',()=>{const id=input.dataset.filter==='affiliates'?'affiliatesTable':'appointmentsTable';const table=document.getElementById(id);if(!table)return;const q=input.value.toLocaleLowerCase('es');table.querySelectorAll('tbody tr').forEach(row=>row.hidden=!row.textContent.toLocaleLowerCase('es').includes(q));}));
</script></body></html>


