

\if :{?migrar_existente}
-- Actualización segura de una base ya creada: no recrea ni borra tablas.
INSERT INTO tipo_documento (sigla,nombre) VALUES
    ('PEP','Permiso especial de permanencia'),
    ('PPT','Permiso por protección temporal')
ON CONFLICT (sigla) DO UPDATE SET nombre=EXCLUDED.nombre;

-- Relaciona cada servicio con la(s) especialidad(es) que pueden atenderlo.
CREATE TABLE IF NOT EXISTS servicio_especialidad (
    id_servicio SMALLINT NOT NULL REFERENCES servicio(id_servicio) ON DELETE CASCADE,
    id_especialidad SMALLINT NOT NULL REFERENCES especialidad(id_especialidad) ON DELETE RESTRICT,
    PRIMARY KEY (id_servicio,id_especialidad)
);
INSERT INTO servicio_especialidad (id_servicio,id_especialidad)
SELECT s.id_servicio,e.id_especialidad
FROM (VALUES
    ('Medicina general','Medicina general'),
    ('Control de medicina general','Medicina general'),
    ('Consulta preventiva','Medicina general'),
    ('Pediatría','Pediatría'),
    ('Control de crecimiento y desarrollo','Pediatría'),
    ('Odontología general','Odontología'),
    ('Limpieza dental','Odontología'),
    ('Cardiología','Cardiología'),
    ('Dermatología','Dermatología'),
    ('Ginecología','Ginecología y obstetricia'),
    ('Oftalmología','Oftalmología'),
    ('Ortopedia','Ortopedia'),
    ('Medicina interna','Medicina interna'),
    ('Psicología','Psicología'),
    ('Nutrición','Nutrición'),
    ('Enfermería','Enfermería')
) AS m(servicio_nombre,especialidad_nombre)
JOIN servicio s ON s.nombre=m.servicio_nombre
JOIN especialidad e ON e.nombre=m.especialidad_nombre
ON CONFLICT DO NOTHING;
GRANT SELECT,INSERT,UPDATE,DELETE ON servicio_especialidad TO eps_app;
GRANT SELECT ON servicio_especialidad TO eps_lectura,eps_paciente,eps_medico,eps_administrador;

CREATE OR REPLACE FUNCTION limpiar_horarios_vencidos()
RETURNS VOID
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public
AS $$
BEGIN
    DELETE FROM horario h
    USING estado e
    WHERE e.id_estado=h.id_estado
      AND e.ambito='horario'
      AND e.nombre='Disponible'
      AND (h.fecha+h.hora_fin) <= (CURRENT_TIMESTAMP AT TIME ZONE 'America/Bogota')
      AND NOT EXISTS (SELECT 1 FROM cita c WHERE c.id_horario=h.id_horario);
END;
$$;

CREATE OR REPLACE FUNCTION disparador_limpieza_horarios_vencidos()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    PERFORM limpiar_horarios_vencidos();
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS limpiar_horarios_vencidos_al_cambiar ON horario;
CREATE TRIGGER limpiar_horarios_vencidos_al_cambiar
AFTER INSERT OR UPDATE ON horario
FOR EACH ROW EXECUTE FUNCTION disparador_limpieza_horarios_vencidos();
GRANT EXECUTE ON FUNCTION limpiar_horarios_vencidos() TO eps_app;
\quit
\endif

-- Roles PostgreSQL: eps_app es la conexión de la aplicación; los demás
-- son grupos para accesos directos diferenciados a la base de datos.
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='eps_app') THEN
        CREATE ROLE eps_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD '2345';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='eps_lectura') THEN CREATE ROLE eps_lectura NOLOGIN; END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='eps_paciente') THEN CREATE ROLE eps_paciente NOLOGIN; END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='eps_medico') THEN CREATE ROLE eps_medico NOLOGIN; END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='eps_administrador') THEN CREATE ROLE eps_administrador NOLOGIN; END IF;
END $$;
ALTER ROLE eps_app WITH LOGIN PASSWORD '2345';

BEGIN;

-- Catálogos del MER
CREATE TABLE tipo_documento (
    id_tipo_doc SMALLSERIAL PRIMARY KEY,
    sigla VARCHAR(5) NOT NULL UNIQUE,
    nombre VARCHAR(60) NOT NULL
);

CREATE TABLE estado (
    id_estado SMALLSERIAL PRIMARY KEY,
    nombre VARCHAR(30) NOT NULL,
    ambito VARCHAR(20) NOT NULL,
    UNIQUE (ambito, nombre)
);

CREATE TABLE tarifa (
    id_tarifa SMALLSERIAL PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL UNIQUE,
    ingreso_min NUMERIC(12,2) NOT NULL CHECK (ingreso_min >= 0),
    ingreso_max NUMERIC(12,2) NOT NULL CHECK (ingreso_max >= ingreso_min),
    porcentaje_cobro NUMERIC(5,2) NOT NULL CHECK (porcentaje_cobro BETWEEN 0 AND 100)
);

CREATE TABLE especialidad (
    id_especialidad SMALLSERIAL PRIMARY KEY,
    nombre VARCHAR(80) NOT NULL UNIQUE,
    descripcion TEXT
);

CREATE TABLE servicio (
    id_servicio SMALLSERIAL PRIMARY KEY,
    nombre VARCHAR(80) NOT NULL UNIQUE,
    descripcion TEXT,
    costo_base NUMERIC(10,2) NOT NULL CHECK (costo_base >= 0)
);

CREATE TABLE servicio_especialidad (
    id_servicio SMALLINT NOT NULL REFERENCES servicio(id_servicio) ON DELETE CASCADE,
    id_especialidad SMALLINT NOT NULL REFERENCES especialidad(id_especialidad) ON DELETE RESTRICT,
    PRIMARY KEY (id_servicio,id_especialidad)
);

CREATE TABLE consultorio (
    id_consultorio SMALLSERIAL PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL UNIQUE,
    ubicacion VARCHAR(100) NOT NULL,
    capacidad SMALLINT NOT NULL CHECK (capacidad > 0),
    id_estado SMALLINT NOT NULL REFERENCES estado(id_estado)
);

CREATE TABLE consultorio_especialidad (
    id_consultorio SMALLINT NOT NULL REFERENCES consultorio(id_consultorio) ON DELETE CASCADE,
    id_especialidad SMALLINT NOT NULL REFERENCES especialidad(id_especialidad) ON DELETE RESTRICT,
    PRIMARY KEY (id_consultorio, id_especialidad)
);

-- Entidad solicitada para las credenciales del portal.
CREATE TABLE usuario (
    id_usuario SERIAL PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE
);

-- Entidades afiliado y médico del MER; usuario las habilita para iniciar sesión.
CREATE TABLE afiliado (
    id_afiliado SERIAL PRIMARY KEY,
    id_usuario INTEGER NOT NULL UNIQUE REFERENCES usuario(id_usuario) ON DELETE CASCADE,
    id_tipo_doc SMALLINT NOT NULL REFERENCES tipo_documento(id_tipo_doc),
    num_doc VARCHAR(20) NOT NULL,
    nombre1 VARCHAR(50) NOT NULL CHECK (nombre1 !~ '[0-9]'),
    nombre2 VARCHAR(50) CHECK (nombre2 IS NULL OR nombre2 !~ '[0-9]'),
    apellido1 VARCHAR(50) NOT NULL CHECK (apellido1 !~ '[0-9]'),
    apellido2 VARCHAR(50) CHECK (apellido2 IS NULL OR apellido2 !~ '[0-9]'),
    sexo VARCHAR(20),
    fecha_nacimiento DATE NOT NULL CHECK (fecha_nacimiento <= CURRENT_DATE),
    telefono VARCHAR(20),
    correo VARCHAR(100),
    direccion VARCHAR(150),
    ingreso_mensual NUMERIC(12,2) NOT NULL CHECK (ingreso_mensual >= 0),
    id_tarifa SMALLINT REFERENCES tarifa(id_tarifa),
    estado_afiliacion VARCHAR(12) NOT NULL DEFAULT 'Pendiente'
        CHECK (estado_afiliacion IN ('Pendiente','Activo','Inactivo')),
    UNIQUE (id_tipo_doc, num_doc)
);

CREATE TABLE medico (
    id_medico SERIAL PRIMARY KEY,
    id_usuario INTEGER NOT NULL UNIQUE REFERENCES usuario(id_usuario) ON DELETE CASCADE,
    id_tipo_doc SMALLINT NOT NULL REFERENCES tipo_documento(id_tipo_doc),
    num_documento VARCHAR(20) NOT NULL,
    nombre1 VARCHAR(50) NOT NULL CHECK (nombre1 !~ '[0-9]'),
    nombre2 VARCHAR(50) CHECK (nombre2 IS NULL OR nombre2 !~ '[0-9]'),
    apellido1 VARCHAR(50) NOT NULL CHECK (apellido1 !~ '[0-9]'),
    apellido2 VARCHAR(50) CHECK (apellido2 IS NULL OR apellido2 !~ '[0-9]'),
    correo VARCHAR(100),
    telefono VARCHAR(20),
    id_especialidad SMALLINT NOT NULL REFERENCES especialidad(id_especialidad),
    id_consultorio SMALLINT,
    UNIQUE (id_tipo_doc, num_documento),
    FOREIGN KEY (id_consultorio, id_especialidad)
        REFERENCES consultorio_especialidad(id_consultorio, id_especialidad)
);

CREATE TABLE historial (
    id_historial SERIAL PRIMARY KEY,
    id_afiliado INTEGER NOT NULL UNIQUE REFERENCES afiliado(id_afiliado) ON DELETE CASCADE,
    fecha_apertura DATE NOT NULL DEFAULT CURRENT_DATE,
    alergias TEXT,
    grupo_sanguineo VARCHAR(5),
    antecedentes TEXT,
    observaciones TEXT
);

CREATE TABLE horario (
    id_horario SERIAL PRIMARY KEY,
    id_medico INTEGER NOT NULL REFERENCES medico(id_medico),
    fecha DATE NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    id_estado SMALLINT NOT NULL REFERENCES estado(id_estado),
    CHECK (hora_fin > hora_inicio),
    CHECK (hora_fin - hora_inicio <= INTERVAL '1 hour'),
    UNIQUE (id_medico, fecha, hora_inicio)
);

CREATE TABLE cita (
    id_cita SERIAL PRIMARY KEY,
    id_afiliado INTEGER NOT NULL REFERENCES afiliado(id_afiliado),
    id_servicio SMALLINT NOT NULL REFERENCES servicio(id_servicio),
    id_horario INTEGER NOT NULL UNIQUE REFERENCES horario(id_horario),
    fecha_solicitud TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    id_estado SMALLINT NOT NULL REFERENCES estado(id_estado),
    valor_cobrado NUMERIC(10,2) NOT NULL DEFAULT 0 CHECK (valor_cobrado >= 0)
);

-- Retira la disponibilidad de horarios vencidos que nunca se reservaron.
CREATE OR REPLACE FUNCTION limpiar_horarios_vencidos()
RETURNS VOID
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public
AS $$
BEGIN
    DELETE FROM horario h
    USING estado e
    WHERE e.id_estado = h.id_estado
      AND e.ambito = 'horario'
      AND e.nombre = 'Disponible'
      AND (h.fecha + h.hora_fin) <= (CURRENT_TIMESTAMP AT TIME ZONE 'America/Bogota')
      AND NOT EXISTS (SELECT 1 FROM cita c WHERE c.id_horario = h.id_horario);
END;
$$;

CREATE OR REPLACE FUNCTION disparador_limpieza_horarios_vencidos()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    PERFORM limpiar_horarios_vencidos();
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS limpiar_horarios_vencidos_al_cambiar ON horario;
CREATE TRIGGER limpiar_horarios_vencidos_al_cambiar
AFTER INSERT OR UPDATE ON horario
FOR EACH ROW EXECUTE FUNCTION disparador_limpieza_horarios_vencidos();
GRANT EXECUTE ON FUNCTION limpiar_horarios_vencidos() TO eps_app;

-- Catálogos de demostración
INSERT INTO tipo_documento (sigla, nombre) VALUES
    ('CN','Certificado de nacido vivo'), ('RC','Registro civil de nacimiento'),
    ('TI','Tarjeta de identidad'), ('CC','Cédula de ciudadanía'),
    ('CE','Cédula de extranjería'), ('PA','Pasaporte'),
    ('CD','Carné diplomático'), ('SC','Salvoconducto de permanencia'),
    ('PEP','Permiso especial de permanencia'), ('PPT','Permiso por protección temporal')
ON CONFLICT (sigla) DO UPDATE SET nombre=EXCLUDED.nombre;

INSERT INTO estado (nombre, ambito) VALUES
    ('Disponible','horario'), ('Ocupado','horario'),
    ('Programada','cita'), ('Atendida','cita'), ('Cancelada','cita'),
    ('Activo','consultorio'), ('Inactivo','consultorio');

INSERT INTO tarifa (nombre, ingreso_min, ingreso_max, porcentaje_cobro) VALUES
    ('Categoría A',0,1000000,0),
    ('Categoría B',1000000.01,2500000,5),
    ('Categoría C',2500000.01,9999999999.99,10);

INSERT INTO especialidad (nombre, descripcion) VALUES
    ('Medicina general','Atención primaria'), ('Pediatría','Salud infantil'),
    ('Odontología','Salud oral'), ('Cardiología','Sistema cardiovascular'),
    ('Dermatología','Salud de la piel'), ('Ginecología y obstetricia','Salud de la mujer'),
    ('Oftalmología','Salud visual'), ('Ortopedia','Sistema osteomuscular'),
    ('Medicina interna','Atención integral del adulto'), ('Psicología','Salud mental'),
    ('Nutrición','Alimentación y nutrición'), ('Enfermería','Cuidados de enfermería');

INSERT INTO servicio (nombre, descripcion, costo_base) VALUES
    ('Medicina general','Consulta médica general',50000),
    ('Control de medicina general','Seguimiento de consulta general',40000),
    ('Consulta preventiva','Valoración preventiva de salud',45000),
    ('Pediatría','Atención médica infantil',70000),
    ('Control de crecimiento y desarrollo','Seguimiento infantil',60000),
    ('Odontología general','Valoración odontológica',60000),
    ('Limpieza dental','Profilaxis y limpieza dental',80000),
    ('Cardiología','Consulta cardiovascular',100000),
    ('Dermatología','Consulta de piel',90000),
    ('Ginecología','Consulta ginecológica',90000),
    ('Oftalmología','Valoración visual',85000),
    ('Ortopedia','Valoración osteomuscular',95000),
    ('Medicina interna','Consulta de medicina interna',90000),
    ('Psicología','Consulta psicológica',80000),
    ('Nutrición','Consulta nutricional',65000),
    ('Enfermería','Procedimientos básicos de enfermería',35000);

INSERT INTO servicio_especialidad (id_servicio,id_especialidad)
SELECT s.id_servicio,e.id_especialidad
FROM (VALUES
    ('Medicina general','Medicina general'),
    ('Control de medicina general','Medicina general'),
    ('Consulta preventiva','Medicina general'),
    ('Pediatría','Pediatría'),
    ('Control de crecimiento y desarrollo','Pediatría'),
    ('Odontología general','Odontología'),
    ('Limpieza dental','Odontología'),
    ('Cardiología','Cardiología'),
    ('Dermatología','Dermatología'),
    ('Ginecología','Ginecología y obstetricia'),
    ('Oftalmología','Oftalmología'),
    ('Ortopedia','Ortopedia'),
    ('Medicina interna','Medicina interna'),
    ('Psicología','Psicología'),
    ('Nutrición','Nutrición'),
    ('Enfermería','Enfermería')
) AS m(servicio_nombre,especialidad_nombre)
JOIN servicio s ON s.nombre=m.servicio_nombre
JOIN especialidad e ON e.nombre=m.especialidad_nombre;

INSERT INTO consultorio (nombre, ubicacion, capacidad, id_estado)
SELECT x.nombre, x.ubicacion, x.capacidad, e.id_estado
FROM (VALUES
    ('Consultorio 101','Piso 1',3), ('Consultorio 102','Piso 1',3),
    ('Consultorio 201','Piso 2',2), ('Consultorio 103','Piso 1',2),
    ('Consultorio 202','Piso 2',2), ('Consultorio 203','Piso 2',2),
    ('Consultorio 301','Piso 3',2)
) AS x(nombre, ubicacion, capacidad)
JOIN estado e ON e.ambito='consultorio' AND e.nombre='Activo';

INSERT INTO consultorio_especialidad (id_consultorio, id_especialidad)
SELECT c.id_consultorio, e.id_especialidad
FROM (VALUES
    ('Consultorio 101','Medicina general'), ('Consultorio 102','Pediatría'),
    ('Consultorio 201','Odontología'), ('Consultorio 202','Cardiología'),
    ('Consultorio 203','Dermatología'), ('Consultorio 301','Ginecología y obstetricia'),
    ('Consultorio 103','Oftalmología'), ('Consultorio 103','Ortopedia'),
    ('Consultorio 103','Medicina interna'), ('Consultorio 103','Psicología'),
    ('Consultorio 103','Nutrición'), ('Consultorio 103','Enfermería')
) AS x(consultorio, especialidad)
JOIN consultorio c ON c.nombre=x.consultorio
JOIN especialidad e ON e.nombre=x.especialidad;

-- Copago automático (máximo $10.000)
CREATE FUNCTION calcular_valor_cita() RETURNS TRIGGER AS $$
DECLARE
    costo NUMERIC(10,2);
    porcentaje NUMERIC(5,2);
BEGIN
    SELECT s.costo_base, t.porcentaje_cobro INTO costo, porcentaje
    FROM servicio s
    JOIN afiliado a ON a.id_afiliado=NEW.id_afiliado
    JOIN tarifa t ON t.id_tarifa=a.id_tarifa
    WHERE s.id_servicio=NEW.id_servicio;
    IF costo IS NULL OR porcentaje IS NULL THEN
        RAISE EXCEPTION 'No se encontró el servicio o la tarifa del afiliado.';
    END IF;
    NEW.valor_cobrado := LEAST(ROUND(costo * porcentaje / 100, 2), 10000.00);
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_calcular_valor_cita
BEFORE INSERT OR UPDATE OF id_servicio, id_afiliado ON cita
FOR EACH ROW EXECUTE FUNCTION calcular_valor_cita();

-- Actualiza la disponibilidad del horario al reservar o borrar una cita.
CREATE FUNCTION actualizar_estado_horario() RETURNS TRIGGER AS $$
DECLARE
    horario_actual INTEGER;
    nuevo_estado SMALLINT;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        horario_actual := OLD.id_horario;
        SELECT id_estado INTO nuevo_estado FROM estado
        WHERE ambito='horario'
          AND nombre=CASE WHEN EXISTS (SELECT 1 FROM cita WHERE id_horario=horario_actual)
                          THEN 'Ocupado' ELSE 'Disponible' END;
        UPDATE horario SET id_estado=nuevo_estado WHERE id_horario=horario_actual;
    END IF;
    IF TG_OP <> 'DELETE' THEN
        horario_actual := NEW.id_horario;
        SELECT id_estado INTO nuevo_estado FROM estado
        WHERE ambito='horario'
          AND nombre=CASE WHEN EXISTS (SELECT 1 FROM cita WHERE id_horario=horario_actual)
                          THEN 'Ocupado' ELSE 'Disponible' END;
        UPDATE horario SET id_estado=nuevo_estado WHERE id_horario=horario_actual;
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_actualizar_estado_horario
AFTER INSERT OR UPDATE OR DELETE ON cita
FOR EACH ROW EXECUTE FUNCTION actualizar_estado_horario();

-- Datos de acceso demo: contraseña 2345. La cuenta admin es CC 99999999.
INSERT INTO usuario (username, password_hash)
SELECT x.username, '$2y$12$XZWRqvXpBqAbC2UOy5lOYuVvx8oG3HEQLZNRzAUSUHr1BXLY5X5I6'
FROM (VALUES
    ('99999999'), ('diana.rojas'), ('carlos.mejia'), ('lucia.torres'),
    ('andres.mora'), ('carolina.gil'), ('felipe.ruiz'), ('natalia.santos'),
    ('ana.martinez'), ('luis.gomez'), ('laura.ramirez'), ('miguel.perez'),
    ('sofia.lopez'), ('juan.hernandez'), ('valentina.diaz'), ('mateo.castillo'),
    ('isabella.vargas'), ('santiago.ruiz'), ('camila.moreno'), ('daniel.ortiz'),
    ('gabriela.silva'), ('samuel.reyes'), ('mariana.castro')
) AS x(username);

INSERT INTO medico (id_usuario, id_tipo_doc, num_documento, nombre1, apellido1, telefono, correo, id_especialidad, id_consultorio)
SELECT u.id_usuario, td.id_tipo_doc, x.documento, x.nombre, x.apellido,
       '3005550100', x.username || '@eps.local', e.id_especialidad, c.id_consultorio
FROM (VALUES
    ('diana.rojas','90000001','Diana','Rojas','Medicina general','Consultorio 101'),
    ('carlos.mejia','90000002','Carlos','Mejía','Pediatría','Consultorio 102'),
    ('lucia.torres','90000003','Lucía','Torres','Odontología','Consultorio 201'),
    ('andres.mora','90000004','Andrés','Mora','Medicina general','Consultorio 101'),
    ('carolina.gil','90000005','Carolina','Gil','Cardiología','Consultorio 202'),
    ('felipe.ruiz','90000006','Felipe','Ruiz','Dermatología','Consultorio 203'),
    ('natalia.santos','90000007','Natalia','Santos','Ginecología y obstetricia','Consultorio 301')
) AS x(username, documento, nombre, apellido, especialidad, consultorio)
JOIN usuario u ON u.username=x.username
JOIN tipo_documento td ON td.sigla='CC'
JOIN especialidad e ON e.nombre=x.especialidad
JOIN consultorio c ON c.nombre=x.consultorio;

INSERT INTO afiliado (id_usuario, id_tipo_doc, num_doc, nombre1, apellido1, fecha_nacimiento, telefono, correo, direccion, ingreso_mensual, id_tarifa, estado_afiliacion)
SELECT u.id_usuario, td.id_tipo_doc, x.documento, x.nombre, x.apellido,
       DATE '1995-01-01' + x.n, '300555' || lpad(x.n::text, 4, '0'),
       x.username || '@correo.local', 'Villavicencio', x.ingreso, t.id_tarifa,
       CASE WHEN x.n <= 10 THEN 'Activo' ELSE 'Pendiente' END
FROM (VALUES
    ('ana.martinez','10000001','Ana','Martínez',1,800000),
    ('luis.gomez','10000002','Luis','Gómez',2,800000),
    ('laura.ramirez','10000003','Laura','Ramírez',3,800000),
    ('miguel.perez','10000004','Miguel','Pérez',4,800000),
    ('sofia.lopez','10000005','Sofía','López',5,800000),
    ('juan.hernandez','10000006','Juan','Hernández',6,1800000),
    ('valentina.diaz','10000007','Valentina','Díaz',7,1800000),
    ('mateo.castillo','10000008','Mateo','Castillo',8,1800000),
    ('isabella.vargas','10000009','Isabella','Vargas',9,1800000),
    ('santiago.ruiz','10000010','Santiago','Ruiz',10,1800000),
    ('camila.moreno','10000011','Camila','Moreno',11,3200000),
    ('daniel.ortiz','10000012','Daniel','Ortiz',12,3200000),
    ('gabriela.silva','10000013','Gabriela','Silva',13,3200000),
    ('samuel.reyes','10000014','Samuel','Reyes',14,3200000),
    ('mariana.castro','10000015','Mariana','Castro',15,3200000)
) AS x(username, documento, nombre, apellido, n, ingreso)
JOIN usuario u ON u.username=x.username
JOIN tipo_documento td ON td.sigla='CC'
JOIN tarifa t ON x.ingreso BETWEEN t.ingreso_min AND t.ingreso_max;

INSERT INTO historial (id_afiliado, alergias, grupo_sanguineo, antecedentes)
SELECT a.id_afiliado, 'Sin alergias registradas', 'O+', 'Sin antecedentes registrados'
FROM afiliado a
JOIN usuario u ON u.id_usuario=a.id_usuario;

INSERT INTO horario (id_medico, fecha, hora_inicio, hora_fin, id_estado)
SELECT m.id_medico, CURRENT_DATE + 1,
       TIME '08:00' + (g.slot * INTERVAL '1 hour'),
       TIME '09:00' + (g.slot * INTERVAL '1 hour'), e.id_estado
FROM medico m
CROSS JOIN generate_series(0, 2) AS g(slot)
JOIN estado e ON e.ambito='horario' AND e.nombre='Disponible';

INSERT INTO cita (id_afiliado, id_servicio, id_horario, id_estado)
SELECT a.id_afiliado, s.id_servicio, h.id_horario, e.id_estado
FROM afiliado a
JOIN usuario u ON u.id_usuario=a.id_usuario
JOIN horario h ON h.id_horario=a.id_afiliado - (SELECT min(id_afiliado) FROM afiliado) + 1
JOIN servicio s ON s.nombre='Medicina general'
JOIN estado e ON e.ambito='cita' AND e.nombre='Programada'
WHERE u.username IN ('ana.martinez','luis.gomez');

-- Permisos de conexión y grupos PostgreSQL.
DO $$ BEGIN
    EXECUTE format(
        'GRANT CONNECT ON DATABASE %I TO eps_app, eps_lectura, eps_paciente, eps_medico, eps_administrador',
        current_database()
    );
END $$;
GRANT USAGE ON SCHEMA public TO eps_app, eps_lectura, eps_paciente, eps_medico, eps_administrador;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO eps_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO eps_app;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO eps_lectura;
GRANT SELECT ON tipo_documento, estado, tarifa, especialidad, servicio, servicio_especialidad, consultorio,
    consultorio_especialidad, horario, afiliado, medico, cita, historial TO eps_paciente;
GRANT INSERT ON cita TO eps_paciente;
GRANT UPDATE (id_estado) ON horario TO eps_paciente;
GRANT USAGE, SELECT ON SEQUENCE cita_id_cita_seq TO eps_paciente;
GRANT SELECT ON tipo_documento, estado, especialidad, servicio, servicio_especialidad, consultorio,
    consultorio_especialidad, horario, afiliado, medico, cita, historial TO eps_medico;
GRANT UPDATE (id_estado) ON cita TO eps_medico;
GRANT UPDATE (id_estado) ON horario TO eps_medico;
GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO eps_administrador;
GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO eps_administrador;

COMMENT ON TABLE usuario IS 'Credenciales de acceso para afiliados, médicos y administrador.';
COMMENT ON TABLE consultorio_especialidad IS 'Relación muchos a muchos entre consultorios y especialidades.';
COMMENT ON TABLE servicio_especialidad IS 'Define las especialidades médicas habilitadas para cada servicio.';

COMMIT;
