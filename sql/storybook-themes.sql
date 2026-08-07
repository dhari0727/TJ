-- =====================================================================
-- JourneyAI — Storybook theme seeds
-- =====================================================================
-- Run:  mysql -u root project < sql/storybook-themes.sql
-- SAFE TO RE-RUN: replaces existing rows via INSERT REPLACE.
-- Depends on: sql/storybook.sql (storybooks_themes table)
-- =====================================================================

REPLACE INTO storybooks_themes (theme_id, label, description, palette, fonts, bg_class, emoji_set) VALUES

-- ---------------------------------------------------------------------
-- 🌸 Floral / soft pastel
-- ---------------------------------------------------------------------
('floral', 'Floral Pastel', 'Warm pastels, floral motifs, handwritten feel.',
JSON_OBJECT(
  '--sb-bg',        '#FFF5F7',
  '--sb-surface',   '#FFFFFF',
  '--sb-text',      '#3D2B2B',
  '--sb-text-dim',  '#7A5C5C',
  '--sb-accent',    '#D4758C',
  '--sb-accent2',   '#A3C9A8',
  '--sb-border',    '#F0D4DB',
  '--sb-paper',     '#FDF8F3',
  '--sb-shadow',    '0 4px 20px rgba(212,117,140,0.15)',
  '--sb-slot-bg',   '#FFF0F3',
  '--sb-slot-border','#F0B8C4'
),
JSON_OBJECT(
  '--sb-font-display', "'Playfair Display', Georgia, serif",
  '--sb-font-body',    "'Lora', Georgia, serif",
  '--sb-font-hand',    "'Caveat', cursive"
),
'sb-floral',
JSON_ARRAY('🌸','💐','🦋','🌷','🎀','💕','✨','🌺','🌼','🍃','🌈','🍰','🪻','🌷','🌻')
),

-- ---------------------------------------------------------------------
-- 📚 Dark academia
-- ---------------------------------------------------------------------
('dark-academia', 'Dark Academia', 'Deep greens and browns, serif type, vintage paper texture.',
JSON_OBJECT(
  '--sb-bg',        '#1A1612',
  '--sb-surface',   '#2A2420',
  '--sb-text',      '#E8DCC8',
  '--sb-text-dim',  '#A89880',
  '--sb-accent',    '#C9A96E',
  '--sb-accent2',   '#6B8F71',
  '--sb-border',    '#3D342A',
  '--sb-paper',     '#2E2820',
  '--sb-shadow',    '0 4px 20px rgba(0,0,0,0.4)',
  '--sb-slot-bg',   '#252018',
  '--sb-slot-border','#4A3F32'
),
JSON_OBJECT(
  '--sb-font-display', "'DM Serif Display', Georgia, serif",
  '--sb-font-body',    "'Lora', Georgia, serif",
  '--sb-font-hand',    "'Caveat', cursive"
),
'sb-dark-academia',
JSON_ARRAY('📚','🦉','🌿','🍂','🕯️','📜','🖋️','🏰','🍃','☕','🗝️','🪶','🫖','📖','🌰')
),

-- ---------------------------------------------------------------------
-- ⛰️ Retro travel poster
-- ---------------------------------------------------------------------
('retro-travel', 'Retro Travel', 'Bold flat colors, mid-century illustration style.',
JSON_OBJECT(
  '--sb-bg',        '#F5E6C8',
  '--sb-surface',   '#FFFBF0',
  '--sb-text',      '#1A2E35',
  '--sb-text-dim',  '#5A7078',
  '--sb-accent',    '#D35400',
  '--sb-accent2',   '#2980B9',
  '--sb-border',    '#D4C4A0',
  '--sb-paper',     '#FFF8EC',
  '--sb-shadow',    '0 4px 20px rgba(26,46,53,0.15)',
  '--sb-slot-bg',   '#EDE2CC',
  '--sb-slot-border','#C8B898'
),
JSON_OBJECT(
  '--sb-font-display', "'Playfair Display', Georgia, serif",
  '--sb-font-body',    "'Inter', system-ui, sans-serif",
  '--sb-font-hand',    "'Caveat', cursive"
),
'sb-retro-travel',
JSON_ARRAY('🗺️','✈️','🧭','🏔️','☀️','🎒','🚂','⛱️','🌍','⛽','🗼','🏜️','🌅','🚌','🏕️')
),

-- ---------------------------------------------------------------------
-- 🖤 Minimal / monochrome
-- ---------------------------------------------------------------------
('minimal', 'Minimal Mono', 'Clean grid, generous white space, quiet type.',
JSON_OBJECT(
  '--sb-bg',        '#FAFAFA',
  '--sb-surface',   '#FFFFFF',
  '--sb-text',      '#1A1A1A',
  '--sb-text-dim',  '#888888',
  '--sb-accent',    '#1A1A1A',
  '--sb-accent2',   '#555555',
  '--sb-border',    '#E0E0E0',
  '--sb-paper',     '#FFFFFF',
  '--sb-shadow',    '0 2px 12px rgba(0,0,0,0.08)',
  '--sb-slot-bg',   '#F5F5F5',
  '--sb-slot-border','#D0D0D0'
),
JSON_OBJECT(
  '--sb-font-display', "'Space Mono', 'Courier New', monospace",
  '--sb-font-body',    "'Inter', system-ui, sans-serif",
  '--sb-font-hand',    "'Inter', system-ui, sans-serif"
),
'sb-minimal',
JSON_ARRAY('📍','🗺️','🧭','✨','⬛','⬜','🔹','▫️','▪️','◻️','●','○','◆','◇','▪')
),

-- ---------------------------------------------------------------------
-- 📼 Y2K / scrapbook-chaotic
-- ---------------------------------------------------------------------
('y2k', 'Y2K Scrapbook', 'Busy collage energy, bold fonts, playful stickers.',
JSON_OBJECT(
  '--sb-bg',        '#FFE8F5',
  '--sb-surface',   '#FFFFFF',
  '--sb-text',      '#1A0A2E',
  '--sb-text-dim',  '#7A5A9A',
  '--sb-accent',    '#FF2D9B',
  '--sb-accent2',   '#00D4FF',
  '--sb-border',    '#E0A0D0',
  '--sb-paper',     '#FFF0FA',
  '--sb-shadow',    '0 4px 20px rgba(255,45,155,0.15)',
  '--sb-slot-bg',   '#FFF0F8',
  '--sb-slot-border','#FFB0D8'
),
JSON_OBJECT(
  '--sb-font-display', "'Space Mono', 'Courier New', monospace",
  '--sb-font-body',    "'Inter', system-ui, sans-serif",
  '--sb-font-hand',    "'Caveat', cursive"
),
'sb-y2k',
JSON_ARRAY('💿','🦋','🩷','🪩','⭐','🔮','💎','🫧','🌈','🎵','💖','🧊','🌸','🫀','✧')
),

-- ---------------------------------------------------------------------
-- 🎞️ Moody film
-- ---------------------------------------------------------------------
('moody-film', 'Moody Film', 'Muted tones, grain texture, old-photo-album feel.',
JSON_OBJECT(
  '--sb-bg',        '#1C1916',
  '--sb-surface',   '#2A2520',
  '--sb-text',      '#D4C8B8',
  '--sb-text-dim',  '#8A7E6E',
  '--sb-accent',    '#C4956A',
  '--sb-accent2',   '#7A8B6E',
  '--sb-border',    '#3D362E',
  '--sb-paper',     '#242018',
  '--sb-shadow',    '0 4px 20px rgba(0,0,0,0.4)',
  '--sb-slot-bg',   '#1E1A14',
  '--sb-slot-border','#4A4038'
),
JSON_OBJECT(
  '--sb-font-display', "'Lora', Georgia, serif",
  '--sb-font-body',    "'Inter', system-ui, sans-serif",
  '--sb-font-hand',    "'Caveat', cursive"
),
'sb-moody-film',
JSON_ARRAY('🎞️','🥀','🌙','📷','🌫️','🖤','🎬','🎞️','📽️','🎞️','🌫️','📷','🖤','🎞️','🌙')
),

-- ---------------------------------------------------------------------
-- 🌿 Cottagecore / nature
-- ---------------------------------------------------------------------
('cottagecore', 'Cottagecore', 'Earthy greens, botanical motifs, handwritten type.',
JSON_OBJECT(
  '--sb-bg',        '#F0EDE4',
  '--sb-surface',   '#FAF8F2',
  '--sb-text',      '#2C3E28',
  '--sb-text-dim',  '#6B7E66',
  '--sb-accent',    '#5E8B5A',
  '--sb-accent2',   '#C4956A',
  '--sb-border',    '#D4CEBC',
  '--sb-paper',     '#F5F2EA',
  '--sb-shadow',    '0 4px 20px rgba(44,62,40,0.12)',
  '--sb-slot-bg',   '#EAE6DA',
  '--sb-slot-border','#C8C2B0'
),
JSON_OBJECT(
  '--sb-font-display', "'Lora', Georgia, serif",
  '--sb-font-body',    "'Lora', Georgia, serif",
  '--sb-font-hand',    "'Caveat', cursive"
),
'sb-cottagecore',
JSON_ARRAY('🌿','🍄','🌻','🦋','🐝','🍃','🌸','🌾','🪴','🦔','🌰','🫖','🧺','🪵','🌼')
);
