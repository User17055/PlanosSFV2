-- Schema do CMS do institucional, deduzido das queries em public_html/src.
-- Aplicar: mysql institucional < schema.sql
-- Os textos de institutional_type são provisórios: edite pelo painel /luzsisadm.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS institutional_type (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  url VARCHAR(255) NULL,
  body MEDIUMTEXT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS institutional (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  type INT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  url VARCHAR(255) NULL,
  body MEDIUMTEXT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  KEY (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS adocoes_type (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(255) NOT NULL,
  cidade VARCHAR(255) NULL,
  image VARCHAR(255) NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS unidades_type (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  endereco VARCHAR(255) NOT NULL,
  cidade VARCHAR(255) NULL,
  image VARCHAR(255) NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS servicos_type (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  image VARCHAR(255) NULL,
  body MEDIUMTEXT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS planos_type (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  url VARCHAR(255) NULL,
  body MEDIUMTEXT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS beneficios_type (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  body MEDIUMTEXT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS planos_beneficios (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  id_plano INT UNSIGNED NOT NULL,
  id_beneficio INT UNSIGNED NOT NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  UNIQUE KEY (id_plano, id_beneficio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS banner_type (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  width INT NULL,
  height INT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS banner (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  type INT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  url VARCHAR(255) NULL,
  image VARCHAR(255) NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  show_in DATETIME NULL,
  show_out DATETIME NULL,
  `order` INT NOT NULL DEFAULT 0,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  KEY (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS seo (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  url VARCHAR(255) NOT NULL,
  title VARCHAR(255) NULL,
  description TEXT NULL,
  keyword TEXT NULL,
  h1 VARCHAR(255) NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  `order` INT NOT NULL DEFAULT 0,
  created_at DATETIME NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS roles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  role VARCHAR(100) NOT NULL UNIQUE,
  is_removable TINYINT(1) NOT NULL DEFAULT 1,
  description VARCHAR(255) NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS roles_access (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  path VARCHAR(255) NOT NULL,
  roles TEXT NULL,
  methods TEXT NULL,
  host VARCHAR(255) NULL,
  ip VARCHAR(255) NULL,
  `order` INT NOT NULL DEFAULT 0,
  created_at DATETIME NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  email VARCHAR(255) NULL,
  name VARCHAR(255) NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  deleted_at DATETIME NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users_roles (
  user INT UNSIGNED NOT NULL,
  role INT UNSIGNED NOT NULL,
  PRIMARY KEY (user, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Dados mínimos: papel do painel, regra de acesso e os 5 textos institucionais lidos pelo site.
INSERT INTO roles (role, is_removable, description, created_at, updated_at)
SELECT 'ROLE_ADMIN', 0, 'Administrador', NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM roles WHERE role = 'ROLE_ADMIN');

INSERT INTO roles_access (path, roles, methods, `order`, created_at, updated_at)
SELECT '^/luzsisadm', '["ROLE_ADMIN"]', NULL, 1, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM roles_access);

INSERT INTO institutional_type (id, title, url, body, enabled, created_at, updated_at)
SELECT * FROM (
  SELECT 1 id, 'Quem somos' title, '/' url, '<p>Texto provisório. Edite no painel.</p>' body, 1 enabled, NOW() created_at, NOW() updated_at
  UNION ALL SELECT 2, 'Adoções', '/adocoes/', '<p>Texto provisório. Edite no painel.</p>', 1, NOW(), NOW()
  UNION ALL SELECT 3, 'A clínica', '/sobre-a-clinica/', '<p>Texto provisório. Edite no painel.</p>', 1, NOW(), NOW()
  UNION ALL SELECT 4, 'Planos de saúde', '/planos-saude/', '<p>Conheça nossos planos e acompanhe cada etapa do cuidado do seu pet.</p>', 1, NOW(), NOW()
  UNION ALL SELECT 5, 'Banho e tosa', '/banho-e-tosa/', '<p>Texto provisório. Edite no painel.</p>', 1, NOW(), NOW()
) t WHERE NOT EXISTS (SELECT 1 FROM institutional_type);
