-- ============================================================================
-- tablojs — Données initiales : tablojs_settings
-- Valeurs par défaut, équivalent de config/settings.json initial
-- ============================================================================

INSERT INTO tablojs_settings (setting_key, value) VALUES
  ('company_name',    'tablojs'),
  ('company_tagline', 'Gestion des stocks'),
  ('company_logo',    ''),
  ('allowed_ips',     '[]'),
  ('max_attempts',    '3'),
  ('lockout_duration','300'),
  ('session_duration','28800'),
  ('captcha_enabled', '1'),
  ('log_enabled',     '1')
ON DUPLICATE KEY UPDATE value = VALUES(value);


-- ============================================================================
-- tablojs — Données initiales : tablojs_unit
-- Unités de vente/achat courantes
-- ============================================================================

INSERT INTO tablojs_unit (code, label, label_short, rang) VALUES
  ('PCE', 'Pièce',      'pce',  10),
  ('KG',  'Kilogramme', 'kg',   20),
  ('T',   'Tonne',      't',    30),
  ('M',   'Mètre',      'm',    40),
  ('M2',  'Mètre carré','m²',   50),
  ('M3',  'Mètre cube', 'm³',   60),
  ('L',   'Litre',      'l',    70),
  ('ML',  'Mètre linéaire','ml',80),
  ('U',   'Unité',      'u',    90),
  ('FT',  'Forfait',    'fft', 100)
ON DUPLICATE KEY UPDATE label = VALUES(label);


-- ============================================================================
-- tablojs — Données initiales : tablojs_product_category
-- Catégories de base (à personnaliser selon l activité)
-- ============================================================================

INSERT INTO tablojs_product_category (code, label, color, rang) VALUES
  ('TRANSPORT', 'Transport & Fret', '#64748b', 10),
  ('PIECE',     'Pièces détachées', '#3b82f6', 20),
  ('FERR',      'Ferraille',        '#f59e0b', 30),
  ('CONSOMM',   'Consommables',     '#22c55e', 40),
  ('AUTRE',     'Autre',            '#94a3b8', 99)
ON DUPLICATE KEY UPDATE label = VALUES(label);
