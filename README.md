# QueBot - Tu Asistente Inteligente

Interface de chat estilo Tasklet conectada a Claude AI.

## 🚀 Instalación Rápida (cPanel)

### Paso 1: Subir archivos
1. Accede a tu cPanel
2. Abre el **Administrador de Archivos**
3. Navega a `public_html` (o la carpeta donde quieras instalarlo)
4. Sube el archivo `quebot-app.zip`
5. Haz clic derecho > **Extraer**
6. Elimina el archivo .zip después de extraer

### Paso 2: Configurar tu API Key
1. En el Administrador de Archivos, navega a `public_html/api/`
2. Haz clic derecho en `config.php` > **Editar**
3. Busca la línea:
   ```php
   define('ANTHROPIC_API_KEY', 'TU_API_KEY_AQUI');
   ```
4. Reemplaza `TU_API_KEY_AQUI` con tu API key de Claude
5. Guarda el archivo

### Paso 3: ¡Listo!
Visita tu dominio y empieza a usar QueBot.

---

## 🔑 Obtener tu API Key de Claude

1. Ve a [console.anthropic.com](https://console.anthropic.com)
2. Crea una cuenta o inicia sesión
3. Ve a **API Keys** en el menú
4. Haz clic en **Create Key**
5. Copia la key (solo se muestra una vez)

**Costo aproximado:** $3 USD por 1 millón de tokens de entrada + $15 por 1 millón de tokens de salida (Claude Sonnet).
Una conversación típica usa ~2,000-5,000 tokens.

---

## 📁 Estructura de Archivos

```
quebot-app/
├── index.html          # Página principal
├── .htaccess           # Config Apache
├── css/
│   └── styles.css      # Estilos
├── js/
│   ├── storage.js      # Persistencia local
│   ├── api.js          # Comunicación con backend
│   ├── ui.js           # Funciones de interfaz
│   └── app.js          # Lógica principal
├── api/
│   ├── config.php      # ⚠️ TU API KEY VA AQUÍ
│   ├── chat.php        # Endpoint proxy
│   └── .htaccess       # Protección de seguridad
└── assets/             # Imágenes/iconos
```

---

## ⚙️ Personalización

### Cambiar el modelo de Claude
En `api/config.php`:
```php
define('CLAUDE_MODEL', 'claude-sonnet-4-20250514');
```

Opciones:
- `claude-sonnet-4-20250514` - Equilibrio velocidad/calidad (recomendado)
- `claude-3-5-sonnet-20241022` - Anterior versión
- `claude-3-opus-20240229` - Máxima calidad (más costoso)

### Personalizar el asistente
Edita `SYSTEM_PROMPT` en `api/config.php` para cambiar la personalidad y comportamiento del bot.

### Cambiar colores/branding
Edita las variables CSS al inicio de `css/styles.css`:
```css
:root {
    --accent-primary: #2d5a3d;    /* Color principal */
    --accent-secondary: #3b82f6;  /* Color secundario */
    /* ... más variables ... */
}
```

---

## 🔒 Seguridad

- ✅ Tu API key está protegida en el servidor, nunca se expone al navegador
- ✅ El archivo `config.php` está bloqueado por `.htaccess`
- ✅ Rate limiting básico incluido (20 requests/minuto por IP)
- ✅ Headers de seguridad configurados

**Recomendaciones adicionales:**
- Usa HTTPS (SSL) en tu dominio
- Monitorea tu uso en console.anthropic.com
- Considera agregar autenticación si es para uso privado

---

## 🐛 Solución de Problemas

### "API no configurada"
- Verifica que editaste `api/config.php` con tu API key real
- Asegúrate de no haber dejado espacios extra

### Error 500
- Revisa que PHP tenga habilitada la extensión `curl`
- En cPanel: Select PHP Version > Extensions > curl ✓

### No carga estilos/scripts
- Verifica que los archivos se extrajeron correctamente
- Revisa que `.htaccess` está en su lugar

### Respuestas lentas
- Es normal, Claude puede tardar 5-15 segundos
- El streaming muestra el texto mientras se genera

---

## 📞 Soporte

¿Problemas? Revisa:
1. La consola del navegador (F12 > Console)
2. Los logs de error de PHP en cPanel
3. Tu saldo/límites en console.anthropic.com

---

## 📄 Licencia

Uso libre para proyectos personales y comerciales.
Creado con ❤️ usando Claude AI.
