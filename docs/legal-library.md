# QueBot Legal Library

Base Legal Curada de Chile — Pipeline de datos legales para alimentar el RAG de QueBot.

## Arquitectura

```
┌─────────────────────────────────────────────────┐
│                  Railway App                      │
│                                                   │
│  ┌──────────────┐     ┌──────────────────────┐  │
│  │ API Endpoints │     │  services/legal/      │  │
│  │  /api/legal/* │────▶│  BcnConnector.php     │  │
│  │               │     │  LegalChunker.php     │  │
│  └──────────────┘     │  LegalSync.php        │  │
│                        │  database.php         │  │
│                        └──────────┬───────────┘  │
│                                   │               │
│  ┌─────────────┐    ┌────────────▼────────────┐ │
│  │ Cron Worker  │───▶│    PostgreSQL (Railway)  │ │
│  │ worker.php   │    │  legal_sources           │ │
│  └─────────────┘    │  legal_norms             │ │
│                      │  legal_versions          │ │
│                      │  legal_chunks (FTS)      │ │
│                      │  legal_sync_runs         │ │
│                      └─────────────────────────┘ │
│                                                   │
│  ┌──────────────────────────────────────────────┐│
│  │           BCN LeyChile XML API               ││
│  │  https://www.leychile.cl/Consulta/obtxml     ││
│  └──────────────────────────────────────────────┘│
└─────────────────────────────────────────────────┘
```

## Endpoints

| Endpoint | Method | Auth | Descripción |
|----------|--------|------|-------------|
| `/api/legal/health` | GET | No | Estado de DB, última sync, conteos |
| `/api/legal/norms` | GET | No | Lista normas del núcleo curado |
| `/api/legal/norm?idNorma=X` | GET | No | Detalle de norma + versiones |
| `/api/legal/article?idNorma=X&art=Y` | GET | No | Texto de artículo + sub-chunks |
| `/api/legal/search?q=X` | GET | No | Búsqueda full-text (PostgreSQL tsvector) |
| `/api/legal/sync` | POST | ADMIN_TOKEN | Ejecutar sync completo |
| `/api/legal/sync?idNorma=X` | POST | ADMIN_TOKEN | Sync una norma específica |
| `/api/legal/migrate` | POST | ADMIN_TOKEN | Correr migraciones de DB |

## Variables de Entorno (Railway)

| Variable | Requerida | Descripción |
|----------|-----------|-------------|
| `DATABASE_URL` | Sí | PostgreSQL connection string (Railway lo setea automáticamente) |
| `ADMIN_TOKEN` | Sí | Token para endpoints protegidos (sync, migrate) |
| `LEGAL_CORE_NORMS` | No | Lista de idNorma del núcleo curado (JSON array o CSV). Default: [141599, 242302, 29726, 276268] |
| `LEGAL_SYNC_SCHEDULE` | No | Documentación del schedule de sync (default: diario 03:00 UTC) |

## Cómo agregar una norma al núcleo curado

1. Encontrar el `idNorma` en LeyChile: buscar la ley en https://www.leychile.cl/Consulta, ir a datos > Norma ID
2. Opción A - Sync individual:
   ```bash
   curl -X POST "https://quebot-production.up.railway.app/api/legal/sync?idNorma=XXXXX" \
     -H "X-Admin-Token: $ADMIN_TOKEN"
   ```
3. Opción B - Agregar a LEGAL_CORE_NORMS en Railway y esperar al próximo sync diario
4. Verificar: `GET /api/legal/norm?idNorma=XXXXX`

## Cómo correr sync manualmente

```bash
# Sync completo (todas las normas del núcleo)
curl -X POST "https://quebot-production.up.railway.app/api/legal/sync" \
  -H "X-Admin-Token: YOUR_TOKEN"

# Sync de una norma
curl -X POST "https://quebot-production.up.railway.app/api/legal/sync?idNorma=141599" \
  -H "X-Admin-Token: YOUR_TOKEN"
```

## Cómo verificar que está vigente

```bash
# Health check - muestra última sync y conteos
curl "https://quebot-production.up.railway.app/api/legal/health"
```

Respuesta incluye `last_sync.started_at`, `last_sync.status`, y conteos de tablas.

## Cómo depurar fallas

1. **Health check**: `GET /api/legal/health` — verifica conexión DB y estado de tablas
2. **Sync runs**: Los errores se registran en `legal_sync_runs.errors` (JSON array)
3. **Logs Railway**: Dashboard de Railway > Logs
4. **Test manual**: Sync una norma específica para aislar el problema

## Chunking Legal

### Estrategia

```
Norma
├── Título (grouper)
│   ├── Artículo 1 (chunk: article)
│   │   ├── Inciso 1 (sub-chunk si art > 2000 chars)
│   │   ├── Inciso 2
│   │   │   ├── Letra a)
│   │   │   └── Letra b)
│   │   └── Inciso 3
│   ├── Artículo 2
│   └── ...
├── Título II
│   └── ...
└── Disposiciones Transitorias
```

### Reglas
- **Chunk base**: Artículo completo
- **Sub-chunk**: Solo si artículo > 2000 caracteres
- **Separación**: Por incisos (párrafos), luego por letras (a), b), c))
- **Path**: `Tit_Preliminar/Art_1`, `Tit_I/Art_4/Inc_2/Let_b`
- **Reconstrucción**: El texto completo del artículo siempre está en el chunk padre
- **Full-text search**: PostgreSQL `tsvector` con configuración `spanish`

## Fuentes futuras (fase 2)

La interfaz `SourceConnectorInterface` permite agregar:
- **Diario Oficial**: Novedades legislativas
- **Contraloría**: Dictámenes
- **SII**: Circulares tributarias
- **MINVU/OGUC**: Normativa urbanística

Cada fuente implementa `fetchNorm()`, `parseNorm()`, y `searchNorms()`.
