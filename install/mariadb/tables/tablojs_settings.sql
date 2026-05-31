-- ============================================================================
-- tablojs — Table: tablojs_settings
-- Paramètres applicatifs (branding, sécurité, session, etc.)
-- Remplace: config/settings.json
-- ============================================================================

CREATE TABLE tablojs_settings (
  rowid       INT          AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(64)  NOT NULL UNIQUE COMMENT 'Clé du paramètre (ex: company_name)',
  value       TEXT         NOT NULL         COMMENT 'Valeur sérialisée (scalaire ou JSON)',
  updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Paramètres dynamiques de l application';
