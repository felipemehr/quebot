-- QueBot Legal Library - PostgreSQL Schema
-- Run: psql $DATABASE_URL -f services/legal/migrations/001_schema.sql

BEGIN;

-- Sources registry (BCN, Diario Oficial, etc.)
CREATE TABLE IF NOT EXISTS legal_sources (
    id SERIAL PRIMARY KEY,
    code VARCHAR(50) UNIQUE NOT NULL,       -- 'bcn_leychile'
    name VARCHAR(200) NOT NULL,              -- 'BCN LeyChile'
    base_url VARCHAR(500),                   -- 'https://www.leychile.cl'
    description TEXT,
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- Norms (laws, decrees, etc.)
CREATE TABLE IF NOT EXISTS legal_norms (
    id SERIAL PRIMARY KEY,
    source_id INTEGER REFERENCES legal_sources(id),
    id_norma VARCHAR(50) NOT NULL,           -- BCN's normaId
    tipo VARCHAR(100),                        -- 'Ley', 'Decreto', 'DFL', etc.
    numero VARCHAR(100),                      -- '19628'
    titulo TEXT,                               -- 'SOBRE PROTECCION DE LA VIDA PRIVADA'
    organismo VARCHAR(300),                   -- 'MINISTERIO SECRETARÍA GENERAL DE LA PRESIDENCIA'
    fecha_publicacion DATE,
    fecha_promulgacion DATE,
    url_canonica VARCHAR(500),               -- 'https://www.leychile.cl/Navegar?idNorma=141599'
    estado VARCHAR(50) DEFAULT 'vigente',    -- 'vigente', 'derogado'
    es_tratado BOOLEAN DEFAULT FALSE,
    materias TEXT[],                           -- Array of topics
    nombres_uso_comun TEXT[],                 -- Common names (e.g. "LEY DICOM")
    in_core_set BOOLEAN DEFAULT FALSE,       -- Is this in the curated core set?
    metadata JSONB DEFAULT '{}',             -- Additional metadata
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE(source_id, id_norma)
);

-- Norm versions (track changes over time)
CREATE TABLE IF NOT EXISTS legal_versions (
    id SERIAL PRIMARY KEY,
    norm_id INTEGER REFERENCES legal_norms(id) ON DELETE CASCADE,
    fecha_version DATE,                       -- BCN's fechaVersion
    text_hash VARCHAR(64),                    -- SHA-256 of full text for change detection
    xml_size INTEGER,                         -- Size of source XML
    article_count INTEGER,                    -- Number of articles in this version
    status VARCHAR(50) DEFAULT 'active',     -- 'active', 'superseded'
    fetched_at TIMESTAMPTZ DEFAULT NOW(),
    raw_xml_path VARCHAR(500),               -- Optional: path to stored XML
    UNIQUE(norm_id, fecha_version)
);

-- Chunks (articles, sections, etc.)
CREATE TABLE IF NOT EXISTS legal_chunks (
    id SERIAL PRIMARY KEY,
    version_id INTEGER REFERENCES legal_versions(id) ON DELETE CASCADE,
    norm_id INTEGER REFERENCES legal_norms(id) ON DELETE CASCADE,
    chunk_type VARCHAR(50) NOT NULL,         -- 'article', 'title', 'chapter', 'inciso', 'transitory'
    chunk_path VARCHAR(200),                  -- 'Art_1', 'Tit_I/Art_19/Inc_2/Let_b'
    id_parte VARCHAR(50),                     -- BCN's idParte
    nombre_parte VARCHAR(200),               -- '1', '2 bis', etc.
    titulo_parte VARCHAR(500),               -- For groupers: 'Título I De la utilización...'
    texto TEXT NOT NULL,                       -- Full text of the chunk
    texto_plain TEXT,                          -- Cleaned text (no HTML/XML artifacts)
    parent_chunk_id INTEGER REFERENCES legal_chunks(id),
    ordering INTEGER DEFAULT 0,              -- Order within parent
    derogado BOOLEAN DEFAULT FALSE,
    transitorio BOOLEAN DEFAULT FALSE,
    char_count INTEGER,                       -- Length of texto_plain
    metadata JSONB DEFAULT '{}',
    created_at TIMESTAMPTZ DEFAULT NOW(),
    -- Full-text search support
    tsv tsvector GENERATED ALWAYS AS (
        to_tsvector('spanish', COALESCE(texto_plain, ''))
    ) STORED
);

-- Sync run logs
CREATE TABLE IF NOT EXISTS legal_sync_runs (
    id SERIAL PRIMARY KEY,
    started_at TIMESTAMPTZ DEFAULT NOW(),
    finished_at TIMESTAMPTZ,
    status VARCHAR(50) DEFAULT 'running',    -- 'running', 'completed', 'failed', 'partial'
    trigger_type VARCHAR(50),                 -- 'cron', 'manual', 'api'
    norms_checked INTEGER DEFAULT 0,
    norms_updated INTEGER DEFAULT 0,
    norms_failed INTEGER DEFAULT 0,
    chunks_created INTEGER DEFAULT 0,
    chunks_deleted INTEGER DEFAULT 0,
    errors JSONB DEFAULT '[]',               -- Array of {norm_id, error, timestamp}
    summary TEXT,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Indexes for performance
CREATE INDEX IF NOT EXISTS idx_norms_id_norma ON legal_norms(id_norma);
CREATE INDEX IF NOT EXISTS idx_norms_tipo ON legal_norms(tipo);
CREATE INDEX IF NOT EXISTS idx_norms_core ON legal_norms(in_core_set) WHERE in_core_set = TRUE;
CREATE INDEX IF NOT EXISTS idx_versions_norm ON legal_versions(norm_id);
CREATE INDEX IF NOT EXISTS idx_versions_active ON legal_versions(status) WHERE status = 'active';
CREATE INDEX IF NOT EXISTS idx_chunks_norm ON legal_chunks(norm_id);
CREATE INDEX IF NOT EXISTS idx_chunks_version ON legal_chunks(version_id);
CREATE INDEX IF NOT EXISTS idx_chunks_path ON legal_chunks(chunk_path);
CREATE INDEX IF NOT EXISTS idx_chunks_type ON legal_chunks(chunk_type);
CREATE INDEX IF NOT EXISTS idx_chunks_tsv ON legal_chunks USING GIN(tsv);
CREATE INDEX IF NOT EXISTS idx_sync_status ON legal_sync_runs(status);

-- Insert default source
INSERT INTO legal_sources (code, name, base_url, description)
VALUES ('bcn_leychile', 'BCN LeyChile', 'https://www.leychile.cl', 'Biblioteca del Congreso Nacional - Legislación Abierta Web Service')
ON CONFLICT (code) DO NOTHING;

COMMIT;
