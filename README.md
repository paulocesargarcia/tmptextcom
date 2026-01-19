# TmpText - Almacenamiento Temporal de Archivos y Texto

Un sistema simple y liviano para compartir temporalmente textos (logs/código) y archivos, desarrollado en PHP sin necesidad de base de datos.

## 🚀 Funcionalidades

- **Subida de texto o archivo**: Vía web o CLI (curl/bash).
- **URLs Únicas**: Generación de URL basada en UUID.
- **Vista Previa Inteligente**:
  - Syntax highlight para código y logs.
  - Vista previa para imágenes.
  - Descarga directa para otros tipos de archivo.
- **Expiración Automática**: Los archivos se eliminan después de 7 días.
- **Seguridad**:
  - Límite de velocidad por IP (10 subidas por minuto).
  - Protección contra XSS y CSRF.
  - Límite de tamaño de archivo (5MB).
  - Almacenamiento fuera de la raíz pública.
- **Sin Base de Datos**: Utiliza solo el sistema de archivos local.

## 🛠️ Requisitos

- PHP 7.4 o superior.
- Servidor Web (Apache, Nginx o el servidor embebido de PHP).

## 📥 Instalación (cPanel)

Este proyecto está diseñado para funcionar en entornos como cPanel donde los archivos del sistema se mantienen fuera de la carpeta pública por seguridad.

1. Subí la carpeta `app` al directorio raíz de tu cuenta (un nivel antes de `public_html`).
2. Copiá el contenido de la carpeta `public_html` (el archivo `index.php`) adentro de tu carpeta `public_html` del cPanel.
3. Asegurate de que el directorio `app/storage` y sus subdirectorios tengan permisos de escritura (generalmente 755 o 777 dependiendo del servidor).

## 💻 Cómo usar

### Servidor de Desarrollo (PHP)
Para ejecutar rápidamente desde la raíz del proyecto:
```bash
php -S localhost:8000 -t public_html public_html/index.php
```

### Vía Web
Acceder a `http://localhost:8000` en tu navegador para usar el formulario de subida.

### Vía CLI (Ejecución Remota)

Podés ejecutar el CLI directamente sin descargar nada, útil para servidores y automatizaciones rápidas:

```bash
# Enviar texto
bash <(curl -s "http://localhost:8000/cli.sh") -t "Hola Mundo"

# Enviar un archivo
bash <(curl -s "http://localhost:8000/cli.sh") -f mi-archivo.txt

# Enviar vía Pipe (stdin)
cat app.log | bash <(curl -s "http://localhost:8000/cli.sh")
```

### Vía CLI (Local)

Si preferís descargar el script:

```bash
curl -O http://localhost:8000/cli.sh
chmod +x cli.sh

./cli.sh -t "Texto local"
```

### Vía curl (Directo)

Si preferís no usar el script:

**Texto:** `curl -X POST --data "texto de prueba" http://localhost:8000/api/upload`

**Archivo:** `curl -F "file=@archivo.txt" http://localhost:8000/api/upload`

## 📂 Estructura del Proyecto

```
/app          - Lógica y almacenamiento (debe estar fuera de la raíz web)
  /src        - Clases PHP y plantillas
  /storage    - Archivos almacenados, metadados e logs
/public_html  - Raíz del servidor web (contiene solo index.php)
```

## 🛡️ Seguridad y Autor

- El sistema limita a **10 subidas por minuto por IP**.
- Autor: **Paulo Cesar Garcia**
- Repositorio: [https://github.com/paulocesargarcia/tmptextcom](https://github.com/paulocesargarcia/tmptextcom)
