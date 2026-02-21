# QueBot Legal Library

Base de datos jurídica para alimentar el sistema RAG de QueBot con legislación chilena oficial.

## Arquitectura

```
BCN LeyChile (XML)
    │
    ▼
BcnConnector.php     ← Descarga + parseo XML + SHA-256
    │
    ▼
LegalChunker.php     ← Chunking jurídico por artículo/inciso/numeral/letra
    │
    ▼
LegalSync.php        ← Orquestación + versionado + transacciones
    │
    ▼
PostgreSQL           ← 5 tablas: sources, norms, versions, chunks, sync_runs
    │
    ▼
LegalSearch.php      ← Búsqueda FTS (español) para RAG
    │
    ▼
chat.php             ← Inyección automática de contexto legal en Claude
```

## Modelo de Datos (PostgreSQL)

### `legal_sources`
Fuentes de datos (BCN LeyChile, DOF, etc.)

### `legal_norms`
Registro de cada norma: id_norma, tipo, número, título, organismo, fechas, estado, materias.

### `legal_versions`
Historial de versiones por norma. Cada fetch exitoso genera una versión con `text_hash` SHA-256. Si el hash no cambió, no se reprocesa.

### `legal_chunks`
Fragmentos de texto para RAG. Cada chunk tiene:
- `chunk_type`: article, inciso, numeral, letra, title, chapter, book...
- `chunk_path`: `Art_19/Inc_2/Num_3/Let_b`
- `texto` / `texto_plain`: contenido
- `parent_chunk_id`: relación jerárquica
- `tsv`: tsvector para Full-Text Search en español

### `legal_sync_runs`
Log de cada ejecución de sync: tiempos, normas procesadas, chunks creados, errores.

## Normas Core (verificadas Feb 2026)

| idNorma | Norma | XML Size | Estado |
|---------|-------|----------|--------|
| 172986 | Código Civil (DFL 1, 2000) | 2.9 MB | Vigente |
| 22740 | Código de Procedimiento Civil | 57 MB | Vigente |
| 13560 | DFL 458 Ley General de Urbanismo y Construcciones | 484 KB | Vigente |
| 1174663 | Ley 21.442 Copropiedad Inmobiliaria | 590 KB | Vigente |
| 210676 | Ley 19.880 Procedimiento Administrativo | 110 KB | Vigente |

**Nota:** Ley 19.537 fue reemplazada por Ley 21.442.
**Nota:** CPC (22740) requiere timeout extendido (5 min) por su tamaño.

## Flujo de Versionado

1. **Fetch**: BcnConnector descarga XML desde `https://www.leychile.cl/Consulta/obtxml?opt=7&idNorma=XXX`
2. **Parse**: Extrae metadatos + estructura jerárquica (EstructuraFuncional)
3. **Hash**: Calcula SHA-256 del texto completo
4. **Compare**: Si hash == versión activa existente → SKIP (sin cambios)
5. **Transaction BEGIN**
6. **Version**: Crea nueva versión en `legal_versions`
7. **Chunk**: Genera chunks con LegalChunker
8. **Store**: Guarda chunks en `legal_chunks`
9. **Supersede**: Marca versiones anteriores como "superseded"
10. **Transaction COMMIT** (o ROLLBACK si falla)

Nunca se borran versiones anteriores.

## Estructura de Chunking

### Jerarquía

```
Artículo (unidad base)
  └─ Inciso (párrafos del artículo)
       └─ Numeral (1., 2., 3° ...)
            └─ Letra (a), b), c) ...)
```

### Reglas

- **Artículo completo** = 1 chunk si ≤ ~700 palabras (4500 chars)
- Si excede, se subdivide por **incisos**
- Si un inciso tiene items numerados (1., 2., 3.), se subdivide por **numerales**
- Si un numeral/inciso tiene letras (a), b), c)), se subdivide por **letras**
- **Mínimo por chunk**: ~150 palabras (900 chars). Chunks pequeños se fusionan con el siguiente.
- **Máximo por chunk**: ~700 palabras (4500 chars)
- Cada chunk es jurídicamente coherente y puede reconstruir el artículo completo

### Path format

```
Art_19                    → Artículo completo
Art_19/Inc_2              → Segundo inciso
Art_19/Inc_2/Num_3        → Tercer numeral del segundo inciso
Art_19/Inc_2/Num_3/Let_b  → Letra b del tercer numeral
```

### Metadata por chunk

- `norma_id` (FK a legal_norms)
- `version_id` (FK a legal_versions)
- `chunk_type`: article, inciso, numeral, letra, title, chapter, book...
- `chunk_path`: ubicación jerárquica
- `nombre_parte`, `titulo_parte`
- `derogado`, `transitorio`
- `parent_chunk_id` (relación padre-hijo)
- `char_count`

## Endpoints API

Todos protegidos por `ADMIN_TOKEN` (query param `?token=` o header `X-Admin-Token`).

### Health
```
GET /api/legal/health.php
→ { "status": "ok", "database": "connected", "norms_count": 5, ... }
```

### Listar normas
```
GET /api/legal/norms.php?token=XXX
→ [{ "id_norma": "172986", "titulo": "...", "estado": "vigente", ... }]
```

### Detalle de norma
```
GET /api/legal/norm.php?id=172986&token=XXX
→ { norm details + version info + chunk count }
```

### Buscar artículos
```
GET /api/legal/article.php?norm=172986&article=19&token=XXX
→ { article text }
```

### Búsqueda FTS
```
GET /api/legal/search.php?q=proteccion+datos&token=XXX
→ [{ matching chunks ranked by relevance }]
```

### Sync completo
```
POST /api/legal/sync.php?token=XXX
→ { sync run summary }
```

### Sync individual
```
POST /api/legal/sync.php?token=XXX&idNorma=210676
→ { sync run summary for single norm }
```

### Sync test (lectura + sync + resumen)
```
GET /api/legal/sync-test.php?idNorma=210676&token=XXX
→ {
    "norma": "ESTABLECE BASES...",
    "version_detected": "2026-02-05",
    "chunks_created": 85,
    "status": "completed",
    "duration_ms": 2340
  }
```

## Cómo ejecutar sync manual

### Desde API (recomendado)
```bash
# Sync todas las normas core
curl -X POST "https://YOUR-DOMAIN/api/legal/sync.php?token=YOUR_TOKEN"

# Sync una norma específica
curl -X POST "https://YOUR-DOMAIN/api/legal/sync.php?token=YOUR_TOKEN&idNorma=210676"

# Test una norma (GET, solo lectura + sync + resumen)
curl "https://YOUR-DOMAIN/api/legal/sync-test.php?idNorma=210676&token=YOUR_TOKEN"
```

### Desde CLI (Railway console)
```bash
php services/legal/worker.php sync
php services/legal/worker.php sync 210676
```

## Cómo agregar una nueva norma

1. **Encontrar idNorma** en BCN LeyChile:
   - Buscar en https://www.leychile.cl/
   - El idNorma está en la URL: `?idNorma=XXXXX`
   - O usar la API: `GET /Consulta/obtxml?opt=61&cadena=BUSQUEDA&cantidad=5`

2. **Verificar que el XML funciona**:
   ```bash
   curl -H "User-Agent: Mozilla/5.0" \
     "https://www.leychile.cl/Consulta/obtxml?opt=7&idNorma=XXXXX" \
     -o test.xml
   # Verificar que no esté vacío y tenga estructura XML válida
   ```

3. **Agregar a core set** (opcional):
   - Editar `seedCoreNorms()` en `services/legal/LegalSync.php`
   - O configurar variable de entorno `LEGAL_CORE_NORMS` (JSON array o CSV)

4. **Ejecutar sync**:
   ```bash
   curl -X POST "https://YOUR-DOMAIN/api/legal/sync.php?token=TOKEN&idNorma=XXXXX"
   ```

5. **Verificar**:
   ```bash
   curl "https://YOUR-DOMAIN/api/legal/sync-test.php?idNorma=XXXXX&token=TOKEN"
   ```

## Integración RAG

El sistema de chat (`chat.php`) detecta automáticamente consultas legales y busca en `legal_chunks` vía Full-Text Search. Los chunks más relevantes se inyectan como contexto en la llamada a Claude.

Detección de intent legal: keywords como "ley", "artículo", "código", "norma", "decreto", etc.

## Consideraciones

- **CPC (57MB)**: Requiere `set_time_limit(300)` y `memory_limit=512M`
- **BCN rate limiting**: 500ms entre requests (`usleep(500000)`)
- **User-Agent requerido**: BCN bloquea requests sin User-Agent
- **Transacciones**: Cada syncNorm está envuelto en BEGIN/COMMIT/ROLLBACK
- **Idempotente**: Ejecutar sync múltiples veces no duplica datos
