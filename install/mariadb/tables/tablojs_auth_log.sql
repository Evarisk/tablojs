-- ============================================================================
-- tablojs — Table: tablojs_auth_log
-- Journal des événements d'authentification
-- Remplace: logs/auth.log (JSON-lines)
-- ============================================================================

CREATE TABLE tablojs_auth_log (
  rowid       BIGINT       AUTO_INCREMENT PRIMARY KEY,
  ts          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Horodatage de l événement',
  event       VARCHAR(32)  NOT NULL                           COMMENT 'login_success | login_fail | login_locked | session_expired | ip_blocked | log_cleared | settings_updated | logo_uploaded',
  ip          VARCHAR(45)  NOT NULL DEFAULT ''                COMMENT 'Adresse IP du client (IPv4 ou IPv6)',
  user_login  VARCHAR(64)  DEFAULT NULL                       COMMENT 'Login concerné (NULL si non applicable)',
  user_agent  VARCHAR(200) DEFAULT NULL                       COMMENT 'User-Agent HTTP tronqué',
  details     JSON         DEFAULT NULL                       COMMENT 'Données contextuelles supplémentaires (attempts, reason, etc.)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Journal des connexions et événements de sécurité';
